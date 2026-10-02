<?php

namespace Tests\Feature\Hestia;

use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Services\Models\Service;
use Database\Factories\ClientFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class HestiaSyncCommandTest extends TestCase
{
    use InteractsWithHestia;
    use RefreshDatabase;

    /** Payload web domain Hestia (subset field yang relevan). */
    private function domain(string $date = '2026-01-01', string $suspended = 'no'): array
    {
        return ['IP' => '203.0.113.10', 'DATE' => $date, 'SUSPENDED' => $suspended];
    }

    // ---------- pemetaan otomatis (adopsi layanan lama, email, & belum cocok) ----------

    public function test_sync_maps_accounts_and_does_not_duplicate_existing_service(): void
    {
        $this->configureHestia();

        $client1 = ClientFactory::new()->create(['name' => 'Contoh Jaya', 'email' => 'ops@contoh.com']);
        $existing = ServiceFactory::new()->create([
            'client_id' => $client1->id,
            'reference' => 'contoh.com',
            'name' => 'Hosting Lama',
            'price' => 999000,
        ]);
        $client2 = ClientFactory::new()->create(['name' => 'Klien Email', 'email' => 'admin@emaildomain.com']);

        $this->fakeHestia(
            [
                'mcimedia' => ['PACKAGE' => 'default', 'SUSPENDED' => 'no'],
                'lain' => ['PACKAGE' => 'mini', 'SUSPENDED' => 'no'],
            ],
            [
                'mcimedia' => [
                    'contoh.com' => $this->domain('2026-01-01'),
                    'emaildomain.com' => $this->domain('2026-02-02'),
                ],
                'lain' => [
                    'belumcocok.com' => $this->domain('2026-03-03'),
                ],
            ]
        );

        $this->artisan('hestia:sync')->assertSuccessful();

        $this->assertDatabaseCount('hestia_accounts', 3);

        // 1) domain dengan layanan lama → adopsi layanan, tanpa duplikat & tanpa menimpa harga.
        $contoh = HestiaAccount::where('domain', 'contoh.com')->firstOrFail();
        $this->assertSame($client1->id, $contoh->client_id);
        $this->assertSame($existing->id, $contoh->service_id);
        $this->assertSame('auto', $contoh->mapping_status->value);
        $this->assertSame(1, Service::where('reference', 'contoh.com')->count());
        $this->assertSame(999000, $existing->fresh()->price);
        $this->assertSame('Hosting Lama', $existing->fresh()->name);

        // 2) domain cocok lewat domain email klien → layanan baru dibuat.
        $email = HestiaAccount::where('domain', 'emaildomain.com')->firstOrFail();
        $this->assertSame($client2->id, $email->client_id);
        $this->assertNotNull($email->service_id);
        $this->assertSame('auto', $email->mapping_status->value);
        $this->assertSame($email->service_id, Service::where('reference', 'emaildomain.com')->value('id'));

        // 3) domain tanpa pasangan klien → belum dipetakan, tanpa layanan.
        $unmapped = HestiaAccount::where('domain', 'belumcocok.com')->firstOrFail();
        $this->assertNull($unmapped->client_id);
        $this->assertNull($unmapped->service_id);
        $this->assertSame('unmapped', $unmapped->mapping_status->value);

        $log = HestiaSyncLog::orderByDesc('id')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertSame(3, $log->pulled);
        $this->assertSame(3, $log->created);
        $this->assertSame(1, $log->unmapped);
    }

    // ---------- idempotent: sync ulang tidak menduplikasi akun / layanan ----------

    public function test_resync_is_idempotent(): void
    {
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@bisnisku.id']);

        $payload = static fn () => ['u' => ['PACKAGE' => 'pro', 'SUSPENDED' => 'no']];
        $domains = ['u' => ['bisnisku.id' => $this->domain()]];

        $this->fakeHestia($payload(), $domains);
        $this->artisan('hestia:sync')->assertSuccessful();

        $this->assertDatabaseCount('hestia_accounts', 1);
        $this->assertSame(1, Service::where('reference', 'bisnisku.id')->count());
        $this->assertSame(1, HestiaSyncLog::orderByDesc('id')->first()->created);

        $this->fakeHestia($payload(), $domains);
        $this->artisan('hestia:sync')->assertSuccessful();

        $this->assertDatabaseCount('hestia_accounts', 1);
        $this->assertSame(1, Service::where('reference', 'bisnisku.id')->count());

        $second = HestiaSyncLog::orderByDesc('id')->first();
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->updated);
    }

    // ---------- akun hilang dari Hestia → nonaktif, tidak dihapus ----------

    public function test_missing_account_is_deactivated_not_deleted(): void
    {
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@a.com']);
        ClientFactory::new()->create(['email' => 'admin@b.com']);

        $this->fakeHestia(
            ['u' => ['PACKAGE' => 'pro']],
            ['u' => ['a.com' => $this->domain(), 'b.com' => $this->domain()]]
        );
        $this->artisan('hestia:sync')->assertSuccessful();

        $b = HestiaAccount::where('domain', 'b.com')->firstOrFail();
        $bServiceId = $b->service_id;
        $this->assertSame('active', $b->status->value);
        $this->assertSame('active', $b->service->status->value);

        // Sync kedua: b.com tidak lagi ada di Hestia.
        $this->fakeHestia(
            ['u' => ['PACKAGE' => 'pro']],
            ['u' => ['a.com' => $this->domain()]]
        );
        $this->artisan('hestia:sync')->assertSuccessful();

        $b->refresh();
        $this->assertSame('inactive', $b->status->value);
        $this->assertSame('inactive', $b->service->fresh()->status->value);
        $this->assertDatabaseHas('services', ['id' => $bServiceId]); // baris tetap ada
        $this->assertSame(1, HestiaSyncLog::orderByDesc('id')->first()->deactivated);
    }

    // ---------- domain di-suspend Hestia → layanan nonaktif ----------

    public function test_suspended_domain_creates_inactive_account_and_service(): void
    {
        $this->configureHestia();
        ClientFactory::new()->create(['email' => 'admin@suspend.com']);

        $this->fakeHestia(
            ['u' => ['PACKAGE' => 'pro']],
            ['u' => ['suspend.com' => $this->domain('2026-01-01', 'yes')]]
        );
        $this->artisan('hestia:sync')->assertSuccessful();

        $account = HestiaAccount::where('domain', 'suspend.com')->firstOrFail();
        $this->assertSame('inactive', $account->status->value);
        $this->assertSame('inactive', $account->service->status->value);
    }

    // ---------- kredensial tidak bocor ke log & perintah read-only ----------

    public function test_credentials_never_leak_to_logs_and_only_read_commands_are_sent(): void
    {
        $this->configureHestia();

        $handler = new TestHandler;
        $this->app->instance('log', new Logger('hestia-test', [$handler]));

        $this->fakeHestia(
            ['mcimedia' => ['PACKAGE' => 'default']],
            ['mcimedia' => ['contoh.com' => $this->domain()]]
        );

        $this->artisan('hestia:sync')->assertSuccessful();

        // Tidak ada rekaman log yang memuat password.
        $this->assertNotEmpty($handler->getRecords());
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString($this->hestiaPassword, (string) $record['message']);
            $this->assertStringNotContainsString($this->hestiaPassword, (string) json_encode($record['context']));
        }

        // Tidak ada data tersimpan yang memuat password.
        $this->assertStringNotContainsString($this->hestiaPassword, json_encode(HestiaAccount::all()->toArray()));
        $this->assertStringNotContainsString($this->hestiaPassword, json_encode(HestiaSyncLog::all()->toArray()));

        // Hanya perintah v-list* yang dikirim; kredensial tidak di URL.
        $this->assertNotEmpty(Http::recorded());
        foreach (Http::recorded() as [$request, $response]) {
            $this->assertStringStartsWith('v-list', (string) ($request->data()['cmd'] ?? ''));
            $this->assertStringNotContainsString($this->hestiaPassword, $request->url());
        }
    }

    // ---------- autentikasi access/secret key mengirim hash, bukan password ----------

    public function test_access_key_authentication_sends_hash_and_not_password(): void
    {
        $this->configureHestia();
        config([
            'crm.hestia.user' => '',
            'crm.hestia.password' => '',
            'crm.hestia.access_key' => 'AK-123',
            'crm.hestia.secret_key' => 'SK-456',
        ]);

        $this->fakeHestia(
            ['u' => ['PACKAGE' => 'pro']],
            ['u' => ['contoh.com' => $this->domain()]]
        );

        $this->artisan('hestia:sync')->assertSuccessful();

        foreach (Http::recorded() as [$request, $response]) {
            $data = $request->data();
            $this->assertSame('AK-123:SK-456', $data['hash'] ?? null);
            $this->assertArrayNotHasKey('password', $data);
            $this->assertArrayNotHasKey('user', $data);
        }
    }

    // ---------- belum dikonfigurasi → gagal rapi, tanpa request ----------

    public function test_disabled_sync_fails_gracefully_without_http_calls(): void
    {
        $this->configureHestia(false);
        Http::fake();

        $this->artisan('hestia:sync')
            ->expectsOutputToContain('gagal')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame('failed', HestiaSyncLog::orderByDesc('id')->firstOrFail()->status);
    }

    // ---------- scheduler ----------

    public function test_sync_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('hestia:sync')
            ->assertSuccessful();
    }
}
