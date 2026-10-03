<?php

namespace App\Domains\Security\Services;

use App\Domains\Hestia\Models\HestiaServer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk mengambil metrik dari Netdata API via tailnet.
 *
 * Tidak mengekspos Netdata ke publik - semua request dari server-side Laravel.
 * Data di-cache untuk mengurangi beban ke server Netdata.
 * Server dikonfigurasi melalui tabel `hestia_servers` (kolom netdata_host, netdata_port).
 */
class NetdataService
{
    /** TTL cache dalam detik (5 menit - sesuai interval push-agent) */
    private const CACHE_TTL = 300;

    /** Timeout request ke Netdata (detik) */
    private const REQUEST_TIMEOUT = 10;

    /** Default port Netdata */
    private const DEFAULT_NETDATA_PORT = 19999;

    /** Chart Netdata yang dipakai untuk setiap metrik */
    private const CHARTS = [
        'cpu' => 'system.cpu',
        'ram' => 'system.ram',
        'disk' => 'disk_space./',
        'network' => 'net.eth0',
    ];

    /**
     * Ambil daftar server Netdata dari database.
     *
     * Hanya server yang punya netdata_host terisi dan is_active = true.
     *
     * @return array<string, array{name: string, host: string, port: int}>
     */
    public static function getServers(): array
    {
        $servers = HestiaServer::query()
            ->whereNotNull('netdata_host')
            ->where('netdata_host', '!=', '')
            ->where('is_active', true)
            ->get(['code', 'name', 'netdata_host', 'netdata_port'])
            ->mapWithKeys(function ($server) {
                $port = $server->netdata_port ?? self::DEFAULT_NETDATA_PORT;

                return [$server->code => [
                    'name' => $server->name,
                    'host' => $server->netdata_host,
                    'port' => (int) $port,
                ]];
            })
            ->toArray();

        return $servers;
    }

    /**
     * Cek apakah server Netdata tersedia (health check).
     */
    public function checkServer(string $key): bool
    {
        $servers = self::getServers();
        $server = $servers[$key] ?? null;

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

    /**
     * Ambil data time-series untuk semua server dan metrik.
     *
     * @param int $after Detik ke belakang dari sekarang (default: 3600 = 1 jam)
     * @param int $points Jumlah titik data yang diinginkan (default: 60)
     * @return array<string, array<string, array<array{time: int, value: float}>>>
     */
    public function fetchAllMetrics(int $after = 3600, int $points = 60): array
    {
        $servers = self::getServers();
        $results = [];

        foreach ($servers as $key => $server) {
            $results[$key] = [
                'server_name' => $server['name'],
                'metrics' => [],
            ];

            foreach (self::CHARTS as $metricKey => $chart) {
                $cacheKey = "netdata.{$key}.{$chart}.{$after}.{$points}";
                $results[$key]['metrics'][$metricKey] = Cache::remember(
                    $cacheKey,
                    self::CACHE_TTL,
                    fn () => $this->fetchChart($server['host'], $server['port'], $metricKey, $chart, $after, $points)
                );
            }
        }

        return $results;
    }

    /**
     * Ambil data untuk satu chart dari satu server.
     *
     * Nilai dihitung sesuai jenis metrik (bukan sekadar dimensi pertama):
     * - cpu: total utilisasi = jumlah semua dimensi (% waktu CPU)
     * - ram/disk: used / total * 100
     * - network: (received + |sent|) KB/s dikonversi ke MB/s
     *
     * @return array<array{time: int, value: float}>
     */
    private function fetchChart(string $host, int $port, string $metricKey, string $chart, int $after, int $points): array
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
            if (!isset($data['labels'], $data['data'][0]) || !is_array($data['data'][0])) {
                return [];
            }
            $labels = $data['labels'];

            $result = [];
            foreach ($data['data'] as $row) {
                if (!is_array($row) || count($row) < 2) {
                    continue;
                }
                $time = $row[0];
                $value = $this->extractMetricValue($metricKey, $labels, $row);
                if (is_numeric($time) && is_numeric($value)) {
                    $result[] = [
                        'time' => (int) $time,
                        'value' => round((float) $value, 2),
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
     * Hitung nilai metrik dari satu baris data Netdata.
     *
     * @param array<int, string> $labels
     * @param array<int, mixed> $row
     */
    private function extractMetricValue(string $metricKey, array $labels, array $row): ?float
    {
        // Petakan label dimensi => nilai (lewati index 0 = time)
        $dims = [];
        foreach ($labels as $i => $label) {
            if ($i === 0) {
                continue;
            }
            if (isset($row[$i]) && is_numeric($row[$i])) {
                $dims[strtolower((string) $label)] = (float) $row[$i];
            }
        }
        if (empty($dims)) {
            return null;
        }

        switch ($metricKey) {
            case 'cpu':
                // system.cpu: tiap dimensi adalah % waktu CPU -> utilisasi = jumlah semua dimensi
                return array_sum($dims);

            case 'ram':
            case 'disk':
                // used / total * 100 (total = jumlah semua dimensi)
                if (!isset($dims['used'])) {
                    return null;
                }
                $total = array_sum($dims);
                return $total > 0 ? $dims['used'] / $total * 100 : null;

            case 'network':
                // (received + |sent|) dari KB/s ke MB/s (Netdata mengirim sent sebagai negatif)
                $rx = $dims['received'] ?? 0;
                $tx = $dims['sent'] ?? 0;
                return ($rx + abs($tx)) / 1024;

            default:
                $first = reset($dims);
                return $first === false ? null : $first;
        }
    }
}