<?php

namespace Tests\Feature\Hestia;

use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Services\Models\Service;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Seeders\HestiaServerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * Tes multi-server HestiaCP (F4-12).
 *
 * Fokus: (1) CRUD server lewat UI, (2) kredensial terenkripsi & tidak bocor,
 * (3) sync per server benar-benar terisolasi satu sama lain, (4) kompatibilitas
 * mundur dengan path environment F3-1, (5) sifat read-only tetap berlaku.
 */
class HestiaMultiServerTest extends TestCase
{
    use InteractsWithHestiaServers;
    use RefreshDatabase;

    /** Password environment path F3-1 (dummy untuk tes). */
    protected string $hestiaPassword = 'rahasia-env-test-123';

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    // ============================================================
    // 1. Migrasi & seeder
    // ============================================================

    public function test_seeder_creates_three_active_servers_with_netdata_but_no_secrets(): void
    {
        $this->seed(HestiaServerSeeder::class);

        $this->assertDatabaseCount('hestia_servers', 3);
        $this->assertDatabaseHas('hestia_servers', ['code' => 'sg2', 'name' => 'sg2', 'is_active' => true, 'netdata_host' => '100.119.156.82', 'netdata_port' => 19999]);
        $this->assertDatabaseHas('hestia_servers', ['code' => 'yiari', 'name' => 'YIARI', 'is_active' => true, 'netdata_host' => '100.114.35.33', 'netdata_port' => 19999]);
        $this->assertDatabaseHas('hestia_servers', ['code' => 'pa-salatiga', 'name' => 'PA Salatiga', 'is_active' => true, 'netdata_host' => '100.97.142.93', 'netdata_port' => 19999]);

        // Tidak ada kredensial panel yang dikarang di seeder.
        foreach (HestiaServer::all() as $server) {
            $this->assertEmpty($server->credentialBag());
            $this->assertFalse($server->isConfigured());
        }
    }

    public function test_seeder_is_non_destructive_when_rerun(): void
    {
        $this->seed(HestiaServerSeeder::class);

        // Admin mengisi & mengaktifkan sg2.
        $sg2 = HestiaServer::where('code', 'sg2')->firstOrFail();
        $sg2->update([
            'host' => 'panel.sg2.co.id',
            'is_active' => true,
            'credentials' => ['user' => 'admin', 'password' => $this->serverPassword],
        ]);

        $this->seed(HestiaServerSeeder::class);

        $sg2->refresh();
        $this->assertTrue($sg2->is_active);
        $this->assertSame('panel.sg2.co.id', $sg2->host);
        $this->assertSame('admin', $sg2->credentialBag()['user']);
        $this->assertDatabaseCount('hestia_servers', 3);
    }

    // ============================================================
    // 2. CRUD server lewat UI
    // ============================================================

