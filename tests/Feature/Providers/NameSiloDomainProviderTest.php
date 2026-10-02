<?php

namespace Tests\Feature\Providers;

use App\Domains\Providers\Contracts\DomainInfo;
use App\Domains\Providers\DomainProviderRegistry;
use App\Domains\Providers\Drivers\NameSiloDomainProviderDriver;
use App\Domains\Providers\Exceptions\NameSiloRequestFailed;
use App\Domains\Providers\Exceptions\ProviderNotConfigured;
use App\Domains\Providers\Models\DomainProvider;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use App\Domains\Services\Models\Service;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\DomainProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tes driver NameSilo untuk provider registry (F4-7).
 *
 * Bentuk payload di bawah disalin VERBATIM dari respons API NameSilo v1
 * (`type=json`, diverifikasi langsung ke www.namesilo.com/api):
 *   - listDomains  → reply.domains[] = {domain, created, expires}
 *   - getDomainInfo → reply{code, detail, created, expires, status, locked,
 *                     private, auto_renew, ...} (TANPA field `domain`)
 *   - error        → HTTP 200 (!) dengan reply.code berupa STRING ("110")
 *
 * Perilaku NameSilo yang menentukan banyak tes di bawah:
 *   1. Kegagalan API dikembalikan sebagai HTTP 200 + reply.code != 300, bukan
 *      HTTP error → driver wajib memeriksa reply.code, bukan `$response->failed()`.
 *   2. `reply.code` bertipe string pada error dan int pada sukses → harus di-cast.
 *   3. Auto-renew TIDAK punya operasi list; flag-nya hanya ada di getDomainInfo.
 */
class NameSiloDomainProviderTest extends TestCase
{
    use RefreshDatabase;

    /** Bentuk asli respons `listDomains` (2 domain pertama dari akun uji). */
    private const LIST_DOMAINS = [
        'reply' => [
            'code' => 300,
            'detail' => 'success',
            'domains' => [
                ['domain' => 'eskrimkekinian.com', 'created' => '2023-02-05', 'expires' => '2027-02-06'],
                ['domain' => 'gorenganews.com', 'created' => '2022-07-06', 'expires' => '2027-07-06'],
            ],
        ],
    ];

    /** Bentuk asli respons `getDomainInfo` (catatan: tanpa field `domain`). */
    private const DOMAIN_INFO = [
        'reply' => [
            'code' => 300,
            'detail' => 'success',
            'created' => '2023-02-05',
            'expires' => '2027-02-06',
            'status' => 'Active',
            'locked' => 'Yes',
            'private' => 'Yes',
            'auto_renew' => 'No',
            'traffic_type' => 'Custom DNS',
            'email_verification_required' => 'No',
            'portfolio' => 'N/A',
            'forward_url' => 'N/A',
            'forward_type' => 'N/A',
            'nameservers' => [
                ['nameserver' => 'armando.ns.cloudflare.com', 'position' => 1],
                ['nameserver' => 'coraline.ns.cloudflare.com', 'position' => 2],
            ],
            'contact_ids' => [
                'registrant' => '25112',
                'administrative' => '25112',
                'technical' => '25112',
                'billing' => '25112',
            ],
        ],
    ];

    /** Balasan NameSilo untuk domain yang bukan milik akun ini. */
    private const NOT_OWNED = [
        'reply' => [
            'code' => 200,
            'detail' => 'Domain is not active, or does not belong to this user',
            'inactive_type' => '',
        ],
    ];

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * Fake NameSilo per-operasi.
     *
     * @param  array<string, mixed>  $map  nama operasi (tanpa path) → payload
     */
    private function fakeNameSilo(array $map, int $status = 200): void
    {
        $patterns = [];

        foreach ($map as $operation => $payload) {
            $patterns['www.namesilo.com/api/'.$operation.'*'] = Http::response($payload, $status);
        }

        Http::fake($patterns);
    }

    private function driver(array $credentials = []): NameSiloDomainProviderDriver
    {
        $provider = DomainProviderFactory::new()->namesilo($credentials)->create();

        return $provider->driver();
    }

    // ---------- deskripsi driver ----------

    public function test_registry_exposes_namesilo_driver(): void
    {
        $registry = new DomainProviderRegistry;

        $this->assertTrue($registry->has('namesilo'));
        $this->assertSame('NameSilo (registrar domain)', $registry->label('namesilo'));
        $this->assertSame(NameSiloDomainProviderDriver::class, $registry->resolve('namesilo'));
        $this->assertSame('NameSilo (registrar domain)', $registry->options()['namesilo']);
    }

