<?php

namespace App\Domains\Providers\Services;

use App\Domains\Providers\Contracts\DomainProviderDriver;
use App\Domains\Providers\Models\DomainProvider;
use App\Domains\Services\Enums\ServiceCycle;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use App\Domains\Services\Models\Service;

/**
 * Impor domain dari provider registry menjadi data layanan CRM (F4-7).
 *
 * Domain yang ditarik driver (mis. NameSilo) diubah menjadi baris `services`
 * bertipe `domain` milik satu klien, sehingga ikut masuk ke pengingat kedaluwarsa
 * dan laporan CRUD layanan yang sudah ada di CRM.
 *
 * Aturan penting:
 *   - IDEMPOTENT: domain dicocokkan lewat kolom `reference`. Domain yang sudah
 *     menjadi layanan TIDAK diduplikasi; hanya `end_date` yang disegarkan dari
 *     data provider. Nama manual, harga, dan status tidak ditimpa.
 *   - TIDAK menghapus: domain yang hilang dari provider tidak dihapus dari CRM
 *     (konsisten dengan sinkronisasi Hestia F3-1) — data CRM bukan cermin API.
 *   - `price` 0 dan `cycle` tahunan: NameSilo tidak mengembalikan harga pada
 *     operasi baca, jadi admin yang mengisinya manual.
 */
class ProviderServiceImporter
{
    /**
     * Impor seluruh domain milik provider ke layanan milik klien.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(DomainProvider $provider, int $clientId, ?DomainProviderDriver $driver = null): array
    {
        $driver ??= $provider->driver();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($driver->listDomains() as $info) {
            $domain = mb_strtolower(trim($info->domain));

            if ($domain === '') {
                $skipped++;

                continue;
            }

            $existing = Service::query()
                ->where('reference', $domain)
                ->first();

            $endDate = $info->expiresAt?->toDateString();

            if ($existing === null) {
                Service::create([
                    'client_id' => $clientId,
                    'type' => ServiceType::Domain,
                    'name' => $domain,
                    'reference' => $domain,
                    'start_date' => $info->raw['created'] ?? null,
                    'end_date' => $endDate,
                    'cycle' => ServiceCycle::Yearly,
                    'status' => ServiceStatus::Active,
                    'reminder_enabled' => true,
                    'notes' => $this->note($provider),
                ]);

                $created++;

                continue;
            }

            // Sudah menjadi layanan (input manual atau impor sebelumnya): segarkan
            // hanya tanggal kedaluwarsa, sisanya tetap seperti admin terakhir.
            if ($endDate !== null) {
                $existing->forceFill(['end_date' => $endDate])->save();
            }

            $updated++;
        }

        return compact('created', 'updated', 'skipped');
    }

    private function note(DomainProvider $provider): string
    {
        return sprintf(
            'Disinkronkan otomatis dari provider "%s" (registrar: %s).',
            $provider->name,
            $provider->driverLabel(),
        );
    }
}
