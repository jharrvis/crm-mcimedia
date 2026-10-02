<?php

namespace Tests\Feature\Providers;

use App\Domains\Providers\DomainProviderRegistry;
use App\Domains\Providers\Drivers\ManualDomainProviderDriver;
use App\Domains\Providers\Exceptions\UnknownDomainProviderDriver;
use App\Domains\Providers\Models\DomainProvider;
use App\Models\User;
use Database\Factories\DomainProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tes registry penyedia domain/hosting (F4-5):
 * CRUD + aktif/nonaktif provider, enkripsi kredensial, resolusi driver,
 * daftar domain via driver, dan ekstensibilitas (driver baru via config).
 */
class DomainProviderTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Fake API Hestia: hanya merespons v-list-web-domains. */
    private function fakeHestiaDomains(array $domains): void
    {
        Http::fake(function (Request $request) use ($domains) {
            $data = $request->data();

            if (($data['cmd'] ?? '') === 'v-list-web-domains') {
                return Http::response(json_encode($domains), 200);
            }

            return Http::response('[]', 200);
        });
    }

    // ---------- akses ----------

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('domain-providers.index'))->assertRedirect(route('login'));
    }

    // ---------- index ----------

    public function test_index_lists_providers_with_driver_label(): void
    {
        $this->login();
        DomainProviderFactory::new()->create(['name' => 'Registrar Manual']);
        DomainProviderFactory::new()->hestia()->create(['name' => 'Hestia Utama']);

        $this->get(route('domain-providers.index'))
            ->assertOk()
            ->assertSee('Registrar Manual')
            ->assertSee('Manual (tanpa API)')
            ->assertSee('Hestia Utama')
            ->assertSee('HestiaCP (hosting)');
    }

    public function test_index_filters_by_status(): void
    {
        $this->login();
        DomainProviderFactory::new()->create(['name' => 'Aktif Saja']);
        DomainProviderFactory::new()->inactive()->create(['name' => 'Nonaktif Saja']);

        $this->get(route('domain-providers.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Nonaktif Saja')
            ->assertDontSee('Aktif Saja');
    }

    // ---------- create form ----------

    public function test_create_page_renders_driver_credential_fields(): void
    {
        $this->login();

        $this->get(route('domain-providers.create', ['driver' => 'hestia']))
            ->assertOk()
            ->assertSee('HestiaCP (hosting)')
            ->assertSee('credentials[host]', false)
            ->assertSee('credentials[account]', false);

        $this->get(route('domain-providers.create', ['driver' => 'manual']))
            ->assertOk()
            ->assertSee('Daftar domain (JSON)')
            ->assertSee('credentials[domains_json]', false);
    }

    // ---------- store ----------

    public function test_store_creates_provider_with_encrypted_credentials(): void
    {
        $this->login();

        $this->post(route('domain-providers.store'), [
            'name' => 'Hestia Utama',
            'driver' => 'hestia',
            'notes' => 'Server utama',
            'credentials' => [
                'host' => 'panel.rahasia.test',
                'port' => 8083,
                'user' => 'admin',
                'password' => 'sangat-rahasia-xyz',
                'account' => 'mcimedia',
            ],
        ])->assertRedirect(route('domain-providers.index'));

        $provider = DomainProvider::firstOrFail();
        $this->assertSame('Hestia Utama', $provider->name);
        $this->assertSame('hestia', $provider->driver);
        $this->assertTrue($provider->is_active);
        $this->assertSame('sangat-rahasia-xyz', $provider->credentials['password']);
        $this->assertSame(8083, $provider->credentials['port']);
        $this->assertFalse($provider->credentials['verify_ssl']);

        // Di database mentah: terenkripsi (tidak ada teks kredensial).
        $raw = (string) DB::table('domain_providers')->value('credentials');
        $this->assertStringNotContainsString('sangat-rahasia-xyz', $raw);
        $this->assertStringNotContainsString('panel.rahasia.test', $raw);

        // Tidak ada teks rahasia di halaman mana pun.
        $this->get(route('domain-providers.index'))->assertDontSee('sangat-rahasia-xyz');
        $this->get(route('domain-providers.edit', $provider))->assertDontSee('sangat-rahasia-xyz');
    }

    public function test_store_requires_name_and_valid_driver(): void
    {
        $this->login();

        $this->post(route('domain-providers.store'), ['name' => '', 'driver' => 'tidak-ada'])
            ->assertSessionHasErrors(['name', 'driver']);
    }

    public function test_store_validates_driver_specific_required_fields(): void
    {
        $this->login();

        // Hestia tanpa host/user → field wajib dilaporkan (password opsional
        // bila memakai access/secret key, jadi tidak ikut diwajibkan di sini).
        $this->post(route('domain-providers.store'), [
            'name' => 'Hestia Kosong',
            'driver' => 'hestia',
            'credentials' => ['account' => 'mcimedia'],
        ])->assertSessionHasErrors(['credentials.host', 'credentials.user']);

        $this->assertDatabaseCount('domain_providers', 0);
    }

    public function test_hestia_without_auth_method_fails_gracefully_on_domains_page(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create([
            'driver' => 'hestia',
            'credentials' => [
                'host' => 'panel.test', 'port' => 8083, 'verify_ssl' => false,
                'user' => 'admin', 'account' => 'mcimedia',
            ],
        ]);

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('Gagal menarik domain dari provider');
    }

    public function test_store_rejects_invalid_json_for_textarea_field(): void
    {
        $this->login();

        $this->post(route('domain-providers.store'), [
            'name' => 'Manual Rusak',
            'driver' => 'manual',
            'credentials' => ['domains_json' => '{bukan json'],
        ])->assertSessionHasErrors(['credentials.domains_json']);
    }

    // ---------- update ----------

    public function test_update_keeps_existing_secret_when_left_blank(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->hestia()->create(['name' => 'Lama']);
        $provider->update(['credentials' => array_merge($provider->credentials, ['password' => 'password-lama'])]);

        $this->put(route('domain-providers.update', $provider), [
            'name' => 'Hestia Baru',
            'driver' => 'hestia',
            'is_active' => '1',
            'credentials' => [
                'host' => 'panel.baru.test',
                'port' => 8083,
                'user' => 'admin',
                'password' => '',
                'account' => 'mcimedia',
            ],
        ])->assertRedirect(route('domain-providers.index'));

        $provider->refresh();
        $this->assertSame('Hestia Baru', $provider->name);
        $this->assertSame('panel.baru.test', $provider->credentials['host']);
        $this->assertSame('password-lama', $provider->credentials['password'], 'Password kosong harus mempertahankan nilai lama.');
    }

    public function test_update_can_deactivate_provider(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create();

        $this->put(route('domain-providers.update', $provider), [
            'name' => $provider->name,
            'driver' => 'manual',
            'is_active' => '0',
            'credentials' => ['domains_json' => $provider->credentials['domains_json']],
        ])->assertRedirect(route('domain-providers.index'));

        $this->assertFalse($provider->refresh()->is_active);
    }

    // ---------- toggle & destroy ----------

    public function test_toggle_switches_active_state(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create();

        $this->patch(route('domain-providers.toggle', $provider))->assertRedirect();
        $this->assertFalse($provider->refresh()->is_active);

        $this->patch(route('domain-providers.toggle', $provider))->assertRedirect();
        $this->assertTrue($provider->refresh()->is_active);
    }

    public function test_destroy_deletes_provider(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create();

        $this->delete(route('domain-providers.destroy', $provider))
            ->assertRedirect(route('domain-providers.index'));

        $this->assertDatabaseCount('domain_providers', 0);
    }

    // ---------- daftar domain lewat driver ----------

    public function test_domains_page_lists_manual_domains_with_expiry(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create([
            'credentials' => [
                'domains_json' => json_encode([
                    ['domain' => 'contoh.com', 'expires_at' => now()->addDays(5)->toDateString()],
                    ['domain' => 'tanpa-tanggal.com'],
                ]),
            ],
        ]);

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('contoh.com')
            ->assertSee('tanpa-tanggal.com')
            ->assertSee('5 hari');

        $this->assertNotNull($provider->refresh()->last_used_at);
    }

    public function test_domains_page_shows_message_when_not_configured(): void
    {
        $this->login();
        $provider = DomainProviderFactory::new()->create([
            'driver' => 'manual',
            'credentials' => ['domains_json' => json_encode([])],
        ]);

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('Tidak ada domain ditemukan');
    }

    public function test_hestia_driver_lists_domains_and_expiry_from_api(): void
    {
        $this->login();
        $this->fakeHestiaDomains([
            'contoh.com' => ['IP' => '10.0.0.1', 'SUSPENDED' => 'no', 'EXPIRY' => '2027-03-01'],
            'suspended.com' => ['SUSPENDED' => 'yes'],
        ]);

        $provider = DomainProviderFactory::new()->hestia()->create();

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('contoh.com')
            ->assertSee('01/03/2027')   // dari getExpiry/listDomains
            ->assertSee('suspended.com')
            ->assertSee('Suspended');
    }

    // ---------- registry / ekstensibilitas ----------

    public function test_registry_exposes_builtin_drivers(): void
    {
        $registry = new DomainProviderRegistry;

        $this->assertTrue($registry->has('hestia'));
        $this->assertTrue($registry->has('manual'));
        $this->assertSame('HestiaCP (hosting)', $registry->label('hestia'));
        $this->assertArrayHasKey('manual', $registry->options());
    }

    public function test_registry_rejects_unknown_driver_key(): void
    {
        $this->expectException(UnknownDomainProviderDriver::class);

        (new DomainProviderRegistry)->resolve('tidak-ada');
    }

    public function test_provider_resolves_to_its_driver_instance(): void
    {
        $provider = DomainProviderFactory::new()->create();

        $this->assertInstanceOf(
            ManualDomainProviderDriver::class,
            $provider->driver(),
        );
        $this->assertSame('manual', $provider->driver()->provider()->driver);
    }

    /**
     * Ekstensibilitas: driver baru cukup didaftarkan lewat config — provider
     * dibuat & dipakai TANPA mengubah skema `domain_providers`.
     */
    public function test_custom_driver_works_via_config_without_schema_change(): void
    {
        config(['crm.domain_providers.drivers' => [FakeDomainProviderDriver::class]]);

        $registry = new DomainProviderRegistry;
        $this->assertTrue($registry->has('fake'));
        $this->assertSame('Fake Provider', $registry->label('fake'));
        $this->assertSame(['token'], array_keys($registry->credentialFields('fake')));

        $provider = DomainProvider::create([
            'name' => 'Fake Provider',
            'driver' => 'fake',
            'credentials' => ['token' => 'token-123'],
            'is_active' => true,
        ]);

        $driver = $provider->driver();
        $this->assertInstanceOf(FakeDomainProviderDriver::class, $driver);
        $this->assertSame('token-123', $driver->receivedToken());
        $this->assertSame('fake-domain.test', $driver->listDomains()[0]->domain);
        $this->assertNotNull($driver->getExpiry('fake-domain.test'));
        $this->assertNull($driver->getExpiry('domain-lain.test'));
    }

    public function test_custom_driver_is_usable_through_the_ui(): void
    {
        config(['crm.domain_providers.drivers' => [FakeDomainProviderDriver::class]]);
        $this->login();

        $provider = DomainProvider::create([
            'name' => 'Fake Provider',
            'driver' => 'fake',
            'credentials' => ['token' => 'token-123'],
            'is_active' => true,
        ]);

        $this->get(route('domain-providers.index'))
            ->assertOk()
            ->assertSee('Fake Provider');

        $this->get(route('domain-providers.domains', $provider))
            ->assertOk()
            ->assertSee('fake-domain.test');
    }
}
