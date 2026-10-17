<?php

namespace Tests\Feature\Providers;

use App\Domains\Providers\Models\DomainProvider;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use App\Domains\Services\Models\Service;
use Database\Factories\DomainProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Command `providers:sync-domain-expiry` (t_f2ae1c62).
 *
 * Dipakai driver `manual` (tanpa HTTP) supaya tes hermetis; perilaku driver
 * lain (NameSilo/Hostinger/Hestia) sudah dicakup tes driver masing-masing.
 */
class SyncDomainExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    private function manualProvider(string $name, array $domains, bool $active = true): DomainProvider
    {
        return DomainProviderFactory::new()->create([
            'name' => $name,
            'driver' => 'manual',
            'credentials' => ['domains_json' => json_encode($domains)],
            'is_active' => $active,
        ]);
    }

    private function domainService(string $reference, string $endDate): Service
    {
        return Service::factory()->create([
            'type' => ServiceType::Domain,
            'name' => $reference,
            'reference' => $reference,
            'end_date' => $endDate,
            'status' => ServiceStatus::Active,
        ]);
    }

    public function test_sync_refreshes_end_date_of_existing_services(): void
    {
        $this->manualProvider('Provider A', [
            ['domain' => 'contoh.com', 'expires_at' => '2027-05-01'],
        ]);
        $service = $this->domainService('contoh.com', '2026-01-01');

        $this->artisan('providers:sync-domain-expiry')
            ->expectsOutputToContain('[Provider A] 1 disegarkan')
            ->assertSuccessful();

        $this->assertSame('2027-05-01', $service->fresh()->end_date->toDateString());
    }

    public function test_sync_is_idempotent_and_never_duplicates_services(): void
    {
        $this->manualProvider('Provider A', [
            ['domain' => 'contoh.com', 'expires_at' => '2027-05-01'],
        ]);
        $this->domainService('contoh.com', '2026-01-01');

        // Dua kali run berturut-turut: jumlah layanan tidak boleh berubah.
        $this->artisan('providers:sync-domain-expiry')->assertSuccessful();
        $this->artisan('providers:sync-domain-expiry')->assertSuccessful();

        $this->assertSame(1, Service::query()->where('reference', 'contoh.com')->count());
        $this->assertSame(1, Service::query()->count());
    }

    public function test_sync_skips_unmapped_domains_instead_of_creating_them(): void
    {
        // domain-belum.com tidak punya layanan CRM: harus dilewati, bukan dibuat.
        $this->manualProvider('Provider A', [
            ['domain' => 'contoh.com', 'expires_at' => '2027-05-01'],
            ['domain' => 'domain-belum.com', 'expires_at' => '2027-06-01'],
        ]);
        $this->domainService('contoh.com', '2026-01-01');

        $this->artisan('providers:sync-domain-expiry')
            ->expectsOutputToContain('1 domain belum dipetakan')
            ->assertSuccessful();

        $this->assertNull(Service::query()->where('reference', 'domain-belum.com')->first());
        $this->assertSame(1, Service::query()->count());
    }

    public function test_inactive_providers_are_not_synced(): void
    {
        $this->manualProvider('Provider Nonaktif', [
            ['domain' => 'contoh.com', 'expires_at' => '2027-05-01'],
        ], active: false);
        $service = $this->domainService('contoh.com', '2026-01-01');

        $this->artisan('providers:sync-domain-expiry')
            ->expectsOutputToContain('Tidak ada provider domain aktif')
            ->assertSuccessful();

        $this->assertSame('2026-01-01', $service->fresh()->end_date->toDateString());
    }

    public function test_one_failing_provider_does_not_stop_the_rest(): void
    {
        // Provider rusak: driver manual tanpa domains_json melempar
        // ProviderNotConfigured — mewakili provider offline/kredensial salah.
        DomainProviderFactory::new()->create([
            'name' => 'Provider Rusak',
            'driver' => 'manual',
            'credentials' => [],
            'is_active' => true,
        ]);
        $this->manualProvider('Provider Sehat', [
            ['domain' => 'sehat.com', 'expires_at' => '2027-07-01'],
        ]);
        $service = $this->domainService('sehat.com', '2026-01-01');

        $this->artisan('providers:sync-domain-expiry')
            ->expectsOutputToContain('[Provider Rusak] gagal')
            ->expectsOutputToContain('[Provider Sehat] 1 disegarkan')
            ->expectsOutputToContain('1 sukses, 1 gagal')
            ->assertFailed();

        // Provider sehat tetap ter-sync meski provider lain gagal.
        $this->assertSame('2027-07-01', $service->fresh()->end_date->toDateString());
    }

    public function test_provider_option_limits_sync_to_one_provider(): void
    {
        $this->manualProvider('Provider A', [
            ['domain' => 'a.com', 'expires_at' => '2027-05-01'],
        ]);
        $this->manualProvider('Provider B', [
            ['domain' => 'b.com', 'expires_at' => '2027-06-01'],
        ]);
        $serviceA = $this->domainService('a.com', '2026-01-01');
        $serviceB = $this->domainService('b.com', '2026-01-01');

        $this->artisan('providers:sync-domain-expiry', ['--provider' => 'Provider A'])
            ->assertSuccessful();

        $this->assertSame('2027-05-01', $serviceA->fresh()->end_date->toDateString());
        $this->assertSame('2026-01-01', $serviceB->fresh()->end_date->toDateString());
    }

    public function test_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('providers:sync-domain-expiry')
            ->assertSuccessful();
    }
}
