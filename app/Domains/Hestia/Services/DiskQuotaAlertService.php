<?php

namespace App\Domains\Hestia\Services;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Enums\DiskAlertLevel;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Services\IncidentFonnteNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Alert kuota disk website (t_afef420a).
 *
 * Membandingkan pemakaian disk setiap akun Hestia dengan kuota paketnya
 * (`disk_used` / `disk_quota`, MB) dan menaikkan alert saat ambang
 * terlampaui:
 *
 *   - >= `crm.disk_quota.warning_percent`  (default 80%) -> warning, insiden severity medium
 *   - >= `crm.disk_quota.critical_percent` (default 90%) -> critical, insiden severity high
 *
 * Idempotensi: level alert terakhir tersimpan di `hestia_accounts.disk_alert_level`,
 * sehingga insiden + notifikasi WA hanya dibuat saat level BERUBAH. Saat level
 * turun (eskalasi terlewati / pemakaian mengecil) insiden alert lama ditutup
 * otomatis (`resolved`), termasuk saat kembali normal.
 *
 * Idempotensi lapis kedua: `security_incidents.external_id` unik per klien
 * (`disk-quota:{account_id}:{level}`), jadi dua run yang balapan tidak akan
 * membuat insiden ganda.
 *
 * Akun tanpa klien (belum dipetakan) jatuh ke klien fallback
 * `crm.disk_quota.default_client_id` bila dikonfigurasi; bila tidak ada klien
 * sama sekali, akun dilewati dengan log (tidak ada tujuan insiden).
 */
class DiskQuotaAlertService
{
    public function __construct(
        private readonly IncidentFonnteNotifier $fonnteNotifier,
    ) {}

