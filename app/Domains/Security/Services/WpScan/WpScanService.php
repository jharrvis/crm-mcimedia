<?php

namespace App\Domains\Security\Services\WpScan;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Enums\HestiaAccountStatus;
use App\Domains\Hestia\Enums\HestiaMappingStatus;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Enums\WpScanSiteStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\WpScanSite;
use App\Domains\Security\Services\IncidentFonnteNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Orkestrasi WPScan otomatis untuk situs WordPress klien (t_2e555b0b).
 *
 * Alur satu run (dipanggil command `crm:wpscan`, jadwal harian 05:00):
 *
 *   1. discoverSites  — akun Hestia aktif & terpetakan (mapping resolved,
 *                       client_id ada, klien aktif) yang domainnya belum ada
 *                       di `wp_scan_sites` -> baris baru status pending_detection.
 *                       Domain unik: dua akun menunjuk domain sama berbagi satu
 *                       baris (first-mapped wins, yang lain dilewati).
 *   2. detect         — situs pending_detection di-probe ringan (WpSiteDetector).
 *                       WordPress -> active; bukan -> non_wordpress; tidak bisa
 *                       dipastikan -> tetap pending (dicoba lagi jadwal berikut).
 *   3. scan           — situs active/error dipindai WPScan CLI (WpScanRunner).
 *                       Temuan (WpScanResultParser) menjadi SecurityIncident
 *                       source=wpscan dengan external_id idempoten
 *                       `wpscan:{site_id}:{fingerprint}`; temuan lama yang
 *                       tidak muncul lagi ditutup otomatis.
 *
 * Klien tujuan insiden: klien hasil pemetaan akun Hestia. Tanpa klien (seharusnya
 * tidak terjadi karena discoverSites sudah memfilter) -> situs di-skip + log.
 */
class WpScanService
{
    public function __construct(
        private readonly WpSiteDetector $detector,
        private readonly WpScanRunner $runner,
        private readonly WpScanResultParser $parser,
        private readonly IncidentFonnteNotifier $fonnteNotifier,
    ) {}

    /**
     * Jalankan seluruh pipeline.
     *
     * @return array{discovered: int, detected_wp: int, detected_non_wp: int, scanned: int, findings_created: int, findings_resolved: int, errors: int}
     */
    public function run(): array
    {
        $stats = [
            'discovered' => 0,
            'detected_wp' => 0,
            'detected_non_wp' => 0,
            'scanned' => 0,
            'findings_created' => 0,
            'findings_resolved' => 0,
            'errors' => 0,
        ];

        $stats['discovered'] = $this->discoverSites();

        WpScanSite::query()
            ->where('status', WpScanSiteStatus::PendingDetection)
            ->orderBy('id')
            ->chunkById(100, function ($sites) use (&$stats): void {
                foreach ($sites as $site) {
                    $this->detectSite($site, $stats);
                }
            });

        WpScanSite::query()
            ->scannable()
            ->orderBy('id')
            ->chunkById(50, function ($sites) use (&$stats): void {
                foreach ($sites as $site) {
                    $this->scanSite($site, $stats);
                }
            });

        return $stats;
    }

    // ------------------------------------------------------------------
    // 1. Penemuan target dari akun Hestia
    // ------------------------------------------------------------------

