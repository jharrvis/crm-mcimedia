<?php

namespace Tests\Feature\Hestia;

use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncBatch;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Hestia\Services\HestiaBatchSyncService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tes sinkronisasi HestiaCP BERTAHAP per batch (t_dcccffd9).
 *
 * Fokus: (1) sesi start/batch berjalan lewat AJAX dengan progress yang benar,
 * (2) batch terakhir menonaktifkan akun hilang — hanya untuk server terkait,
 * (3) satu akun gagal tidak menghentikan sisanya dan TIDAK memicu penonaktifan,
 * (4) guard keamanan: kill switch, kredensial kosong, sesi milik server lain,
 * (5) retry request yang selesai bersifat idempotent.
 */
class HestiaBatchSyncTest extends TestCase
{
    use InteractsWithHestiaServers;
    use RefreshDatabase;

    /** Password environment path F3-1 (dummy untuk tes) — dipakai trait. */
    protected string $hestiaPassword = 'rahasia-env-batch-test-123';

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * Daftar user Hestia palsu: user1..userN.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fixtureUsers(int $count, string $prefix = 'user'): array
    {
        $users = [];
        for ($i = 1; $i <= $count; $i++) {
            $users[$prefix.$i] = ['PACKAGE' => 'pro'];
        }

        return $users;
    }

