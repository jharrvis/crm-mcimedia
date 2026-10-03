<?php

namespace App\Domains\Security\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk mengambil metrik dari Netdata API via tailnet.
 *
 * Tidak mengekspos Netdata ke publik - semua request dari server-side Laravel.
 * Data di-cache untuk mengurangi beban ke server Netdata.
 */
class NetdataService
{
    /** @var array<string, array{name: string, host: string, port: int}> */
    private const SERVERS = [
        'sg2' => [
            'name' => 'sg2',
            'host' => '100.119.156.82',
            'port' => 19999,
        ],
        'yiari' => [
            'name' => 'YIARI',
            'host' => '100.114.35.33',
            'port' => 19999,
        ],
        'pa-salatiga' => [
            'name' => 'PA Salatiga',
            'host' => '100.97.142.93',
            'port' => 19999,
        ],
    ];

    /** TTL cache dalam detik (5 menit - sesuai interval push-agent) */
    private const CACHE_TTL = 300;

    /** Timeout request ke Netdata (detik) */
    private const REQUEST_TIMEOUT = 10;

    /** Chart Netdata yang dipakai untuk setiap metrik */
    private const CHARTS = [
        'cpu' => 'system.cpu',
        'ram' => 'system.ram',
        'disk' => 'disk_space./',
        'network' => 'net.eth0',
    ];

    /**
     * Ambil data time-series untuk semua server dan metrik.
     *
     * @param int $after Detik ke belakang dari sekarang (default: 3600 = 1 jam)
     * @param int $points Jumlah titik data yang diinginkan (default: 60)
     * @return array<string, array<string, array<array{time: int, value: float}>>>
     */
    public function fetchAllMetrics(int $after = 3600, int $points = 60): array
    {
        $results = [];

        foreach (self::SERVERS as $key => $server) {
            $results[$key] = [
                'server_name' => $server['name'],
                'metrics' => [],
            ];

            foreach (self::CHARTS as $metricKey => $chart) {
                $cacheKey = "netdata.{$key}.{$chart}.{$after}.{$points}";
                $results[$key]['metrics'][$metricKey] = Cache::remember(
                    $cacheKey,
                    self::CACHE_TTL,
                    fn () => $this->fetchChart($server['host'], $server['port'], $chart, $after, $points)
                );
            }
        }

        return $results;
    }

    /**
     * Ambil data untuk satu chart dari satu server.
     *
     * @return array<array{time: int, value: float}>
     */
    private function fetchChart(string $host, int $port, string $chart, int $after, int $points): array
    {
        $url = "http://{$host}:{$port}/api/v1/data";
        $params = [
            'chart' => $chart,
            'after' => -$after,
            'points' => $points,
            'group' => 'average',
            'options' => 'seconds',
        ];

        try {
            /** @var PendingRequest $client */
            $client = Http::timeout(self::REQUEST_TIMEOUT)
                ->retry(2, 500)
                ->acceptJson();

            $response = $client->get($url, $params);

            if (!$response->successful()) {
                Log::warning('Netdata API gagal', [
                    'host' => $host,
                    'port' => $port,
                    'chart' => $chart,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();

            // Netdata mengembalikan: { labels: [time, dim1, dim2, ...], data: [[ts, v1, v2, ...], ...] }
            // labels[0] = 'time', labels[1:] = nama dimensi
            // data = array of rows, each row: [timestamp, value_dim1, value_dim2, ...]
            // Kita ambil dimensi pertama (index 1 di row, yaitu value untuk labels[1])
            if (!isset($data['labels'], $data['data'][0]) || !is_array($data['data'][0])) {
                return [];
            }

            $result = [];
            foreach ($data['data'] as $row) {
                if (!is_array($row) || count($row) < 2) {
                    continue;
                }
                $time = $row[0];
                $value = $row[1]; // dimensi pertama setelah timestamp
                if (is_numeric($time) && is_numeric($value)) {
                    $result[] = [
                        'time' => (int) $time,
                        'value' => (float) $value,
                    ];
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('Netdata request exception', [
                'host' => $host,
                'port' => $port,
                'chart' => $chart,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Dapatkan daftar server yang dikonfigurasi.
     *
     * @return array<string, array{name: string, host: string, port: int}>
     */
    public static function getServers(): array
    {
        return self::SERVERS;
    }

    /**
     * Cek apakah server Netdata tersedia (health check).
     */
    public function checkServer(string $key): bool
    {
        $server = self::SERVERS[$key] ?? null;
        if (!$server) {
            return false;
        }

        try {
            $response = Http::timeout(5)
                ->get("http://{$server['host']}:{$server['port']}/api/v1/info");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}