    public function test_credential_fields_expose_secret_api_key(): void
    {
        $fields = NameSiloDomainProviderDriver::credentialFields();

        $this->assertTrue($fields['api_key']['required']);
        $this->assertTrue($fields['api_key']['secret']);
        $this->assertSame('password', $fields['api_key']['type']);
        $this->assertSame(20, $fields['timeout']['default']);
    }

    // ---------- listDomains ----------

    public function test_list_domains_maps_list_domains_rows(): void
    {
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS]);

        // enrich_details dimatikan: status/auto-renew tidak diambil di sini.
        $domains = $this->driver(['enrich_details' => false])->listDomains();

        $this->assertCount(2, $domains);
        $this->assertContainsOnlyInstancesOf(DomainInfo::class, $domains);

        $this->assertSame('eskrimkekinian.com', $domains[0]->domain);
        $this->assertSame('2027-02-06', $domains[0]->expiresAt?->format('Y-m-d'));
        $this->assertSame('2023-02-05', $domains[0]->raw['created']);
        $this->assertSame('gorenganews.com', $domains[1]->domain);
        $this->assertSame('2027-07-06', $domains[1]->expiresAt?->format('Y-m-d'));
    }

    public function test_list_domains_skips_blank_rows_and_tolerates_bad_dates(): void
    {
        $this->fakeNameSilo(['listDomains' => ['reply' => [
            'code' => 300,
            'detail' => 'success',
            'domains' => [
                ['domain' => 'ok.test', 'created' => '2023-01-01', 'expires' => '2027-01-05'],
                ['domain' => '', 'expires' => '2027-01-05'],          // tanpa nama → dilewati
                ['created' => '2023-01-01', 'expires' => '2027-01-05'],  // tanpa domain → dilewati
                ['domain' => 'tanpa-tanggal.test'],
                ['domain' => 'tanggal-rusak.test', 'expires' => 'bukan-tanggal'],
                'bukan-array',                                            // entri rusak → dilewati
            ],
        ]]]);

        $domains = $this->driver(['enrich_details' => false])->listDomains();

        $this->assertSame(
            ['ok.test', 'tanpa-tanggal.test', 'tanggal-rusak.test'],
            array_map(fn (DomainInfo $d) => $d->domain, $domains),
        );
        $this->assertSame('2027-01-05', $domains[0]->expiresAt?->format('Y-m-d'));
        $this->assertNull($domains[1]->expiresAt);
        $this->assertNull($domains[2]->expiresAt);
    }

    public function test_list_domains_sends_api_key_and_version_params(): void
    {
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS]);
        $this->driver(['enrich_details' => false])->listDomains();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://www.namesilo.com/api/listDomains')
                && $request->data()['key'] === 'nsk-test-key-123456'
                && $request->data()['version'] === '1'
                && $request->data()['type'] === 'json';
        });
    }

    public function test_list_domains_is_fetched_once_per_driver_instance(): void
    {
        $this->fakeNameSilo([
            'listDomains' => self::LIST_DOMAINS,
            'getDomainInfo' => self::DOMAIN_INFO,
        ]);

        $driver = $this->driver(['enrich_details' => false]);
        $driver->listDomains();
        $driver->getExpiry('eskrimkekinian.com');

        // listDomains di-cache: panggilan kedua tidak menambah request listDomains.
        Http::assertSentCount(2);   // 1 listDomains + 1 getDomainInfo
    }

    // ---------- getExpiry ----------

    public function test_get_expiry_uses_get_domain_info(): void
    {
        $this->fakeNameSilo(['getDomainInfo' => self::DOMAIN_INFO]);
        $driver = $this->driver();

        $this->assertSame('2027-02-06', $driver->getExpiry('eskrimkekinian.com')?->format('Y-m-d'));
    }

    public function test_get_expiry_returns_null_for_domain_not_owned(): void
    {
        $this->fakeNameSilo(['getDomainInfo' => self::NOT_OWNED]);
        $driver = $this->driver();

        $this->assertNull($driver->getExpiry('bukan-milik-saya.test'));
    }

    public function test_list_domains_results_match_case_insensitively(): void
    {
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS, 'getDomainInfo' => self::DOMAIN_INFO]);

        $domains = $this->driver(['enrich_details' => false])->listDomains();
        $byLower = collect($domains)->keyBy(fn (DomainInfo $d) => mb_strtolower($d->domain));

        $this->assertSame(
            '2027-02-06',
            $byLower['eskrimkekinian.com']->expiresAt?->format('Y-m-d'),
            'Domain dari NameSilo selalu huruf kecil; pencocokan harus tahan huruf besar.',
        );
    }

    // ---------- status domain ----------

    public function test_details_expose_status_and_flags(): void
    {
        $this->fakeNameSilo(['getDomainInfo' => self::DOMAIN_INFO]);
        $driver = $this->driver();

        $details = $driver->details('eskrimkekinian.com');

        $this->assertNotNull($details);
        $this->assertSame('eskrimkekinian.com', $details['domain']);   // diisi dari argumen
        $this->assertSame('active', $details['status']);
        $this->assertFalse($details['auto_renew']);
        $this->assertTrue($details['locked']);
        $this->assertTrue($details['private']);
        $this->assertSame('2027-02-06', $details['expires']);
        $this->assertSame(
            ['armando.ns.cloudflare.com', 'coraline.ns.cloudflare.com'],
            $details['nameservers'],
        );
    }

    public function test_status_and_auto_renew_accessors(): void
    {
        $this->fakeNameSilo(['getDomainInfo' => self::DOMAIN_INFO]);
        $driver = $this->driver();

        $this->assertSame('active', $driver->status('eskrimkekinian.com'));
        $this->assertFalse($driver->isAutoRenewEnabled('eskrimkekinian.com'));
    }

    public function test_status_returns_null_for_domain_not_owned(): void
    {
        $this->fakeNameSilo(['getDomainInfo' => self::NOT_OWNED]);
        $driver = $this->driver();

        $this->assertNull($driver->status('bukan-milik-saya.test'));
        $this->assertNull($driver->isAutoRenewEnabled('bukan-milik-saya.test'));
    }

    public function test_details_cache_avoids_repeated_requests(): void
    {
        $this->fakeNameSilo(['getDomainInfo' => self::DOMAIN_INFO]);
        $driver = $this->driver();

        $driver->details('eskrimkekinian.com');
        $driver->details('eskrimkekinian.com');

        Http::assertSentCount(1);
    }

    public function test_list_domains_can_enrich_status_per_domain(): void
    {
        $this->fakeNameSilo([
            'listDomains' => ['reply' => [
                'code' => 300,
                'detail' => 'success',
                'domains' => [
                    ['domain' => 'aktif.test', 'created' => '2023-01-01', 'expires' => '2027-01-05'],
                    ['domain' => 'kedaluwarsa.test', 'created' => '2020-01-01', 'expires' => '2024-01-05'],
                ],
            ]],
            'getDomainInfo' => self::DOMAIN_INFO,
        ]);

        $domains = $this->driver(['enrich_details' => true])->listDomains();

        // getDomainInfo di-fake untuk keduanya; status 'Active' → 'active',
        // dan `raw` kini berisi detail (bukan baris listDomains).
        $this->assertSame('active', $domains[0]->status);
        $this->assertSame('aktif.test', $domains[0]->raw['domain']);
        $this->assertFalse($domains[0]->raw['auto_renew']);
        // Tanggal dari getDomainInfo (2027-02-06) mengalahkan baris listDomains.
        $this->assertSame('2027-02-06', $domains[0]->raw['expires']);
        $this->assertSame('2027-02-06', $domains[0]->expiresAt?->format('Y-m-d'));
    }

    // ---------- auto-renew (tulis) ----------

    public function test_set_auto_renew_enables_and_disables(): void
    {
        $this->fakeNameSilo([
            'addAutoRenewal' => ['reply' => ['code' => 300, 'detail' => 'success']],
            'removeAutoRenewal' => ['reply' => ['code' => 300, 'detail' => 'success']],
        ]);

        $driver = $this->driver();

        $this->assertTrue($driver->setAutoRenew('eskrimkekinian.com', true));
        $this->assertTrue($driver->setAutoRenew('eskrimkekinian.com', false));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/addAutoRenewal')
            && $r->data()['domain'] === 'eskrimkekinian.com');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/removeAutoRenewal')
            && $r->data()['domain'] === 'eskrimkekinian.com');
    }

    public function test_set_auto_renew_throws_on_api_rejection(): void
    {
        $this->fakeNameSilo([
            'addAutoRenewal' => ['reply' => ['code' => '110', 'detail' => 'Invalid API Key (Permission denied)']],
        ]);

        $driver = $this->driver();

        try {
            $driver->setAutoRenew('eskrimkekinian.com', true);
            $this->fail('Seharusnya melempar NameSiloRequestFailed.');
        } catch (NameSiloRequestFailed $e) {
            $this->assertSame(110, $e->apiCode());
            $this->assertStringNotContainsString('nsk-test-key-123456', $e->getMessage());
        }
    }

    // ---------- penanganan error (aman, tanpa bocor kredensial) ----------

    public function test_missing_api_key_throws_provider_not_configured(): void
    {
        $provider = DomainProvider::create([
            'name' => 'NameSilo Kosong',
            'driver' => 'namesilo',
            'credentials' => ['api_key' => ''],
            'is_active' => true,
        ]);

        $this->expectException(ProviderNotConfigured::class);

        $provider->driver()->listDomains();
    }

    public function test_api_error_code_is_treated_as_failure_despite_http_200(): void
    {
        // NameSilo membalas HTTP 200 dengan code 110 (kunci tidak valid).
        $this->fakeNameSilo(['listDomains' => ['reply' => ['code' => '110', 'detail' => 'Invalid API Key']]]);

        $driver = $this->driver(['enrich_details' => false]);

        $this->expectException(NameSiloRequestFailed::class);
        $driver->listDomains();
    }

    public function test_http_error_throws_safe_exception_without_key(): void
    {
        Http::fake(['www.namesilo.com/api/*' => Http::response('Service Unavailable', 503)]);

        $driver = $this->driver(['enrich_details' => false]);

        try {
            $driver->listDomains();
            $this->fail('Seharusnya melempar NameSiloRequestFailed.');
        } catch (NameSiloRequestFailed $e) {
            $this->assertStringContainsString('503', $e->getMessage());
            $this->assertStringNotContainsString('nsk-test-key-123456', $e->getMessage());
        }
    }

    public function test_malformed_payload_throws_invalid_response(): void
    {
        Http::fake(['www.namesilo.com/api/*' => Http::response('<html>bukan json</html>', 200)]);

        $driver = $this->driver(['enrich_details' => false]);

        $this->expectException(NameSiloRequestFailed::class);
        $driver->listDomains();
    }

    // ---------- integrasi UI: halaman daftar domain ----------

    public function test_domains_page_lists_namesilo_domains(): void
    {
        $this->login();
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS]);
        $provider = DomainProviderFactory::new()->namesilo(['enrich_details' => false])
            ->create(['name' => 'Registrar NameSilo']);

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('eskrimkekinian.com')
            ->assertSee('06/02/2027')
            ->assertSee('gorenganews.com')
            ->assertDontSee('nsk-test-key-123456');

        $this->assertNotNull($provider->refresh()->last_used_at);
    }

    public function test_domains_page_shows_safe_error_on_api_failure(): void
    {
        $this->login();
        $this->fakeNameSilo(['listDomains' => ['reply' => ['code' => '110', 'detail' => 'Invalid API Key']]]);
        $provider = DomainProviderFactory::new()->namesilo(['enrich_details' => false])->create();

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('Gagal menarik domain dari provider')
            ->assertDontSee('nsk-test-key-123456');
    }

    public function test_create_form_renders_namesilo_credential_fields(): void
    {
        $this->login();

        $this->get(route('domain-providers.create', ['driver' => 'namesilo']))
            ->assertOk()
            ->assertSee('NameSilo')
            ->assertSee('credentials[api_key]', false);
    }

    public function test_store_creates_namesilo_provider_with_encrypted_key(): void
    {
        $this->login();

        $this->post(route('domain-providers.store'), [
            'name' => 'NameSilo Utama',
            'driver' => 'namesilo',
            'credentials' => ['api_key' => 'kunci-rahasia-namesilo', 'timeout' => 20],
        ])->assertRedirect(route('domain-providers.index'));

        $provider = DomainProvider::firstOrFail();
        $this->assertSame('namesilo', $provider->driver);
        $this->assertSame('kunci-rahasia-namesilo', $provider->credentials['api_key']);

        $raw = (string) DB::table('domain_providers')->value('credentials');
        $this->assertStringNotContainsString('kunci-rahasia-namesilo', $raw);

        $this->get(route('domain-providers.edit', $provider))->assertDontSee('kunci-rahasia-namesilo');
    }

    // ---------- integrasi UI: auto-renew ----------

    public function test_auto_renew_route_toggles_and_reports_success(): void
    {
        $this->login();
        $this->fakeNameSilo([
            'addAutoRenewal' => ['reply' => ['code' => 300, 'detail' => 'success']],
            'getDomainInfo' => self::DOMAIN_INFO,
        ]);
        $provider = DomainProviderFactory::new()->namesilo()->create();

        $this->post(route('domain-providers.auto-renew', $provider), [
            'domain' => 'eskrimkekinian.com',
            'enable' => 1,
        ])->assertRedirect(route('domain-providers.domains', $provider))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/addAutoRenewal'));
    }

    public function test_auto_renew_route_reports_failure_without_leaking_key(): void
    {
        $this->login();
        $this->fakeNameSilo([
            'addAutoRenewal' => ['reply' => ['code' => '110', 'detail' => 'Invalid API Key']],
        ]);
        $provider = DomainProviderFactory::new()->namesilo()->create();

        $response = $this->from(route('domain-providers.domains', $provider))
            ->post(route('domain-providers.auto-renew', $provider), [
                'domain' => 'eskrimkekinian.com',
                'enable' => 1,
            ]);

        $response->assertRedirect(route('domain-providers.domains', $provider))
            ->assertSessionHas('error');
        $this->assertStringNotContainsString('nsk-test-key-123456', (string) session('error'));
    }

    public function test_auto_renew_route_rejects_empty_domain(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->namesilo()->create();

        $this->post(route('domain-providers.auto-renew', $provider), [
            'domain' => '',
            'enable' => 1,
        ])->assertSessionHasErrors('domain');

        Http::assertNothingSent();
    }

    // ---------- integrasi UI: impor domain → layanan CRM ----------

    public function test_import_creates_service_for_each_domain(): void
    {
        $this->login();
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS]);
        $client = ClientFactory::new()->create();
        $provider = DomainProviderFactory::new()->namesilo(['enrich_details' => false])->create();

        $this->post(route('domain-providers.import-services', $provider), [
            'client_id' => $client->id,
        ])->assertRedirect(route('domain-providers.domains', $provider))
            ->assertSessionHas('success');

        $this->assertSame(2, Service::count());

        $service = Service::where('reference', 'eskrimkekinian.com')->firstOrFail();
        $this->assertSame($client->id, $service->client_id);
        $this->assertSame(ServiceType::Domain, $service->type);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('2027-02-06', $service->end_date?->format('Y-m-d'));
        $this->assertTrue($service->reminder_enabled);
        $this->assertStringContainsString('NameSilo', (string) $service->notes);
    }

    public function test_import_is_idempotent(): void
    {
        $this->login();
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS]);
        $client = ClientFactory::new()->create();
        $provider = DomainProviderFactory::new()->namesilo(['enrich_details' => false])->create();

        $this->post(route('domain-providers.import-services', $provider), ['client_id' => $client->id]);
        $this->post(route('domain-providers.import-services', $provider), ['client_id' => $client->id]);

        // Jalankan kedua tidak boleh menduplikasi baris layanan.
        $this->assertSame(2, Service::count());
    }

    public function test_import_updates_expiry_of_existing_service(): void
    {
        $this->login();
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS]);
        $client = ClientFactory::new()->create();
        $provider = DomainProviderFactory::new()->namesilo(['enrich_details' => false])->create();

        $existing = Service::factory()->create([
            'client_id' => $client->id,
            'type' => ServiceType::Domain,
            'name' => 'Layanan Lama',
            'reference' => 'eskrimkekinian.com',
            'end_date' => '2020-01-01',
        ]);

        $this->post(route('domain-providers.import-services', $provider), ['client_id' => $client->id]);

        $existing->refresh();
        // Tanggal kedaluwarsa disegarkan dari NameSilo; nama manual tetap utuh.
        $this->assertSame('2027-02-06', $existing->end_date?->format('Y-m-d'));
        $this->assertSame('Layanan Lama', $existing->name);
        $this->assertSame(2, Service::count());
    }

    public function test_import_requires_client(): void
    {
        $this->login();
        $this->fakeNameSilo(['listDomains' => self::LIST_DOMAINS]);
        $provider = DomainProviderFactory::new()->namesilo(['enrich_details' => false])->create();

        $this->post(route('domain-providers.import-services', $provider), ['client_id' => null])
            ->assertSessionHasErrors('client_id');

        $this->assertSame(0, Service::count());
    }
}