    public function test_guest_cannot_manage_servers(): void
    {
        $this->get(route('hestia.servers.index'))->assertRedirect(route('login'));
        $this->get(route('hestia.servers.create'))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_server_through_the_form(): void
    {
        $this->login();

        $this->post(route('hestia.servers.store'), [
            'name' => 'sg2',
            'host' => 'panel.sg2.co.id',
            'port' => 8083,
            'scheme' => 'https',
            'timeout' => 30,
            'is_active' => '1',
            'credentials' => ['access_key' => 'AK-1', 'secret_key' => 'SK-1'],
        ])->assertRedirect(route('hestia.servers.index'))
            ->assertSessionHas('success');

        $server = HestiaServer::firstOrFail();
        $this->assertSame('sg2', $server->code); // dibuat otomatis dari nama
        $this->assertSame('panel.sg2.co.id', $server->host);
        $this->assertSame('AK-1', $server->credentialBag()['access_key']);
        $this->assertTrue($server->isConfigured());
    }

    public function test_store_rejects_missing_host_and_duplicate_code(): void
    {
        $this->login();
        $this->makeServer('sg2', 'sg2');

        $this->post(route('hestia.servers.store'), ['name' => 'Duplikat', 'code' => 'sg2'])
            ->assertSessionHasErrors(['host', 'code']);

        $this->post(route('hestia.servers.store'), ['name' => 'Tanpa Host', 'code' => 'baru'])
            ->assertSessionHasErrors('host');

        $this->assertDatabaseCount('hestia_servers', 1);
    }

    public function test_admin_can_update_server_and_blank_secret_keeps_old_value(): void
    {
        $this->login();
        $server = $this->makeServer('sg2', 'sg2');

        // Kredensial sengaja DIKOSONGKAN saat edit → nilai lama harus bertahan.
        $this->put(route('hestia.servers.update', $server), [
            'name' => 'sg2 (sgp)',
            'code' => 'sg2',
            'host' => 'panel2.sg2.co.id',
            'credentials' => ['access_key' => '', 'secret_key' => ''],
        ])->assertRedirect(route('hestia.servers.index'))
            ->assertSessionHas('success');

        $server->refresh();
        $this->assertSame('sg2 (sgp)', $server->name);
        $this->assertSame('panel2.sg2.co.id', $server->host);
        $this->assertSame('admin', $server->credentialBag()['user']);
        $this->assertSame($this->serverPassword, $server->credentialBag()['password']);
    }

    public function test_server_can_be_deleted_without_deleting_its_accounts(): void
    {
        $this->login();
        $server = $this->makeServer('sg2', 'sg2');

        HestiaAccount::create([
            'external_key' => HestiaAccount::keyFor('u', 'a.com', 'sg2'),
            'hestia_server_id' => $server->id,
            'hestia_user' => 'u',
            'domain' => 'a.com',
            'status' => 'active',
            'mapping_status' => 'unmapped',
        ]);

        $this->delete(route('hestia.servers.destroy', $server))
            ->assertRedirect(route('hestia.servers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('hestia_servers', ['id' => $server->id]);
        // Akun TIDAK ikut terhapus — hanya kehilangan sumbernya (prinsip F3-1).
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'a.com']);
    }

    // ============================================================
    // 3. Keamanan kredensial
    // ============================================================

    public function test_credentials_are_encrypted_at_rest_and_never_rendered(): void
    {
        $this->login();
        $server = $this->makeServer('sg2', 'sg2');

        // Ciphertext di database tidak memuat nilai asli.
        $raw = DB::table('hestia_servers')->where('id', $server->id)->value('credentials');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($this->serverPassword, $raw);
        $this->assertStringNotContainsString('admin', $raw);

        // Halaman edit & detail tidak pernah mengirim nilai rahasia ke browser.
        foreach ([route('hestia.servers.edit', $server), route('hestia.servers.show', $server), route('hestia.servers.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee($this->serverPassword)
                ->assertDontSee(e($this->serverPassword));
        }
    }

    public function test_credentials_never_reach_logs_or_sync_logs(): void
    {
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');
        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['a.com' => $this->domainPayload()]]);

        $handler = new TestHandler;
        $this->app->instance('log', new Logger('hestia-multi', [$handler]));
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync', ['--server' => 'sg2'])->assertSuccessful();

        $this->assertNotEmpty($handler->getRecords());
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString($this->serverPassword, (string) $record['message']);
            $this->assertStringNotContainsString($this->serverPassword, (string) json_encode($record['context']));
        }

        $this->assertStringNotContainsString($this->serverPassword, json_encode(HestiaSyncLog::all()->toArray()));
    }

