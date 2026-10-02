<?php

namespace App\Domains\Hestia\Services;

use App\Domains\Hestia\Enums\HestiaAccountStatus;
use App\Domains\Hestia\Enums\HestiaMappingStatus;
use App\Domains\Hestia\Exceptions\HestiaApiException;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Services\Enums\ServiceCycle;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Models\Service;
use Illuminate\Support\Carbon as CarbonAlias;
use Illuminate\Support\Facades\Log;

/**
 * Mesin sinkronisasi HestiaCP → CRM (F3-1).
 *
 * Alur:
 *   1. Tarik daftar user Hestia, lalu web domain tiap user (READ-ONLY).
 *   2. Upsert tiap domain ke `hestia_accounts` (kunci `external_key` → idempotent).
 *   3. Cocokkan otomatis ke klien (HestiaClientMatcher); yang cocok dibuatkan /
 *      dihubungkan ke Service, sisanya berstatus "belum dipetakan".
 *   4. Akun yang hilang dari Hestia (atau di-suspend) → status `inactive` beserta
 *      Service-nya; baris TIDAK pernah dihapus.
 *   5. Catat hasil di `hestia_sync_logs` + aplikasi log. Kredensial tidak pernah
 *      ditulis ke log.
 *
 * Tidak ada exception yang keluar dari sync() — kegagalan direkam di SyncLog
 * agar pemanggil (command/controller) cukup memeriksa isSuccess().
 */
class HestiaSyncService
{
    public function __construct(
        private readonly HestiaClientMatcher $matcher = new HestiaClientMatcher,
    ) {}

    public function sync(?HestiaClient $client = null): HestiaSyncLog
    {
        $startedAt = CarbonAlias::now();
        $log = HestiaSyncLog::create([
            'status' => HestiaSyncLog::STATUS_RUNNING,
            'started_at' => $startedAt,
        ]);

        try {
            if (! config('crm.hestia.enabled', false)) {
                throw HestiaApiException::notConfigured();
            }

            $client ??= new HestiaClient;

            if (! $client->isConfigured()) {
                throw HestiaApiException::notConfigured();
            }

            $pulled = 0;
            $created = 0;
            $updated = 0;
            $seenKeys = [];

            foreach ($client->users() as $username => $userData) {
                $username = $this->normalizeUsername($username, $userData);
                if ($username === '') {
                    continue;
                }

                $plan = isset($userData['PACKAGE']) ? (string) $userData['PACKAGE'] : null;

                // F4-13: kuota paket & status suspend hanya ada di level AKUN.
                $userQuota = HestiaQuota::fromUserPayload(is_array($userData) ? $userData : []);
                $userSuspended = HestiaQuota::isYes($userData['SUSPENDED'] ?? null) ?? false;

                foreach ($client->webDomains($username) as $domain => $data) {
                    $domain = mb_strtolower(trim((string) $domain));
                    if ($domain === '' || ! is_array($data)) {
                        continue;
                    }

                    $pulled++;
                    $seenKeys[] = HestiaAccount::keyFor($username, $domain);
                    $this->upsertAccount($username, $domain, $data, $plan, $userQuota, $userSuspended) ? $created++ : $updated++;
                }
            }

            // Hanya nonaktifkan bila benar-benar ada data yang ditarik (hindari
            // menonaktifkan semuanya akibat respons kosong / salah konfigurasi).
            $deactivated = $pulled > 0 ? $this->deactivateMissing($seenKeys) : 0;
            $unmapped = HestiaAccount::unmapped()->count();

            $log->update([
                'status' => HestiaSyncLog::STATUS_SUCCESS,
                'pulled' => $pulled,
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
                'unmapped' => $unmapped,
                'finished_at' => CarbonAlias::now(),
            ]);

            Log::info('Sinkronisasi Hestia selesai', [
                'pulled' => $pulled,
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
                'unmapped' => $unmapped,
            ]);
        } catch (\Throwable $e) {
            // Pesan exception TIDAK memuat kredensial (lihat HestiaApiException).
            $log->update([
                'status' => HestiaSyncLog::STATUS_FAILED,
                'finished_at' => CarbonAlias::now(),
                'message' => $e->getMessage(),
            ]);

            Log::warning('Sinkronisasi Hestia gagal', ['error' => $e->getMessage()]);
        }

        return $log->refresh();
    }

    /** Petakan akun (manual oleh admin) ke klien + buat/hubungkan Service. */
    public function assignClient(HestiaAccount $account, int $clientId): Service
    {
        $account->client_id = $clientId;
        $account->mapping_status = HestiaMappingStatus::Mapped;
        $account->save();

        if ($account->service) {
            $account->service->update(['client_id' => $clientId]);

            return $account->service;
        }

        $service = $this->createService($account);

        $account->service_id = $service->id;
        $account->save();

        return $service;
    }

