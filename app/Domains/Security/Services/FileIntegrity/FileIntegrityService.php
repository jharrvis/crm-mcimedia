<?php

namespace App\Domains\Security\Services\FileIntegrity;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Enums\WpScanSiteStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\WpScanSite;
use Illuminate\Support\Facades\Log;

/**
 * File Integrity via wp-cli `wp core verify-checksums` (t_a94a2d2c).
 *
 * Memeriksa apakah file inti WordPress telah dimodifikasi (indikasi kompromi).
 * Berjalan bertahap: 10 situs per run, situs yang sudah dicek dilewati sampai
 * semua situs tercakup, lalu siklus berulang.
 *
 * Hanya read-only: tidak mengubah file apapun di situs target.
 */
class FileIntegrityService
{
    private const BATCH_SIZE = 10;

    /** @return array{checked: int, clean: int, modified: int, errors: int} */
    public function run(): array
    {
        $stats = ['checked' => 0, 'clean' => 0, 'modified' => 0, 'errors' => 0];

        // Ambil 10 situs yang paling lama tidak dicek (atau belum pernah)
        $sites = WpScanSite::query()
            ->where('status', WpScanSiteStatus::Active)
            ->orderBy('last_integrity_check_at')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($sites as $site) {
            $stats['checked']++;
            try {
                $result = $this->checkSite($site);
                $site->update(['last_integrity_check_at' => now()]);

                if ($result['modified']) {
                    $stats['modified']++;
                    $this->createIncident($site, $result);
                } else {
                    $stats['clean']++;
                    $this->resolveIncident($site);
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('FileIntegrity: gagal cek situs', [
                    'domain' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Jalankan wp core verify-checksums di path situs.
     * @return array{modified: bool, files: array}
     */
    private function checkSite(WpScanSite $site): array
    {
        $path = $this->resolvePath($site);

        if (! $path || ! is_dir($path)) {
            throw new \RuntimeException("Path WordPress tidak ditemukan untuk {$site->domain}");
        }

        // Jalankan wp-cli sebagai user yang memiliki file (via sudo -u)
        $owner = posix_getpwuid(fileowner($path))['name'] ?? 'www-data';
        $cmd = sprintf(
            'sudo -n -u %s wp core verify-checksums --path=%s --allow-root 2>&1',
            escapeshellarg($owner),
            escapeshellarg($path)
        );

        $output = shell_exec($cmd) ?? '';

        // "Success: WordPress installation verifies against checksums." = bersih
        if (str_contains($output, 'verifies against checksums')) {
            return ['modified' => false, 'files' => []];
        }

        // Parse file yang dimodifikasi
        $files = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(File|Warning):\s*(.+)/i', trim($line), $m)) {
                $files[] = trim($m[2]);
            }
        }

        return ['modified' => ! empty($files), 'files' => array_slice($files, 0, 20)];
    }

    /** Cari path instalasi WordPress dari domain. */
    private function resolvePath(WpScanSite $site): ?string
    {
        // Cari via Hestia account
        if ($site->hestia_account_id) {
            $account = $site->hestiaAccount;
            if ($account && $account->hestia_user) {
                $path = "/home/{$account->hestia_user}/web/{$site->domain}/public_html";
                if (is_dir($path.'/wp-admin')) {
                    return $path;
                }
                // Coba public_shtml
                $path2 = "/home/{$account->hestia_user}/web/{$site->domain}/public_shtml";
                if (is_dir($path2.'/wp-admin')) {
                    return $path2;
                }
            }
        }

        return null;
    }

    private function createIncident(WpScanSite $site, array $result): void
    {
        $externalId = "fileintegrity:{$site->id}:".md5(implode(',', $result['files']));

        $existing = SecurityIncident::where('external_id', $externalId)
            ->whereIn('status', [IncidentStatus::Open, IncidentStatus::Acknowledged])
            ->first();

        if ($existing) {
            return; // Sudah ada insiden terbuka untuk temuan ini
        }

        $fileList = implode("\n", array_map(fn ($f) => "- {$f}", $result['files']));

        $incident = SecurityIncident::create([
            'client_id' => $site->client_id,
            'title' => "File WordPress dimodifikasi: {$site->domain}",
            'description' => "File integrity check menemukan ".count($result['files'])." file inti WordPress yang dimodifikasi (bisa jadi indikasi kompromi):\n\n{$fileList}",
            'severity' => IncidentSeverity::High,
            'status' => IncidentStatus::Open,
            'source' => IncidentSource::FileIntegrity,
            'external_id' => $externalId,
            'occurred_at' => now(),
        ]);

        // Notifikasi via Fonnte dikirim oleh scheduler eskalasi berdasarkan severity.
        // Incident High akan masuk antrean notifikasi otomatis.
    }

    private function resolveIncident(WpScanSite $site): void
    {
        SecurityIncident::where('source', IncidentSource::FileIntegrity)
            ->where('client_id', $site->client_id)
            ->where('title', 'like', "%{$site->domain}%")
            ->whereIn('status', [IncidentStatus::Open, IncidentStatus::Acknowledged])
            ->update([
                'status' => IncidentStatus::Resolved,
                'resolved_at' => now(),
            ]);
    }
}
