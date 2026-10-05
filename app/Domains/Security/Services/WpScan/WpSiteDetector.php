<?php

namespace App\Domains\Security\Services\WpScan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Probe ringan untuk memastikan sebuah domain menjalankan WordPress (t_2e555b0b).
 *
 * Urutan probe (berhenti pada bukti pertama):
 *   1. GET https://<domain>/wp-login.php  -> halaman login WP (200 + marker)
 *   2. GET https://<domain>/              -> halaman apa pun yang memuat
 *      marker wp-content / wp-json
 *
 * Marker dicek case-insensitive pada 128 KB pertama badan jawaban untuk
 * membatasi beban memori pada halaman raksasa. Kegagalan jaringan/SSL/timeout
 * mengembalikan `null` (bukan false) supaya caller bisa membedakan "bukan
 * WordPress" dari "tidak bisa dipastikan" — yang terakhir tidak boleh
 * langsung diberi status non_wordpress.
 */
class WpSiteDetector
{
    private const MARKERS = ['wp-content/', 'wp-json', 'wp-includes/', 'wordpress'];

    /** @return bool|null true = WordPress, false = bukan, null = tidak bisa dipastikan */
    public function detect(string $domain): ?bool
    {
        $timeout = max(3, (int) config('crm.wpscan.probe_timeout', 10));
        $verify = (bool) config('crm.wpscan.verify_ssl', true);

        foreach (['https://'.$domain.'/wp-login.php', 'https://'.$domain.'/'] as $url) {
            $result = $this->probe($url, $timeout, $verify);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @return bool|null true bila marker ditemukan, null bila request gagal/tidak meyakinkan */
    private function probe(string $url, int $timeout, bool $verify): ?bool
    {
        try {
            $response = Http::withOptions(['verify' => $verify])
                ->timeout($timeout)
                ->withUserAgent('Mozilla/5.0 (compatible; CRM-Security-Probe/1.0)')
                ->get($url);
        } catch (Throwable $e) {
            Log::info('WPScan probe gagal (jaringan/SSL).', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            // 404/403: endpoint ini tidak membuktikan apa pun -> coba URL lain.
            return null;
        }

        $body = mb_strtolower(substr($response->body(), 0, 131072));

        foreach (self::MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        // Halaman sukses tanpa marker WP: hanya konklusif untuk homepage
        // (wp-login.php tanpa marker bisa jadi halaman redirect/login lain).
        return str_ends_with(rtrim($url, '/'), '/wp-login.php') ? null : false;
    }
}