    /** Tandai akun diabaikan (hanya untuk akun yang belum punya Service). */
    public function ignoreAccount(HestiaAccount $account): void
    {
        $account->mapping_status = HestiaMappingStatus::Ignored;
        $account->client_id = null;
        $account->save();
    }

    /** @param array<string, mixed> $data */
    private function upsertAccount(
        string $username,
        string $domain,
        array $data,
        ?string $plan,
        ?int $userDiskQuota = null,
        bool $userSuspended = false,
    ): bool {
        $account = HestiaAccount::firstOrNew(['external_key' => HestiaAccount::keyFor($username, $domain)]);
        $isNew = ! $account->exists;

        $suspended = HestiaQuota::isYes($data['SUSPENDED'] ?? null) ?? false;

        $account->fill([
            'hestia_user' => $username,
            'domain' => $domain,
            'plan' => $plan,
            'service_type' => $account->service_type ?: 'hosting',
            'status' => $suspended ? HestiaAccountStatus::Inactive : HestiaAccountStatus::Active,
            'start_date' => $account->start_date ?? $this->parseDate($data['DATE'] ?? null),
            'raw' => $data,
            // F4-13: kuota & pemakaian disk berasal dari dua level payload.
            'disk_used' => HestiaQuota::toMegabytes($data['U_DISK'] ?? null),
            'disk_quota' => $userDiskQuota,
            'suspended' => $suspended,
            'user_suspended' => $userSuspended,
        ]);
        $account->first_seen_at ??= CarbonAlias::now();
        $account->last_seen_at = CarbonAlias::now();

        if ($account->service_id !== null && $account->service !== null) {
            // Sudah terpetakan: sinkronkan status Service, jangan sentuh harga/catatan manual.
            $account->service->update(['status' => $account->status->value === 'active' ? ServiceStatus::Active : ServiceStatus::Inactive]);
        } elseif ($account->mapping_status !== HestiaMappingStatus::Ignored) {
            $decision = $this->matcher->match($domain, $username);

            if ($decision['service_id'] !== null) {
                // Adopsi Service yang sudah ada (dulu diinput manual).
                $account->service_id = $decision['service_id'];
                $account->client_id = $decision['client_id'];
                $account->mapping_status = HestiaMappingStatus::Auto;
                Service::query()->whereKey($decision['service_id'])->update([
                    'status' => $account->status->value === 'active' ? ServiceStatus::Active->value : ServiceStatus::Inactive->value,
                ]);
            } elseif ($decision['client_id'] !== null) {
                $account->client_id = $decision['client_id'];
                $account->mapping_status = HestiaMappingStatus::Auto;
                $account->save();
                $account->service_id = $this->createService($account)->id;
            } else {
                $account->client_id = null;
                $account->mapping_status = HestiaMappingStatus::Unmapped;
            }
        }

        $account->save();

        return $isNew;
    }

    private function createService(HestiaAccount $account): Service
    {
        $planNote = $account->plan ? ", plan {$account->plan}" : '';

        return Service::create([
            'client_id' => $account->client_id,
            'type' => $account->service_type,
            'name' => $account->domain,
            'reference' => $account->domain,
            'start_date' => $account->start_date,
            'cycle' => ServiceCycle::Yearly,
            'status' => $account->status->value === 'active' ? ServiceStatus::Active : ServiceStatus::Inactive,
            'reminder_enabled' => true,
            'notes' => "Disinkronkan otomatis dari HestiaCP (akun {$account->hestia_user}{$planNote}).",
        ]);
    }

    /**
     * Tandai akun yang TIDAK terlihat pada sync ini sebagai nonaktif (tidak
     * dihapus). Penentuan memakai daftar kunci yang terlihat (bukan perbandingan
     * timestamp) agar tidak bergantung pada resolusi jam.
     *
     * @param  list<string>  $seenKeys
     */
    private function deactivateMissing(array $seenKeys): int
    {
        $stale = HestiaAccount::query()
            ->where('status', HestiaAccountStatus::Active)
            ->whereNotIn('external_key', $seenKeys)
            ->with('service')
            ->get();

        foreach ($stale as $account) {
            $account->update(['status' => HestiaAccountStatus::Inactive]);
            $account->service?->update(['status' => ServiceStatus::Inactive]);
        }

        return $stale->count();
    }

    /** @param array<string, mixed> $userData */
    private function normalizeUsername(int|string $username, array $userData): string
    {
        if (is_string($username) && $username !== '') {
            return $username;
        }

        return (string) ($userData['USER'] ?? '');
    }

    private function parseDate(mixed $value): ?CarbonAlias
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonAlias::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
