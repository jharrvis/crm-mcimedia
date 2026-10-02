<?php

namespace Tests\Feature\Providers;

use App\Domains\Providers\Contracts\DomainInfo;
use App\Domains\Providers\DomainProviderRegistry;
use App\Domains\Providers\Drivers\HostingerDomainProviderDriver;
use App\Domains\Providers\Exceptions\ProviderApiException;
use App\Domains\Providers\Exceptions\ProviderNotConfigured;
use App\Domains\Providers\Models\DomainProvider;
use App\Models\User;
use Database\Factories\DomainProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tes driver Hostinger untuk provider registry (F4-6):
 * autentikasi Bearer token hPanel, listDomains/getExpiry dari portfolio,
 * pemetaan status/kedaluwarsa, penanganan error yang aman, dan integrasi UI.
 */
class HostingerDomainProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PORTFOLIO = [
        ['id' => 3409407, 'domain' => 'ibmp.co.id', 'type' => 'domain', 'status' => 'active', 'created_at' => '2022-12-21T09:46:56Z', 'expires_at' => '2027-06-28T00:00:00Z'],
        ['id' => 3546843, 'domain' => 'expired.example', 'type' => 'domain', 'status' => 'expired', 'created_at' => '2022-12-22T12:28:06Z', 'expires_at' => '2026-01-13T00:00:00Z'],
    ];

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** @param list<array<string, mixed>>|array<string, mixed>|string $body */
    private function fakePortfolio(array|string $body, int $status = 200): void
    {
        Http::fake([
            'developers.hostinger.com/*' => Http::response($body, $status),
        ]);
    }

    // ---------- deskripsi driver ----------

    public function test_registry_exposes_hostinger_driver(): void
    {
        $registry = new DomainProviderRegistry;

        $this->assertTrue($registry->has('hostinger'));
        $this->assertSame('Hostinger', $registry->label('hostinger'));
        $this->assertSame(HostingerDomainProviderDriver::class, $registry->resolve('hostinger'));
        $this->assertSame('Hostinger', $registry->options()['hostinger']);
    }

    public function test_credential_fields_expose_secret_api_token(): void
    {
        $fields = HostingerDomainProviderDriver::credentialFields();

        $this->assertTrue($fields['api_token']['required']);
        $this->assertTrue($fields['api_token']['secret']);
        $this->assertSame('password', $fields['api_token']['type']);
        $this->assertSame(HostingerDomainProviderDriver::DEFAULT_BASE_URL, $fields['base_url']['default']);
        $this->assertSame(30, $fields['timeout']['default']);
    }

    // ---------- listDomains / getExpiry ----------

    public function test_list_domains_maps_portfolio_rows(): void
    {
        $this->fakePortfolio(self::PORTFOLIO);
        $driver = DomainProviderFactory::new()->hostinger()->create()->driver();

        $domains = $driver->listDomains();

        $this->assertCount(2, $domains);
        $this->assertContainsOnlyInstancesOf(DomainInfo::class, $domains);

        $this->assertSame('ibmp.co.id', $domains[0]->domain);
        $this->assertSame('active', $domains[0]->status);
        $this->assertSame('2027-06-28', $domains[0]->expiresAt?->format('Y-m-d'));
        $this->assertSame(3409407, $domains[0]->raw['id']);

        // Status & tanggal kedaluwarsa dari penyedia dipetakan apa adanya.
        $this->assertSame('expired', $domains[1]->status);
        $this->assertTrue($domains[1]->isExpired());
    }

    public function test_list_domains_skips_blank_rows_and_tolerates_bad_dates(): void
    {
        $this->fakePortfolio([
            ['domain' => 'ok.test', 'status' => 'ACTIVE', 'expires_at' => '2027-01-05T00:00:00Z'],
            ['domain' => '', 'status' => 'active'],                 // tanpa nama → dilewati
            ['status' => 'active'],                                  // tanpa domain → dilewati
            ['domain' => 'tanpa-tanggal.test'],                      // tanpa tanggal → null
            ['domain' => 'tanggal-rusak.test', 'expires_at' => 'bukan-tanggal'],
        ]);

        $domains = DomainProviderFactory::new()->hostinger()->create()->driver()->listDomains();

        $this->assertSame(
            ['ok.test', 'tanpa-tanggal.test', 'tanggal-rusak.test'],
            array_map(fn (DomainInfo $d) => $d->domain, $domains),
        );
        $this->assertSame('active', $domains[0]->status);          // 'ACTIVE' → 'active'
        $this->assertNull($domains[1]->expiresAt);
        $this->assertNull($domains[2]->expiresAt);
    }

    public function test_get_expiry_matches_domain_case_insensitively(): void
    {
        $this->fakePortfolio(self::PORTFOLIO);
        $driver = DomainProviderFactory::new()->hostinger()->create()->driver();

        $this->assertSame('2027-06-28', $driver->getExpiry('IBMP.CO.ID')?->format('Y-m-d'));
        $this->assertNull($driver->getExpiry('tidak-ada.test'));
    }

    public function test_bearer_token_is_sent_to_portfolio_endpoint(): void
    {
        $this->fakePortfolio(self::PORTFOLIO);
        $driver = DomainProviderFactory::new()->hostinger()->create()->driver();

        $driver->listDomains();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://developers.hostinger.com/api/domains/v1/portfolio'
                && $request->hasHeader('Authorization', 'Bearer token-hostinger-test-123');
        });
    }

    public function test_portfolio_is_fetched_once_per_driver_instance(): void
    {
        $this->fakePortfolio(self::PORTFOLIO);
        $driver = DomainProviderFactory::new()->hostinger()->create()->driver();

        $driver->listDomains();
        $driver->getExpiry('ibmp.co.id');   // tidak boleh memicu request kedua

        Http::assertSentCount(1);
    }

    // ---------- penanganan error (aman, tanpa bocor kredensial) ----------

    public function test_missing_token_throws_provider_not_configured(): void
    {
        $provider = DomainProvider::create([
            'name' => 'Hostinger Kosong',
            'driver' => 'hostinger',
            'credentials' => ['api_token' => ''],
            'is_active' => true,
        ]);

        $this->expectException(ProviderNotConfigured::class);

        $provider->driver()->listDomains();
    }

    public function test_http_error_throws_safe_provider_api_exception(): void
    {
        $this->fakePortfolio(['message' => 'Unauthenticated.'], 401);
        $driver = DomainProviderFactory::new()->hostinger()->create()->driver();

        try {
            $driver->listDomains();
            $this->fail('Seharusnya melempar ProviderApiException.');
        } catch (ProviderApiException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString('token-hostinger-test-123', $e->getMessage());
        }
    }

    // ---------- integrasi UI ----------

    public function test_domains_page_lists_hostinger_domains_and_sets_last_used_at(): void
    {
        $this->login();
        $this->fakePortfolio(self::PORTFOLIO);
        $provider = DomainProviderFactory::new()->hostinger()->create(['name' => 'Registrar Hostinger']);

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('ibmp.co.id')
            ->assertSee('28/06/2027')
            ->assertSee('expired.example')
            ->assertSee('Expired')
            ->assertDontSee('token-hostinger-test-123');

        $this->assertNotNull($provider->refresh()->last_used_at);
    }

    public function test_domains_page_shows_safe_error_on_api_failure(): void
    {
        $this->login();
        $this->fakePortfolio(['message' => 'Forbidden.'], 403);
        $provider = DomainProviderFactory::new()->hostinger()->create();

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('Gagal menarik domain dari provider')
            ->assertSee('HTTP 403')
            ->assertDontSee('token-hostinger-test-123');
    }

    public function test_create_form_renders_hostinger_credential_fields(): void
    {
        $this->login();

        $this->get(route('domain-providers.create', ['driver' => 'hostinger']))
            ->assertOk()
            ->assertSee('Hostinger')
            ->assertSee('credentials[api_token]', false)
            ->assertSee('API Token (hPanel)');
    }

    public function test_store_creates_hostinger_provider_with_encrypted_token(): void
    {
        $this->login();

        $this->post(route('domain-providers.store'), [
            'name' => 'Hostinger Utama',
            'driver' => 'hostinger',
            'credentials' => [
                'api_token' => 'token-rahasia-hostinger-xyz',
                'base_url' => 'https://developers.hostinger.com',
                'timeout' => 30,
            ],
        ])->assertRedirect(route('domain-providers.index'));

        $provider = DomainProvider::firstOrFail();
        $this->assertSame('hostinger', $provider->driver);
        $this->assertSame('token-rahasia-hostinger-xyz', $provider->credentials['api_token']);

        // Di database mentah, token tersimpan terenkripsi.
        $raw = (string) DB::table('domain_providers')->value('credentials');
        $this->assertStringNotContainsString('token-rahasia-hostinger-xyz', $raw);

        // Field rahasia tidak pernah dirender ulang di form/halaman.
        $this->get(route('domain-providers.index'))->assertDontSee('token-rahasia-hostinger-xyz');
        $this->get(route('domain-providers.edit', $provider))->assertDontSee('token-rahasia-hostinger-xyz');
    }
}
