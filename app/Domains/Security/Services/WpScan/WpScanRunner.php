<?php

namespace App\Domains\Security\Services\WpScan;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Eksekusi binary WPScan CLI untuk satu URL (t_2e555b0b).
 *
 * Keamanan:
 *   - argumen dibangun sebagai ARRAY (Laravel Process meng-escape tiap elemen);
 *     URL dari database tidak pernah masuk ke shell string, jadi tidak ada
 *     jalur injeksi perintah.
 *   - hanya flag read-only: tidak ada `--enumerate u` (enumerasi user),
 *     tidak ada brute force. Scan murni pasif-deteksi.
 *   - api token hanya ditambahkan bila dikonfigurasi; tidak pernah ditulis
 *     ke log.
 *
 * Keluaran: array hasil ternormalisasi:
 *   ['ok' => bool, 'exit_code' => int, 'json' => ?array, 'error' => ?string]
 * `ok` true hanya bila JSON valid ter-parse — exit code non-zero tetap bisa
 * "ok" (WPScan mengembalikan 1 saat menemukan kerentanan pada beberapa versi).
 */
class WpScanRunner
{
    /** @return array{ok: bool, exit_code: int, json: ?array<string, mixed>, error: ?string} */
    public function scan(string $url): array
    {
        $binary = (string) config('crm.wpscan.binary', 'wpscan');
        $timeout = max(30, (int) config('crm.wpscan.timeout', 300));

        if (! $this->binaryExists($binary)) {
            return [
                'ok' => false,
                'exit_code' => 127,
                'json' => null,
                'error' => "Binary wpscan tidak ditemukan di {$binary}. Jalankan scripts/install-wpscan.sh di server CRM.",
            ];
        }

        $args = [$binary, '--url', $url, '--format', 'json', '--no-banner', '--random-user-agent'];

        $apiToken = (string) config('crm.wpscan.api_token', '');

        if ($apiToken !== '') {
            $args[] = '--api-token';
            $args[] = $apiToken;
        }

        try {
            /** @var ProcessResult $result */
            $result = Process::timeout($timeout)->run($args);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'json' => null,
                'error' => 'Eksekusi wpscan gagal: '.$e->getMessage(),
            ];
        }

        // Output JSON WPScan bisa di stdout; beberapa versi menulis peringatan
        // sebelum JSON — ambil dari '{' pertama.
        $output = $result->output() !== '' ? $result->output() : $result->errorOutput();
        $start = strpos($output, '{');

        if ($start === false) {
            return [
                'ok' => false,
                'exit_code' => $result->exitCode(),
                'json' => null,
                'error' => 'Output wpscan bukan JSON (exit '.$result->exitCode().'): '.mb_substr(trim($output), 0, 300),
            ];
        }

        try {
            $json = json_decode(substr($output, $start), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => $result->exitCode(),
                'json' => null,
                'error' => 'JSON wpscan rusak: '.$e->getMessage(),
            ];
        }

        if (! is_array($json)) {
            return [
                'ok' => false,
                'exit_code' => $result->exitCode(),
                'json' => null,
                'error' => 'JSON wpscan bukan objek.',
            ];
        }

        if (($json['scan_aborted'] ?? false) === true) {
            return [
                'ok' => false,
                'exit_code' => $result->exitCode(),
                'json' => $json,
                'error' => 'Scan dibatalkan oleh wpscan (scan_aborted=true).',
            ];
        }

        Log::info('WPScan selesai.', ['url' => $url, 'exit_code' => $result->exitCode()]);

        return ['ok' => true, 'exit_code' => $result->exitCode(), 'json' => $json, 'error' => null];
    }

    private function binaryExists(string $binary): bool
    {
        // Path absolut dicek langsung; nama telanjang (PATH) dianggap tersedia —
        // Process::run akan membuktikan dan error-nya tertangkap di atas.
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        return true;
    }
}