    /**
     * Satu domain web per user.
     *
     * @param  array<string, mixed>  $users
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function fixtureDomains(array $users, string $prefix = 'site'): array
    {
        $domains = [];
        $i = 0;

        foreach (array_keys($users) as $user) {
            $i++;
            $domains[$user] = [$prefix.$i.'.com' => $this->domainPayload()];
        }

        return $domains;
    }

    // ============================================================
    // 1. Alur start → batch → selesai
    // ============================================================

    public function test_start_creates_session_with_total_accounts_and_running_log(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        $users = $this->fixtureUsers(5);
        $this->fakeHestiaHost('sg2.test', $users, $this->fixtureDomains($users));
        $this->installHestiaHttpFake();

        $this->postJson(route('hestia.servers.sync.start', $server))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('batch.total_users', 5)
            ->assertJsonPath('batch.processed_users', 0)
            ->assertJsonPath('batch.percent', 0)
            ->assertJsonPath('batch.done', false)
            ->assertJsonPath('batch.status', 'running')
            ->assertJsonPath('batch.batch_size', 10)
            ->assertJsonPath('batch.server.name', 'sg2');

        $this->assertDatabaseHas('hestia_sync_batches', [
            'hestia_server_id' => $server->id,
            'status' => 'running',
            'total_users' => 5,
            'next_offset' => 0,
            'processed_users' => 0,
        ]);
        $this->assertDatabaseHas('hestia_sync_logs', [
            'hestia_server_id' => $server->id,
            'status' => 'running',
        ]);

        // Daftar user ditarik SEKALI di awal; belum ada domain yang ditarik.
        Http::assertSentCount(1);
        $this->assertDatabaseCount('hestia_accounts', 0);
    }

    public function test_batches_advance_progress_and_final_batch_closes_session(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        $users = $this->fixtureUsers(25);
        $this->fakeHestiaHost('sg2.test', $users, $this->fixtureDomains($users));
        $this->installHestiaHttpFake();

        $batchId = $this->postJson(route('hestia.servers.sync.start', $server), ['batch_size' => 10])
            ->assertOk()
            ->json('batch.id');

        $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId])
            ->assertOk()
            ->assertJsonPath('batch.processed_users', 10)
            ->assertJsonPath('batch.percent', 40)
            ->assertJsonPath('batch.done', false);
        $this->assertDatabaseCount('hestia_accounts', 10);

        $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId])
            ->assertOk()
            ->assertJsonPath('batch.processed_users', 20)
            ->assertJsonPath('batch.percent', 80)
            ->assertJsonPath('batch.done', false);
        $this->assertDatabaseCount('hestia_accounts', 20);

        $final = $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId])
            ->assertOk()
            ->assertJsonPath('batch.processed_users', 25)
            ->assertJsonPath('batch.percent', 100)
            ->assertJsonPath('batch.done', true)
            ->assertJsonPath('batch.status', 'success')
            ->assertJsonPath('batch.totals.pulled', 25)
            ->assertJsonPath('batch.totals.created', 25)
            ->assertJsonPath('batch.totals.failed_users', 0);

        $this->assertStringContainsString('25 akun tersinkron', (string) $final->json('batch.message'));
        $this->assertDatabaseCount('hestia_accounts', 25);
        $this->assertDatabaseHas('hestia_sync_batches', [
            'id' => $batchId,
            'status' => 'success',
            'processed_users' => 25,
            'next_offset' => 25,
        ]);

        $log = HestiaSyncLog::where('hestia_server_id', $server->id)->latest('id')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertSame(25, $log->pulled);
        $this->assertNotNull($log->finished_at);

        $server->refresh();
        $this->assertSame(HestiaServer::STATUS_SUCCESS, $server->last_sync_status);
        $this->assertNotNull($server->last_sync_at);

        // Sifat read-only tetap terjaga: hanya perintah v-list* yang dikirim.
        foreach (Http::recorded() as [$request, $response]) {
            $this->assertStringStartsWith('v-list', (string) ($request->data()['cmd'] ?? ''));
        }
    }

    public function test_repeating_a_finished_batch_returns_final_state_without_reprocessing(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        $users = $this->fixtureUsers(1);
        $this->fakeHestiaHost('sg2.test', $users, $this->fixtureDomains($users));
        $this->installHestiaHttpFake();

        $batchId = $this->postJson(route('hestia.servers.sync.start', $server))->json('batch.id');
        $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId])
            ->assertOk()
            ->assertJsonPath('batch.done', true);

        // Request ulang (mis. response hilang / user menekan ulang) tidak
        // menggandakan pekerjaan: state akhir dikembalikan apa adanya.
        $again = $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId])
            ->assertOk()
            ->assertJsonPath('batch.done', true)
            ->assertJsonPath('batch.totals.pulled', 1);

        $this->assertSame('success', $again->json('batch.status'));
        $this->assertDatabaseCount('hestia_accounts', 1);
    }

    public function test_batch_size_from_request_is_clamped_to_safe_maximum(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();

        $big = $this->makeServer('sg2', 'sg2');
        $normal = $this->makeServer('YIARI', 'yiari');
        $users = $this->fixtureUsers(3);

        $this->fakeHestiaHost('sg2.test', $users, $this->fixtureDomains($users, 'big'));
        $this->fakeHestiaHost('yiari.test', $users, $this->fixtureDomains($users, 'normal'));
        $this->installHestiaHttpFake();

        $this->postJson(route('hestia.servers.sync.start', $big), ['batch_size' => 999])
            ->assertOk()
            ->assertJsonPath('batch.batch_size', HestiaBatchSyncService::MAX_BATCH_SIZE);

        $this->postJson(route('hestia.servers.sync.start', $normal))
            ->assertOk()
            ->assertJsonPath('batch.batch_size', 10);
    }

    public function test_server_without_accounts_finishes_immediately(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        $this->fakeHestiaHost('sg2.test', [], []);
        $this->installHestiaHttpFake();

        $batchId = $this->postJson(route('hestia.servers.sync.start', $server))
            ->assertOk()
            ->assertJsonPath('batch.total_users', 0)
            ->json('batch.id');

        $final = $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId])
            ->assertOk()
            ->assertJsonPath('batch.done', true)
            ->assertJsonPath('batch.status', 'success');

        $this->assertStringContainsString('tidak ada akun Hestia', (string) $final->json('batch.message'));
        $this->assertDatabaseCount('hestia_accounts', 0);
    }

    // ============================================================
    // 2. Isolasi & integritas data
    // ============================================================

    public function test_final_batch_deactivates_missing_accounts_only_for_this_server(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $sg2 = $this->makeServer('sg2', 'sg2');
        $yiari = $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], [
            'u' => ['a.com' => $this->domainPayload(), 'b.com' => $this->domainPayload()],
        ]);
        $this->installHestiaHttpFake();

        $this->artisan('hestia:sync', ['--server' => 'sg2'])->assertSuccessful();

        // Akun server kedua — tidak boleh tersentuh oleh batch sync sg2.
        HestiaAccount::create([
            'external_key' => HestiaAccount::keyFor('u', 'y.com', 'yiari'),
            'hestia_server_id' => $yiari->id,
            'hestia_user' => 'u',
            'domain' => 'y.com',
            'status' => 'active',
            'mapping_status' => 'unmapped',
        ]);

        // b.com hilang dari Hestia sg2.
        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], [
            'u' => ['a.com' => $this->domainPayload()],
        ]);
        $this->installHestiaHttpFake();

        $batchId = $this->postJson(route('hestia.servers.sync.start', $sg2))->json('batch.id');
        $final = $this->postJson(route('hestia.servers.sync.batch', $sg2), ['batch_id' => $batchId]);

        $final->assertOk()
            ->assertJsonPath('batch.done', true)
            ->assertJsonPath('batch.status', 'success')
            ->assertJsonPath('batch.totals.deactivated', 1);

        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'b.com', 'status' => 'inactive']);
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'a.com', 'status' => 'active']);
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'y.com', 'status' => 'active']);
    }

    public function test_account_failure_is_recorded_and_deactivation_is_skipped(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        // Akun lama yang tidak akan terlihat lagi pada sync ini — TAPI karena
        // ada akun gagal ditarik, penonaktifan harus dilewati (daftar seen tidak
        // lengkap tidak boleh mematikan akun yang sebenarnya masih ada).
        HestiaAccount::create([
            'external_key' => HestiaAccount::keyFor('u2', 'lama.com', 'sg2'),
            'hestia_server_id' => $server->id,
            'hestia_user' => 'u2',
            'domain' => 'lama.com',
            'status' => 'active',
            'mapping_status' => 'unmapped',
        ]);

        $users = ['u1' => ['PACKAGE' => 'pro'], 'u2' => ['PACKAGE' => 'pro'], 'u3' => ['PACKAGE' => 'pro']];
        $domains = [
            'u1' => ['satu.com' => $this->domainPayload()],
            'u3' => ['tiga.com' => $this->domainPayload()],
        ];

        Http::fake(function (Request $request) use ($users, $domains) {
            $data = $request->data();
            $cmd = $data['cmd'] ?? '';

            if ($cmd === 'v-list-users') {
                return Http::response(json_encode($users), 200);
            }

            if ($cmd === 'v-list-web-domains') {
                $user = $data['arg1'] ?? '';

                // Kegagalan per akun: hanya u2 yang gagal.
                return $user === 'u2'
                    ? Http::response('server sibuk', 500)
                    : Http::response(json_encode($domains[$user] ?? []), 200);
            }

            return Http::response('perintah tidak didukung: '.$cmd, 500);
        });

        $batchId = $this->postJson(route('hestia.servers.sync.start', $server))->json('batch.id');
        $final = $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId]);

        $final->assertOk()
            ->assertJsonPath('batch.done', true)
            ->assertJsonPath('batch.status', 'success')
            ->assertJsonPath('batch.totals.failed_users', 1)
            ->assertJsonPath('batch.totals.deactivated', 0)
            ->assertJsonPath('batch.errors.0.user', 'u2');

        $message = (string) $final->json('batch.message');
        $this->assertStringContainsString('1 dari 3 akun gagal ditarik', $message);
        $this->assertStringContainsString('Penonaktifan akun yang hilang dilewati', $message);

        // Akun yang berhasil ditarik tetap masuk; akun lama tetap aktif.
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'satu.com']);
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'tiga.com']);
        $this->assertDatabaseHas('hestia_accounts', ['domain' => 'lama.com', 'status' => 'active']);
    }

    public function test_all_accounts_failing_marks_session_log_and_server_failed(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        $users = ['u1' => ['PACKAGE' => 'pro'], 'u2' => ['PACKAGE' => 'pro']];

        Http::fake(function (Request $request) use ($users) {
            $data = $request->data();
            $cmd = $data['cmd'] ?? '';

            if ($cmd === 'v-list-users') {
                return Http::response(json_encode($users), 200);
            }

            // Semua tarikan domain gagal (mis. API tiba-tiba mati).
            return Http::response('down', 500);
        });

        $batchId = $this->postJson(route('hestia.servers.sync.start', $server))->json('batch.id');
        $final = $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => $batchId]);

        $final->assertOk()
            ->assertJsonPath('batch.done', true)
            ->assertJsonPath('batch.status', 'failed')
            ->assertJsonPath('batch.totals.failed_users', 2);

        $this->assertStringContainsString('Sinkronisasi gagal', (string) $final->json('batch.message'));
        $this->assertDatabaseHas('hestia_sync_batches', ['id' => $batchId, 'status' => 'failed']);

        $log = HestiaSyncLog::where('hestia_server_id', $server->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertNotNull($log->finished_at);

        $server->refresh();
        $this->assertSame(HestiaServer::STATUS_FAILED, $server->last_sync_status);
        $this->assertDatabaseCount('hestia_accounts', 0);
    }

    // ============================================================
    // 3. Guard keamanan & sesi
    // ============================================================

    public function test_batch_endpoint_rejects_unknown_and_foreign_sessions(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $sg2 = $this->makeServer('sg2', 'sg2');
        $yiari = $this->makeServer('YIARI', 'yiari');

        $this->fakeHestiaHost('sg2.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['a.com' => $this->domainPayload()]]);
        $this->fakeHestiaHost('yiari.test', ['u' => ['PACKAGE' => 'pro']], ['u' => ['y.com' => $this->domainPayload()]]);
        $this->installHestiaHttpFake();

        $batchId = $this->postJson(route('hestia.servers.sync.start', $sg2))->json('batch.id');

        // Sesi milik sg2 tidak boleh dipakai lewat URL server yiari.
        $this->postJson(route('hestia.servers.sync.batch', $yiari), ['batch_id' => $batchId])
            ->assertStatus(404)
            ->assertJsonPath('ok', false);

        $this->postJson(route('hestia.servers.sync.batch', $sg2), ['batch_id' => 999999])
            ->assertStatus(404)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('hestia_accounts', 0);
    }

    public function test_start_rejects_disabled_kill_switch_without_http_calls(): void
    {
        $this->login();
        $this->configureHestiaEnvironment(false); // HESTIA_ENABLED=false
        $server = $this->makeServer('sg2', 'sg2');

        Http::fake();

        $this->postJson(route('hestia.servers.sync.start', $server))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('hestia_sync_batches', 0);
        $this->assertDatabaseCount('hestia_sync_logs', 0);
    }

    public function test_start_rejects_server_without_credentials(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = HestiaServer::create([
            'name' => 'Kosong',
            'code' => 'kosong',
            'host' => 'kosong.test',
            'is_active' => true,
        ]);

        Http::fake();

        $this->postJson(route('hestia.servers.sync.start', $server))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('hestia_sync_batches', 0);
    }

    public function test_starting_a_new_session_aborts_the_previous_running_one(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        $users = $this->fixtureUsers(3);
        $this->fakeHestiaHost('sg2.test', $users, $this->fixtureDomains($users));
        $this->installHestiaHttpFake();

        $first = $this->postJson(route('hestia.servers.sync.start', $server))->json('batch.id');
        $second = $this->postJson(route('hestia.servers.sync.start', $server))->json('batch.id');

        $this->assertNotSame($first, $second);

        $stale = HestiaSyncBatch::findOrFail($first);
        $this->assertSame('failed', $stale->status);
        $this->assertStringContainsString('dibatalkan', (string) $stale->message);
        $this->assertNotNull($stale->finished_at);

        $this->assertSame('running', HestiaSyncBatch::findOrFail($second)->status);

        // Log milik sesi lama juga ditutup sebagai gagal.
        $this->assertSame('failed', HestiaSyncLog::where('id', $stale->hestia_sync_log_id)->value('status'));
    }

    public function test_stale_advance_does_not_double_count_results(): void
    {
        $this->login();
        $this->configureHestiaEnvironment();
        $server = $this->makeServer('sg2', 'sg2');

        $users = $this->fixtureUsers(3);
        $this->fakeHestiaHost('sg2.test', $users, $this->fixtureDomains($users));
        $this->installHestiaHttpFake();

        $batch = HestiaSyncBatch::findOrFail(
            $this->postJson(route('hestia.servers.sync.start', $server), ['batch_size' => 1])->json('batch.id'),
        );

        // Tiruan request kembar: instance lama dengan `next_offset` yang sudah
        // usang (mis. retry otomatis menyusul setelah request pertama selesai).
        $stale = clone $batch;

        $service = app(HestiaBatchSyncService::class);
        $fresh = $service->advance($batch);

        $this->assertSame(1, $fresh->next_offset);
        $this->assertSame(1, $fresh->pulled);
        $this->assertSame(1, $fresh->created);

        // Advance dengan instance basi: upsert akun idempotent (aman), tetapi
        // counter TIDAK ditulis ulang.
        $again = $service->advance($stale);

        $this->assertSame(1, $again->next_offset);
        $this->assertSame(1, $again->pulled);
        $this->assertSame(1, $again->created);
        $this->assertSame(0, $again->updated);

        $persisted = HestiaSyncBatch::findOrFail($batch->id);
        $this->assertSame(1, $persisted->pulled);
        $this->assertSame(1, $persisted->processed_users);
    }

    public function test_guest_cannot_use_batch_sync_endpoints(): void
    {
        $server = $this->makeServer('sg2', 'sg2');

        $this->postJson(route('hestia.servers.sync.start', $server))->assertUnauthorized();
        $this->postJson(route('hestia.servers.sync.batch', $server), ['batch_id' => 1])->assertUnauthorized();

        // Fallback form biasa (tanpa JSON) tetap diarahkan ke halaman login.
        $this->post(route('hestia.servers.sync.start', $server))->assertRedirect(route('login'));
    }
}
