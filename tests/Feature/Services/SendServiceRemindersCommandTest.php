<?php

namespace Tests\Feature\Services;

use App\Domains\Services\Enums\ServiceReminderKind;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Models\Service;
use App\Domains\Services\Services\ServiceReminderDelivery;
use Database\Factories\ClientFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendServiceRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function enableFonnte(string $token = 'test-token'): void
    {
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => $token]);
    }

    private function service(int $daysFromNow, array $overrides = []): Service
    {
        return ServiceFactory::new()->create(array_merge([
            'end_date' => now()->addDays($daysFromNow)->toDateString(),
            'status' => ServiceStatus::Active,
            'reminder_enabled' => true,
        ], $overrides));
    }

    public function test_h7_and_h1_reminders_are_sent_once_and_not_duplicated_on_second_run(): void
    {
        Http::fake();
        $this->enableFonnte();

        $h7 = $this->service(7, ['name' => 'Hosting H-7']);
        $this->service(2, ['name' => 'Bukan hari reminder']); // H-2: tidak ikut
        $noReminder = $this->service(7, ['name' => 'Reminder off', 'reminder_enabled' => false]);
        $inactive = $this->service(7, ['name' => 'Nonaktif', 'status' => ServiceStatus::Inactive]);

        $this->artisan('crm:send-service-reminders')
            ->expectsOutputToContain('Reminder layanan dikirim: 1 pengiriman.')
            ->assertSuccessful();

        Http::assertSentCount(1);

        $this->assertDatabaseHas('service_reminders', [
            'service_id' => $h7->id,
            'kind' => 'H-7',
            'channel' => 'whatsapp',
        ]);
        $this->assertDatabaseCount('service_reminders', 1);

        foreach ([$noReminder, $inactive] as $skipped) {
            $this->assertDatabaseMissing('service_reminders', ['service_id' => $skipped->id]);
        }

        // Jalan kedua: idempotent, tidak ada kiriman ganda.
        $this->artisan('crm:send-service-reminders')
            ->expectsOutputToContain('Tidak ada reminder layanan yang dikirim.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('service_reminders', 1);
    }

    public function test_h3_and_overdue_reminders_are_sent_once(): void
    {
        Http::fake();
        $this->enableFonnte();

        $h3 = $this->service(3, ['name' => 'Domain H-3']);
        $h1 = $this->service(1, ['name' => 'Domain H-1']);
        $overdue = $this->service(-5, ['name' => 'Domain lewat tempo']);

        $this->artisan('crm:send-service-reminders')->assertSuccessful();

        Http::assertSentCount(3);
        $this->assertDatabaseHas('service_reminders', ['service_id' => $h3->id, 'kind' => 'H-3', 'channel' => 'whatsapp']);
        $this->assertDatabaseHas('service_reminders', ['service_id' => $h1->id, 'kind' => 'H-1', 'channel' => 'whatsapp']);
        $this->assertDatabaseHas('service_reminders', ['service_id' => $overdue->id, 'kind' => 'overdue', 'channel' => 'whatsapp']);

        // Overdue tidak di-spam harian: run kedua tetap sepi.
        $this->artisan('crm:send-service-reminders')
            ->expectsOutputToContain('Tidak ada reminder layanan yang dikirim.')
            ->assertSuccessful();

        Http::assertSentCount(3);
    }

    public function test_skipped_channels_are_not_recorded_and_status_is_untouched(): void
    {
        config(['crm.fonnte.enabled' => false]); // Fonnte mati → skip semua
        Http::fake();

        $service = $this->service(7);
        $this->artisan('crm:send-service-reminders')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseCount('service_reminders', 0);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertTrue(blank($service->fresh()->end_date) === false);
    }

    public function test_service_without_client_whatsapp_is_not_recorded_while_other_services_are(): void
    {
        Http::fake();
        $this->enableFonnte();

        $noWa = $this->service(7, [
            'name' => 'Tanpa WA',
            'client_id' => ClientFactory::new()->create(['whatsapp' => null])->id,
        ]);
        $hasWa = $this->service(7, ['name' => 'Dengan WA']);

        $this->artisan('crm:send-service-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertDatabaseMissing('service_reminders', ['service_id' => $noWa->id]);
        $this->assertDatabaseHas('service_reminders', ['service_id' => $hasWa->id, 'kind' => 'H-7']);
    }

    public function test_whatsapp_message_contains_service_details(): void
    {
        $service = $this->service(3, ['name' => 'Hosting Merdeka', 'price' => 500000]);
        $service->load('client');

        $pre = ServiceReminderDelivery::whatsappMessage($service, ServiceReminderKind::HMinus3);
        $over = ServiceReminderDelivery::whatsappMessage($service, ServiceReminderKind::Overdue);

        $this->assertStringContainsString('Hosting Merdeka', $pre);
        $this->assertStringContainsString('H-3', $pre);
        $this->assertStringContainsString(rupiah(500000), $pre);
        $this->assertStringContainsString(tgl_id($service->end_date), $pre);
        $this->assertStringContainsString('Terlambat:', $over);
    }

    public function test_kind_lookup_matches_only_exact_days(): void
    {
        $this->assertSame(ServiceReminderKind::HMinus7, ServiceReminderKind::forDaysRemaining(7));
        $this->assertSame(ServiceReminderKind::HMinus3, ServiceReminderKind::forDaysRemaining(3));
        $this->assertSame(ServiceReminderKind::HMinus1, ServiceReminderKind::forDaysRemaining(1));
        $this->assertNull(ServiceReminderKind::forDaysRemaining(5));
        $this->assertNull(ServiceReminderKind::forDaysRemaining(0));
    }

    public function test_fonnte_http_error_leaves_no_trace_and_does_not_change_status(): void
    {
        // Fonnte balas 500 → channel dilewati, tanpa baris; run berikut tetap mencoba & bisa pulih.
        Http::fake(['*' => Http::response('gagal', 500)]);
        $this->enableFonnte();

        $service = $this->service(7);

        $this->artisan('crm:send-service-reminders')
            ->expectsOutputToContain('Tidak ada reminder layanan yang dikirim.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('service_reminders', 0);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_connection_exception_does_not_abort_the_batch(): void
    {
        // Fonnte tidak terjangkau pada kiriman pertama → service pertama
        // dilewati tanpa baris, service kedua tetap terkirim (batch jalan).
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;

            if ($call === 1) {
                throw new ConnectionException('Could not resolve host: api.fonnte.com');
            }

            return Http::response(['status' => 'OK']);
        });
        $this->enableFonnte();

        $first = $this->service(7, ['name' => 'Pertama gagal koneksi']);
        $second = $this->service(7, ['name' => 'Kedua sukses']);

        $this->artisan('crm:send-service-reminders')
            ->expectsOutputToContain('Reminder layanan dikirim: 1 pengiriman.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('service_reminders', ['service_id' => $first->id]);
        $this->assertDatabaseHas('service_reminders', ['service_id' => $second->id, 'kind' => 'H-7']);
    }

    public function test_failed_claim_is_released_and_retried_on_next_run(): void
    {
        // Run 1: Fonnte balas 500 → klaim dilepas (tanpa baris). Run 2: pulih → terkirim & menetap.
        $this->enableFonnte();
        $service = $this->service(7);

        // fakeSequence: respons 500 dulu, lalu sukses (fake() kedua tidak
        // menimpa stub pertama).
        Http::fakeSequence()
            ->push('gagal', 500)
            ->push(['status' => 'OK']);

        $this->artisan('crm:send-service-reminders')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('service_reminders', 0);

        $this->artisan('crm:send-service-reminders')
            ->expectsOutputToContain('Reminder layanan dikirim: 1 pengiriman.')
            ->assertSuccessful();

        $this->assertDatabaseHas('service_reminders', ['service_id' => $service->id, 'kind' => 'H-7', 'channel' => 'whatsapp']);
    }

    public function test_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('crm:send-service-reminders')
            ->assertSuccessful();
    }
}