    /**
     * Buat baris `wpscan_sites` untuk setiap akun Hestia aktif yang sudah
     * terpetakan ke klien aktif dan belum terdaftar.
     */
    private function discoverSites(): int
    {
        $discovered = 0;

        HestiaAccount::query()
            ->where('status', HestiaAccountStatus::Active)
            ->whereIn('mapping_status', [HestiaMappingStatus::Auto, HestiaMappingStatus::Mapped])
            ->whereNotNull('client_id')
            ->where('suspended', false)
            ->where('user_suspended', false)
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$discovered): void {
                foreach ($accounts as $account) {
                    if ($this->registerSite($account)) {
                        $discovered++;
                    }
                }
            });

        return $discovered;
    }

    private function registerSite(HestiaAccount $account): bool
    {
        $domain = mb_strtolower(trim((string) $account->domain));

        if ($domain === '' || ! str_contains($domain, '.')) {
            return false;
        }

        $client = Client::find($account->client_id);

        // Klien tidak aktif / sudah dihapus: tidak ada tujuan insiden.
        if (! $client || ! $client->is_active) {
            return false;
        }

        if (WpScanSite::query()->where('domain', $domain)->exists()) {
            return false;
        }

        try {
            WpScanSite::create([
                'client_id' => $client->id,
                'hestia_account_id' => $account->id,
                'domain' => $domain,
                'url' => 'https://'.$domain,
                'status' => WpScanSiteStatus::PendingDetection,
            ]);

            Log::info('WPScan: target baru ditemukan dari akun Hestia.', [
                'domain' => $domain,
                'client_id' => $client->id,
                'hestia_account_id' => $account->id,
            ]);

            return true;
        } catch (QueryException $e) {
            // Balapan konkuren pada unique(domain) — aman diabaikan.
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                return false;
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // 2. Deteksi WordPress
    // ------------------------------------------------------------------

    /** @param array<string, int> $stats */
    private function detectSite(WpScanSite $site, array &$stats): void
    {
        $result = $this->detector->detect($site->domain);

        if ($result === true) {
            $site->update(['status' => WpScanSiteStatus::Active]);
            $stats['detected_wp']++;

            Log::info('WPScan: situs terdeteksi WordPress.', ['domain' => $site->domain]);
        } elseif ($result === false) {
            $site->update(['status' => WpScanSiteStatus::NonWordpress]);
            $stats['detected_non_wp']++;

            Log::info('WPScan: situs bukan WordPress — dilewati.', ['domain' => $site->domain]);
        }
        // null = tidak bisa dipastikan: tetap pending_detection, dicoba ulang
        // pada jadwal berikutnya.
    }

    // ------------------------------------------------------------------
    // 3. Scan & pembuatan insiden
    // ------------------------------------------------------------------

    /** @param array<string, int> $stats */
    private function scanSite(WpScanSite $site, array &$stats): void
    {
        $result = $this->runner->scan($site->url);

        if (! $result['ok']) {
            $site->update([
                'status' => WpScanSiteStatus::Error,
                'last_scan_at' => now(),
                'last_error' => mb_substr((string) $result['error'], 0, 1000),
            ]);
            $stats['errors']++;

            Log::warning('WPScan: scan gagal.', ['domain' => $site->domain, 'error' => $result['error']]);

            return;
        }

        $parsed = $this->parser->parse($result['json'] ?? []);
        $stats['scanned']++;

        $seenFingerprints = [];

        foreach ($parsed['findings'] as $finding) {
            $seenFingerprints[] = $finding['fingerprint'];

            if ($this->createFindingIncident($site, $finding)) {
                $stats['findings_created']++;
            }
        }

        // Temuan lama yang tidak muncul lagi pada scan ini dianggap sudah
        // ditangani (update/penghapusan komponen) — tutup otomatis.
        $prefix = $site->incidentExternalIdPrefix();
        $resolvedCount = 0;

        SecurityIncident::query()
            ->where('external_id', 'like', $prefix.'%')
            ->where('status', IncidentStatus::Open)
            ->get()
            ->each(function (SecurityIncident $incident) use ($seenFingerprints, &$resolvedCount): void {
                $fingerprint = substr((string) $incident->external_id, strrpos((string) $incident->external_id, ':') + 1);

                if (! in_array($fingerprint, $seenFingerprints, true)) {
                    $incident->transitionTo(IncidentStatus::Resolved);
                    $resolvedCount++;
                }
            });

        $stats['findings_resolved'] += $resolvedCount;

        $site->update([
            'status' => WpScanSiteStatus::Active,
            'wp_version' => mb_substr((string) $parsed['version'], 0, 32) ?: null,
            'last_scan_at' => now(),
            'last_finding_count' => count($parsed['findings']),
            'last_error' => null,
        ]);

        if ($resolvedCount > 0 || count($parsed['findings']) > 0) {
            Log::info('WPScan: hasil scan tercatat.', [
                'domain' => $site->domain,
                'findings' => count($parsed['findings']),
                'resolved' => $resolvedCount,
                'wp_version' => $parsed['version'],
            ]);
        }
    }

    /**
     * Buat insiden untuk satu temuan. Idempoten lewat external_id unik
     * (klien + fingerprint). Mengembalikan true hanya saat insiden BARU dibuat.
     */
    private function createFindingIncident(WpScanSite $site, array $finding): bool
    {
        $externalId = $site->incidentExternalIdPrefix().$finding['fingerprint'];

        // Jalur cepat + tangkap unique violation (pola SecurityEventIngest /
        // DiskQuotaAlertService).
        if (SecurityIncident::query()
            ->where('client_id', $site->client_id)
            ->where('external_id', $externalId)
            ->exists()) {
            return false;
        }

        try {
            $incident = SecurityIncident::create([
                'client_id' => $site->client_id,
                'external_id' => $externalId,
                'occurred_at' => now(),
                'severity' => $finding['severity'],
                'source' => IncidentSource::Wpscan,
                'title' => sprintf('[%s] %s — %s', $site->domain, $this->componentLabel($finding), $finding['title']),
                'description' => $this->buildDescription($site, $finding),
                'status' => IncidentStatus::Open,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                return false;
            }

            throw $e;
        }

        // Notifikasi WA hanya untuk temuan serius supaya tidak spam.
        if ($finding['severity']->weight() >= IncidentSeverity::High->weight()) {
            $this->fonnteNotifier->notifyWpScanFinding($incident, $site, $finding);
        }

        return true;
    }

    /** @param array<string, mixed> $finding */
    private function componentLabel(array $finding): string
    {
        return match ($finding['type']) {
            'core' => 'WordPress '.($finding['component_version'] ?? ''),
            'plugin' => 'Plugin '.($finding['slug'] ?? ''),
            'theme' => 'Tema '.($finding['slug'] ?? ''),
            default => 'Komponen',
        };
    }

    /** @param array<string, mixed> $finding */
    private function buildDescription(WpScanSite $site, array $finding): string
    {
        $lines = [
            sprintf('Kerentanan %s pada %s (%s).', $finding['type'], $site->domain, $finding['title']),
        ];

        if (filled($finding['component_version'])) {
            $lines[] = sprintf('Versi terpasang: %s.', $finding['component_version']);
        }

        if (filled($site->wp_version)) {
            $lines[] = sprintf('Versi WordPress saat scan: %s.', $site->wp_version);
        }

        foreach ($finding['references'] as $reference) {
            $lines[] = 'Referensi: '.$reference;
        }

        $lines[] = 'Insiden ditutup otomatis bila temuan tidak muncul lagi pada scan berikutnya.';

        return implode("\n", $lines);
    }
}