    public function test_global_kill_switch_blocks_managed_servers_too(): void
    {
        $this->configureHestiaEnvironment(false); // HESTIA_ENABLED=false
        $server = $this->makeServer('sg2', 'sg2');
        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['a.com' => $this->domainPayload()]]);

        Http::fake();
        $this->artisan('hestia:sync')->assertFailed();

        Http::assertNothingSent();
        $this->assertDatabaseCount('hestia_accounts', 0);
        $this->assertSame('failed', HestiaSyncLog::orderByDesc('id')->firstOrFail()->status);
    }

    // ============================================================
    // 4. Isolasi sync antar-server (regresi KRITIS)
    // ============================================================

    public function test_sync_is_isolated_per_server_and_does_not_cross_deactivate(): void
    {
        $this->configureHestiaEnvironment();
        $sg2 = $this->makeServer('sg2', 'sg2');
        $yiari = $this->makeServer('YIARI', 'yiari');
        ClientFactory::new()->create(['email' => 'admin@sg2domain.com']);

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['sg2domain.com' => $this->domainPayload()]]);
        $this->fakeHestiaHost('yiari.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['yiari-domain.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync')->assertSuccessful();

        $this->assertDatabaseCount('hestia_accounts', 2);
        $this->assertSame($sg2->id, HestiaAccount::where('domain', 'sg2domain.com')->value('hestia_server_id'));
        $this->assertSame($yiari->id, HestiaAccount::where('domain', 'yiari-domain.com')->value('hestia_server_id'));

        // Kunci eksternal ter-scope per server.
        $this->assertSame(
            'srv:sg2:dom:u:sg2domain.com',
            HestiaAccount::where('domain', 'sg2domain.com')->value('external_key'),
        );

        // REGRESI: sync server sg2 SAJA. Domain YIARI tidak ada di sg2, tapi
        // akun YIARI harus TETAP aktif — inilah bug yang dicegah scope per server.
        $this->artisan('hestia:sync', ['--server' => 'sg2'])->assertSuccessful();

        $this->assertSame('active', HestiaAccount::where('domain', 'yiari-domain.com')->value('status')->value);
        $this->assertSame('active', HestiaAccount::where('domain', 'sg2domain.com')->value('status')->value);
    }

    public function test_missing_domain_only_deactivates_within_its_own_server(): void
    {
        $this->configureHestiaEnvironment();
        $sg2 = $this->makeServer('sg2', 'sg2');
        $yiari = $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], [
            'u' => ['a.com' => $this->domainPayload(), 'b.com' => $this->domainPayload()],
        ]);
        $this->fakeHestiaHost('yiari.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['c.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync')->assertSuccessful();

        // Sync ulang sg2 tanpa b.com → hanya b.com (sg2) yang nonaktif.
        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['a.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync', ['--server' => 'sg2'])->assertSuccessful();

        $this->assertSame('inactive', HestiaAccount::where('domain', 'b.com')->value('status')->value);
        $this->assertSame('active', HestiaAccount::where('domain', 'a.com')->value('status')->value);
        $this->assertSame('active', HestiaAccount::where('domain', 'c.com')->value('status')->value);
        $this->assertSame($sg2->id, HestiaAccount::where('domain', 'b.com')->value('hestia_server_id'));
    }

    public function test_same_domain_and_username_on_two_servers_stay_separate(): void
    {
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');
        $this->makeServer('YIARI', 'yiari');

        // Domain & user identik di kedua server.
        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['duplikat.com' => $this->domainPayload()]]);
        $this->fakeHestiaHost('yiari.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['duplikat.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync')->assertSuccessful();

        // Dua akun terpisah, bukan satu yang saling menimpa.
        $this->assertSame(2, HestiaAccount::where('domain', 'duplikat.com')->count());
        $this->assertSame(2, HestiaAccount::distinct()->count('external_key'));
    }

    public function test_resync_each_server_is_idempotent(): void
    {
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');
        $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->fakeHestiaHost('yiari.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['y.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync')->assertSuccessful();
        $this->assertDatabaseCount('hestia_accounts', 2);

        $this->artisan('hestia:sync')->assertSuccessful();

        $this->assertDatabaseCount('hestia_accounts', 2);
        $second = HestiaSyncLog::orderByDesc('id')->limit(2)->get();
        foreach ($second as $log) {
            $this->assertSame(0, $log->created);
            $this->assertSame(1, $log->updated);
        }
    }

    public function test_inactive_server_is_skipped_by_bulk_sync(): void
    {
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');
        $this->makeServer('YIARI', 'yiari', ['is_active' => false]);

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->fakeHestiaHost('yiari.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['y.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync')->assertSuccessful();

        $this->assertDatabaseCount('hestia_accounts', 1);
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 's.com']);
        $this->assertDatabaseMissing('hestia_accounts', ['domain' => 'y.com']);
    }

    public function test_one_failing_server_does_not_stop_the_others(): void
    {
        $this->configureHestiaEnvironment();
        $sg2 = $this->makeServer('sg2', 'sg2');
        $yiari = $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->failHestiaHost('yiari.test');
        $this->installHestiaHttpFake();

        // Command keluar FAILURE karena ada satu server gagal...
        $this->artisan('hestia:sync')->assertFailed();

        // ...tapi server sg2 tetap tersinkron.
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 's.com', 'hestia_server_id' => $sg2->id]);
        $this->assertSame('failed', HestiaSyncLog::where('hestia_server_id', $yiari->id)->value('status'));
        $this->assertSame('success', HestiaSyncLog::where('hestia_server_id', $sg2->id)->value('status'));
    }

    public function test_sync_records_last_run_on_server(): void
    {
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');
        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync', ['--server' => 'sg2'])->assertSuccessful();

        $server->refresh();
        $this->assertSame(HestiaServer::STATUS_SUCCESS, $server->last_sync_status);
        $this->assertNotNull($server->last_sync_at);
        $this->assertNotNull($server->last_synced_at);
    }

    // ============================================================
    // 5. Kompatibilitas mundur dengan path environment (F3-1)
    // ============================================================

    public function test_without_managed_servers_sync_falls_back_to_environment(): void
    {
        $this->configureHestiaEnvironment();
        ClientFactory::new()->create(['email' => 'admin@env-domain.com']);
        $this->fakeHestiaHost('env.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['env-domain.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->assertDatabaseCount('hestia_servers', 0);
        $this->artisan('hestia:sync')->assertSuccessful();

        $account = HestiaAccount::where('domain', 'env-domain.com')->firstOrFail();
        $this->assertNull($account->hestia_server_id);
        // Kunci tetap format lama F3-1 (tanpa prefix srv:) agar data existing kompatibel.
        $this->assertSame('dom:u:env-domain.com', $account->external_key);
    }

    public function test_managed_servers_take_precedence_over_environment(): void
    {
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->fakeHestiaHost('env.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['env-domain.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync')->assertSuccessful();

        // Hanya server terkelola yang diproses; env tidak ikut agar tidak dobel.
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 's.com']);
        $this->assertDatabaseMissing('hestia_accounts', ['domain' => 'env-domain.com']);
    }

    public function test_environment_sync_does_not_touch_managed_server_accounts(): void
    {
        $this->configureHestiaEnvironment();
        $sg2 = $this->makeServer('sg2', 'sg2');

        HestiaAccount::create([
            'external_key' => HestiaAccount::keyFor('u', 's.com', 'sg2'),
            'hestia_server_id' => $sg2->id,
            'hestia_user' => 'u',
            'domain' => 's.com',
            'status' => 'active',
            'mapping_status' => 'unmapped',
        ]);

        $this->fakeHestiaHost('env.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['env-domain.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        // Sync env eksplisit tidak boleh menonaktifkan akun server sg2.
        $this->artisan('hestia:sync --server=env-only')->assertFailed();

        $this->assertSame('active', HestiaAccount::where('domain', 's.com')->value('status')->value);
    }

    // ============================================================
    // 6. CLI & read-only
    // ============================================================

    public function test_sync_with_server_option_reports_unknown_server(): void
    {
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync', ['--server' => 'tidak-ada'])
            ->expectsOutputToContain('tidak ditemukan')
            ->assertFailed();
    }

    public function test_list_option_shows_registered_servers(): void
    {
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');

        $this->artisan('hestia:sync', ['--list' => true])
            ->expectsOutputToContain('sg2')
            ->assertSuccessful();
    }

    public function test_only_read_only_commands_are_sent_for_managed_servers(): void
    {
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');
        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync', ['--server' => 'sg2'])->assertSuccessful();

        $this->assertNotEmpty(Http::recorded());
        foreach (Http::recorded() as [$request, $response]) {
            $this->assertStringStartsWith('v-list', (string) ($request->data()['cmd'] ?? ''));
            $this->assertStringNotContainsString($this->serverPassword, $request->url());
        }
    }

    public function test_scheduler_still_runs_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('hestia:sync')
            ->assertSuccessful();
    }

    // ============================================================
    // 7. UI: filter server, uji koneksi, tombol sinkron
    // ============================================================

    public function test_index_can_filter_accounts_by_server(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();

        $sg2 = $this->makeServer('sg2', 'sg2');
        $yiari = $this->makeServer('YIARI', 'yiari');

        foreach ([[$sg2, 's.com'], [$yiari, 'y.com']] as [$server, $domain]) {
            HestiaAccount::create([
                'external_key' => HestiaAccount::keyFor('u', $domain, $server->code),
                'hestia_server_id' => $server->id,
                'hestia_user' => 'u',
                'domain' => $domain,
                'status' => 'active',
                'mapping_status' => 'unmapped',
            ]);
        }

        $this->get(route('hestia.index'))
            ->assertOk()
            ->assertSee('s.com')
            ->assertSee('y.com');

        $this->get(route('hestia.index', ['server' => 'srv:'.$sg2->id]))
            ->assertOk()
            ->assertSee('s.com')
            ->assertDontSee('y.com');
    }

    public function test_index_ignores_malformed_server_filter(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();

        $this->get(route('hestia.index', ['server' => 'srv:999999']))
            ->assertOk();
        $this->get(route('hestia.index', ['server' => '<script>']))
            ->assertOk();
    }

    public function test_test_connection_button_reports_success_and_failure(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();

        $ok = $this->makeServer('sg2', 'sg2');
        $bad = $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => []]);
        $this->failHestiaHost('yiari.test');
        $this->installHestiaHttpFake();

        $this->post(route('hestia.servers.test', $ok))
            ->assertRedirect(route('hestia.servers.index'))
            ->assertSessionHas('success');

        $this->post(route('hestia.servers.test', $bad))
            ->assertRedirect(route('hestia.servers.index'))
            ->assertSessionHas('error');
    }

    public function test_test_connection_is_skipped_without_credentials(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = HestiaServer::create([
            'name' => 'Kosong', 'code' => 'kosong', 'host' => 'kosong.test', 'is_active' => true,
        ]);

        Http::fake();
        $this->post(route('hestia.servers.test', $server))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_sync_single_server_button_from_server_page(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');
        ClientFactory::new()->create(['email' => 'admin@btn.com']);

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['btn.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->post(route('hestia.servers.sync', $server))
            ->assertRedirect(route('hestia.servers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'btn.com', 'hestia_server_id' => $server->id]);
    }

    public function test_bulk_sync_button_syncs_every_active_server(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');
        $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->fakeHestiaHost('yiari.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['y.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->post(route('hestia.sync'))
            ->assertRedirect(route('hestia.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('hestia_accounts', 2);
    }

    public function test_bulk_sync_button_reports_partial_failure(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $this->makeServer('sg2', 'sg2');
        $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['s.com' => $this->domainPayload()]]);
        $this->failHestiaHost('yiari.test');
        $this->installHestiaHttpFake();

        $this->post(route('hestia.sync'))
            ->assertRedirect(route('hestia.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('hestia_accounts', ['domain' => 's.com']);
    }

    // ============================================================
    // 8. Integritas data
    // ============================================================

    public function test_mapped_accounts_keep_working_across_servers(): void
    {
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');
        ClientFactory::new()->create(['email' => 'admin@layanan.com']);

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['layanan.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync')->assertSuccessful();

        $account = HestiaAccount::where('domain', 'layanan.com')->firstOrFail();
        $this->assertSame($server->id, $account->hestia_server_id);
        $this->assertNotNull($account->service_id);
        $this->assertSame(1, Service::where('reference', 'layanan.com')->count());
    }

    public function test_key_for_scopes_by_server_code_case_insensitively(): void
    {
        // keyFor() menormalisasi ke huruf kecil agar kunci stabil & idempotent.
        $this->assertSame('dom:u:domain.com', HestiaAccount::keyFor('U', 'Domain.COM'));
        $this->assertSame('srv:sg2:dom:u:d.com', HestiaAccount::keyFor('u', 'd.com', 'SG2'));
        $this->assertSame('dom:u:d.com', HestiaAccount::keyFor('u', 'd.com', ''));
        $this->assertSame('dom:u:d.com', HestiaAccount::keyFor('u', 'd.com', null));
    }
}