    /**
     * Pindai seluruh akun Hestia dan proses perubahan level alert.
     *
     * @return array{checked: int, created: int, recovered: int, skipped: int}
     */
    public function run(): array
    {
        $stats = ['checked' => 0, 'created' => 0, 'recovered' => 0, 'skipped' => 0];

        HestiaAccount::query()
            ->with('server')
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$stats): void {
                foreach ($accounts as $account) {
                    $stats['checked']++;

                    $outcome = $this->processAccount($account);

                    if ($outcome === 'created') {
                        $stats['created']++;
                    } elseif ($outcome === 'recovered') {
                        $stats['recovered']++;
                    } elseif ($outcome === 'skipped') {
                        $stats['skipped']++;
                    }
                }
            });

        return $stats;
    }

    /**
     * Proses satu akun: bandingkan level tersimpan vs level hasil perhitungan.
     *
     * @return string created|recovered|skipped|unchanged
     */
    private function processAccount(HestiaAccount $account): string
    {
        $target = $account->currentAlertLevel();

        // Kuota tanpa batas (0), belum dilaporkan (null), atau `disk_used`
        // belum ada: tidak ada yang bisa di-alert-kan.
        if ($target === null) {
            return 'skipped';
        }

        $previous = $account->alertLevel();

        if ($target === $previous) {
            return 'unchanged';
        }

        $clientId = $this->resolveClientId($account);

        if ($clientId === null) {
            Log::warning('Alert kuota disk dilewati: akun belum terpetakan ke klien dan tidak ada klien fallback.', [
                'hestia_account_id' => $account->id,
                'domain' => $account->domain,
                'target_level' => $target->value,
            ]);

            return 'skipped';
        }

        // Tutup insiden alert lama yang masih open — baik saat eskalasi
        // (level naik, digantikan insiden baru) maupun saat pemakaian turun
        // kembali normal (alert selesai).
        $this->resolveOpenAlertIncidents($account);

        $account->forceFill([
            'disk_alert_level' => $target,
            'disk_alert_at' => now(),
        ])->save();

        if ($target === DiskAlertLevel::None) {
            Log::info('Alert kuota disk kembali normal.', [
                'hestia_account_id' => $account->id,
                'domain' => $account->domain,
            ]);

            return 'recovered';
        }

        $incident = $this->createIncident($account, $target, $clientId);

        if ($incident !== null) {
            $this->fonnteNotifier->notifyDiskQuotaAlert($incident, $account, $target);

            Log::warning('Alert kuota disk dibuat.', [
                'incident_id' => $incident->id,
                'hestia_account_id' => $account->id,
                'domain' => $account->domain,
                'level' => $target->value,
                'percent' => $account->diskUsagePercent(),
            ]);
        }

        return 'created';
    }

    /**
     * Klien tujuan insiden: klien hasil pemetaan, atau fallback config.
     */
    private function resolveClientId(HestiaAccount $account): ?int
    {
        if ($account->client_id !== null && Client::find($account->client_id)?->is_active) {
            return $account->client_id;
        }

        $fallback = config('crm.disk_quota.default_client_id');

        if ($fallback && Client::find($fallback)?->is_active) {
            return (int) $fallback;
        }

        return null;
    }

    /**
     * Tutup semua insiden alert kuota milik akun ini yang masih open.
     */
    private function resolveOpenAlertIncidents(HestiaAccount $account): void
    {
        SecurityIncident::query()
            ->where('external_id', 'like', $this->externalIdPrefix($account).'%')
            ->where('status', IncidentStatus::Open)
            ->get()
            ->each(fn (SecurityIncident $incident) => $incident->transitionTo(IncidentStatus::Resolved));
    }

    /**
     * Buat insiden keamanan untuk level alert yang baru, atau null bila sudah
     * ada (duplikat — run balapan atau data historis).
     */
    private function createIncident(HestiaAccount $account, DiskAlertLevel $level, int $clientId): ?SecurityIncident
    {
        $percent = $account->diskUsagePercent() ?? 0;

        $payload = [
            'client_id' => $clientId,
            'external_id' => $this->externalId($account, $level),
            'occurred_at' => now(),
            'severity' => $level === DiskAlertLevel::Critical
                ? IncidentSeverity::High
                : IncidentSeverity::Medium,
            'source' => IncidentSource::Monitor,
            'title' => sprintf('[%s] Kuota disk %d%% — %s', $account->domain, $percent, $level->label()),
            'description' => $this->buildDescription($account, $level, $percent),
            'status' => IncidentStatus::Open,
        ];

        // Cek dulu (jalur cepat) + tangkap unique violation (jalur balapan),
        // pola sama dengan SecurityEventIngest.
        if (SecurityIncident::query()
            ->where('client_id', $clientId)
            ->where('external_id', $payload['external_id'])
            ->exists()) {
            return null;
        }

        try {
            return SecurityIncident::create($payload);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                return null;
            }

            throw $e;
        }
    }

    private function buildDescription(HestiaAccount $account, DiskAlertLevel $level, int $percent): string
    {
        $server = $account->server?->name ?? $account->server?->host ?? 'server tidak diketahui';

        return implode("\n", [
            sprintf(
                'Pemakaian disk %s MB dari kuota %s MB (%d%%) — level %s.',
                number_format((int) $account->disk_used),
                number_format((int) $account->disk_quota),
                $percent,
                $level->label(),
            ),
            sprintf('Akun Hestia: %s di server %s.', $account->hestia_user, $server),
            sprintf(
                'Ambang: warning %d%%, kritis %d%%.',
                (int) config('crm.disk_quota.warning_percent', 80),
                (int) config('crm.disk_quota.critical_percent', 90),
            ),
            'Insiden ditutup otomatis bila pemakaian turun di bawah ambang atau level berubah.',
        ]);
    }

    private function externalIdPrefix(HestiaAccount $account): string
    {
        return 'disk-quota:'.$account->id.':';
    }

    private function externalId(HestiaAccount $account, DiskAlertLevel $level): string
    {
        return $this->externalIdPrefix($account).$level->value;
    }
}