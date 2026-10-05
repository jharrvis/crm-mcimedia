<?php

namespace Tests\Feature\Services;

use App\Domains\Access\Enums\Module;
use App\Domains\Services\Enums\ServiceReminderKind;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Mail\ServiceReminderMailable;
use App\Domains\Services\Models\Service;
use App\Domains\Services\Services\ServiceReminderDelivery;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\RoleFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tombol kirim reminder manual WA & email di halaman Pengingat (t_6f78ca1d).
 */
class ReminderManualSendTest extends TestCase
{
    use RefreshDatabase;

    private function enableFonnte(string $token = 'test-token'): void
    {
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => $token]);
    }

    private function service(int $daysFromNow, array $clientOverrides = [], array $overrides = []): Service
    {
        return ServiceFactory::new()->create(array_merge([
            'end_date' => now()->addDays($daysFromNow)->toDateString(),
            'status' => ServiceStatus::Active,
            'reminder_enabled' => true,
            'client_id' => ClientFactory::new()->create($clientOverrides)->id,
        ], $overrides));
    }

    // ---------- tampilan halaman ----------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('reminders.index'))->assertRedirect(route('login'));
    }

    public function test_buttons_render_for_manage_user_and_disabled_without_targets(): void
    {
        $this->actingAs(User::factory()->create()); // legacy: akses penuh
        $withBoth = $this->service(5, ['whatsapp' => '08123456789', 'email' => 'klien@example.com']);
        $withoutWa = $this->service(10, ['whatsapp' => null, 'email' => 'lagi@example.com']);
        $withoutEmail = $this->service(15, ['whatsapp' => '08129999999', 'email' => null]);

        $response = $this->get(route('reminders.index'))->assertOk();

        $response->assertSee(route('reminders.send-whatsapp', $withBoth), false);
        $response->assertSee(route('reminders.send-email', $withBoth), false);
        // Tanpa nomor WA / email: tombol tetap tampil tapi disabled + alasan jelas.
        $response->assertSee('Klien belum punya nomor WhatsApp');
        $response->assertSee('Klien belum punya alamat email');
        $response->assertSee('disabled', false);
        // Tiga layanan → tiga pasang tombol.
        $this->assertSame(3, substr_count($response->getContent(), 'Kirim WA'));
        $this->assertSame(3, substr_count($response->getContent(), 'Kirim Email'));
    }

    public function test_view_only_user_sees_placeholder_instead_of_buttons(): void
    {
        $role = RoleFactory::new()->views([Module::Reminders])->create();
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);
        $this->service(5);

        $response = $this->get(route('reminders.index'))->assertOk();

        $response->assertDontSee('Kirim WA', false);
        $response->assertDontSee('Kirim Email', false);
        $response->assertSee('Kelola untuk kirim');
    }

    // ---------- kirim WhatsApp ----------

    public function test_whatsapp_send_records_trace_and_logs_activity(): void
    {
        Http::fake();
        $this->enableFonnte();
        $this->actingAs(User::factory()->create());

        $service = $this->service(7); // persis H-7 → kind terjadwal

        $this->from(route('reminders.index'))
            ->post(route('reminders.send-whatsapp', $service))
            ->assertRedirect(route('reminders.index'))
            ->assertSessionHas('success');

        Http::assertSentCount(1);
        $this->assertDatabaseHas('service_reminders', [
            'service_id' => $service->id,
            'kind' => 'H-7',
            'channel' => 'whatsapp',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'subject_id' => $service->id,
            'event' => 'reminder_whatsapp_sent',
        ]);
    }

    public function test_scheduled_kind_cannot_be_sent_twice(): void
    {
        Http::fake();
        $this->enableFonnte();
        $this->actingAs(User::factory()->create());

        $service = $this->service(7);
        $this->post(route('reminders.send-whatsapp', $service))->assertSessionHas('success');
        $this->post(route('reminders.send-whatsapp', $service))->assertSessionHas('error');

        Http::assertSentCount(1); // klik kedua ditolak sebelum HTTP
        $this->assertDatabaseCount('service_reminders', 1);
    }

    public function test_manual_kind_may_be_resent(): void
    {
        Http::fake();
        $this->enableFonnte();
        $this->actingAs(User::factory()->create());

        $service = $this->service(20); // bukan H-7/3/1, belum overdue → Manual

        $this->post(route('reminders.send-whatsapp', $service))->assertSessionHas('success');
        $this->post(route('reminders.send-whatsapp', $service))->assertSessionHas('success');

        Http::assertSentCount(2);
        $this->assertDatabaseHas('service_reminders', [
            'service_id' => $service->id,
            'kind' => 'manual',
            'channel' => 'whatsapp',
        ]);
        // Resend manual = updateOrCreate: trace tetap SATU baris (unique
        // service_id+kind+channel), sent_at = pengiriman terakhir.
        $this->assertDatabaseCount('service_reminders', 1);
    }

    public function test_whatsapp_send_blocked_when_fonnte_disabled_or_number_missing(): void
    {
        Http::fake();
        config(['crm.fonnte.enabled' => false]);
        $this->actingAs(User::factory()->create());

        $noWa = $this->service(7, ['whatsapp' => null]);
        $fonnteOff = $this->service(7);

        $this->post(route('reminders.send-whatsapp', $noWa))->assertSessionHas('error');
        $this->post(route('reminders.send-whatsapp', $fonnteOff))->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertDatabaseCount('service_reminders', 0);
    }

    public function test_whatsapp_send_requires_manage_permission(): void
    {
        Http::fake();
        $this->enableFonnte();
        $role = RoleFactory::new()->views([Module::Reminders])->create();
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        $service = $this->service(7);
        $this->post(route('reminders.send-whatsapp', $service))->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('service_reminders', 0);
    }

    // ---------- kirim email ----------

    public function test_email_send_delivers_mailable_and_records_trace(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());

        $service = $this->service(3, ['email' => 'klien@example.com']); // H-3

        $this->from(route('reminders.index'))
            ->post(route('reminders.send-email', $service))
            ->assertRedirect(route('reminders.index'))
            ->assertSessionHas('success');

        Mail::assertSent(ServiceReminderMailable::class, 1);
        Mail::assertSent(ServiceReminderMailable::class, fn (ServiceReminderMailable $mail) => $mail->hasTo('klien@example.com')
            && $mail->kind === ServiceReminderKind::HMinus3
            && $mail->service->is($service));
        $this->assertDatabaseHas('service_reminders', [
            'service_id' => $service->id,
            'kind' => 'H-3',
            'channel' => 'email',
        ]);
    }

    public function test_email_send_blocked_when_client_has_no_email(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());

        $service = $this->service(3, ['email' => null]);

        $this->post(route('reminders.send-email', $service))->assertSessionHas('error');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('service_reminders', 0);
    }

    public function test_email_and_whatsapp_are_independent_channels(): void
    {
        Http::fake();
        Mail::fake();
        $this->enableFonnte();
        $this->actingAs(User::factory()->create());

        $service = $this->service(1); // H-1

        $this->post(route('reminders.send-email', $service))->assertSessionHas('success');
        $this->post(route('reminders.send-whatsapp', $service))->assertSessionHas('success');
        // Email H-1 sudah terkirim; kirim email lagi ditolak — WA tetap boleh.
        $this->post(route('reminders.send-email', $service))->assertSessionHas('error');

        $this->assertDatabaseHas('service_reminders', ['service_id' => $service->id, 'channel' => 'email']);
        $this->assertDatabaseHas('service_reminders', ['service_id' => $service->id, 'channel' => 'whatsapp']);
        $this->assertDatabaseCount('service_reminders', 2);
        Mail::assertSentCount(1);
        Http::assertSentCount(1);
    }

    public function test_inactive_or_end_date_less_service_is_rejected(): void
    {
        Http::fake();
        $this->enableFonnte();
        $this->actingAs(User::factory()->create());

        $inactive = $this->service(7, [], ['status' => ServiceStatus::Inactive]);
        $noDate = $this->service(7, [], ['end_date' => null]);

        $this->post(route('reminders.send-whatsapp', $inactive))->assertSessionHas('error');
        $this->post(route('reminders.send-whatsapp', $noDate))->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_overdue_service_uses_overdue_kind_and_blocks_resend(): void
    {
        Http::fake();
        $this->enableFonnte();
        $this->actingAs(User::factory()->create());

        $service = $this->service(-5);

        $this->post(route('reminders.send-whatsapp', $service))->assertSessionHas('success');
        $this->post(route('reminders.send-whatsapp', $service))->assertSessionHas('error');

        $this->assertDatabaseHas('service_reminders', [
            'service_id' => $service->id,
            'kind' => 'overdue',
            'channel' => 'whatsapp',
        ]);
        Http::assertSentCount(1);
    }

    public function test_whatsapp_message_carries_service_details(): void
    {
        $service = $this->service(3, [], ['name' => 'Hosting Jatuh Tempo', 'price' => 750000]);
        $service->load('client');

        $message = ServiceReminderDelivery::whatsappMessage(
            $service,
            ServiceReminderKind::HMinus3
        );

        $this->assertStringContainsString('Hosting Jatuh Tempo', $message);
        $this->assertStringContainsString('H-3', $message);
        $this->assertStringContainsString(rupiah(750000), $message);
    }
}
