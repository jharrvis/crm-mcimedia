<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceReminderKind;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Mail\InvoiceReminderMailable;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Domains\Invoicing\Services\InvoiceReminderDelivery;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendOverdueRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function enableFonnte(string $token = 'test-token'): void
    {
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => $token]);
    }

    /**
     * Invoice dengan klien baru, jatuh tempo $daysOverdue hari lalu.
     * Default: status terkirim, punya email + nomor WhatsApp.
     */
    private function overdueInvoice(
        int $daysOverdue,
        InvoiceStatus $status = InvoiceStatus::Sent,
        array $clientOverrides = [],
        int $total = 750000,
    ): Invoice {
        $client = ClientFactory::new()->create(array_merge([
            'email' => 'klien@example.com',
            'whatsapp' => '08123456789',
        ], $clientOverrides));

        return InvoiceFactory::new()->withItems($total)->create([
            'client_id' => $client->id,
            'status' => $status,
            'due_date' => now()->subDays($daysOverdue)->toDateString(),
        ]);
    }

    // ---------- H+1 terkirim sekali, idempotent ----------

    public function test_h1_reminder_is_sent_once_and_not_duplicated_on_second_run(): void
    {
        Mail::fake();
        Http::fake();
        $this->enableFonnte();

        $invoice = $this->overdueInvoice(1);

        $this->artisan('crm:send-overdue-reminders')
            ->expectsOutputToContain('pengiriman')
            ->assertSuccessful();

        Mail::assertSent(InvoiceReminderMailable::class, 1);
        Mail::assertSent(InvoiceReminderMailable::class, fn (InvoiceReminderMailable $mail) => $mail->hasTo('klien@example.com')
            && $mail->kind === InvoiceReminderKind::H1
            && $mail->invoice->is($invoice));
        Http::assertSentCount(1);

        $this->assertDatabaseHas('invoice_reminders', [
            'invoice_id' => $invoice->id,
            'kind' => 'H+1',
            'channel' => 'email',
        ]);
        $this->assertDatabaseHas('invoice_reminders', [
            'invoice_id' => $invoice->id,
            'kind' => 'H+1',
            'channel' => 'whatsapp',
        ]);
        $this->assertDatabaseCount('invoice_reminders', 2);

        // Status invoice TIDAK diubah oleh command ini.
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);

        // Jalan kedua pada hari yang sama: tidak ada pengiriman/baris ganda.
        $this->artisan('crm:send-overdue-reminders')
            ->expectsOutputToContain('Tidak ada pengingat')
            ->assertSuccessful();

        Mail::assertSent(InvoiceReminderMailable::class, 1);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('invoice_reminders', 2);
    }

    // ---------- H+7 & H+14 tepat waktu; H+3 tidak dikirimi ----------

    public function test_h7_and_h14_are_sent_on_their_day_while_other_ages_are_ignored(): void
    {
        Mail::fake();
        Http::fake();
        $this->enableFonnte();

        $h7 = $this->overdueInvoice(7);
        $h14 = $this->overdueInvoice(14, InvoiceStatus::Overdue);
        $h3 = $this->overdueInvoice(3);

        $this->artisan('crm:send-overdue-reminders')->assertSuccessful();

        $this->assertDatabaseHas('invoice_reminders', ['invoice_id' => $h7->id, 'kind' => 'H+7', 'channel' => 'email']);
        $this->assertDatabaseHas('invoice_reminders', ['invoice_id' => $h7->id, 'kind' => 'H+7', 'channel' => 'whatsapp']);
        $this->assertDatabaseHas('invoice_reminders', ['invoice_id' => $h14->id, 'kind' => 'H+14', 'channel' => 'email']);
        $this->assertDatabaseHas('invoice_reminders', ['invoice_id' => $h14->id, 'kind' => 'H+14', 'channel' => 'whatsapp']);

        $this->assertDatabaseMissing('invoice_reminders', ['invoice_id' => $h3->id]);
        $this->assertDatabaseCount('invoice_reminders', 4);

        Mail::assertSent(InvoiceReminderMailable::class, 2); // H+7 & H+14 saja
    }

    // ---------- status final/draf tidak dikirimi ----------

    public function test_paid_cancelled_and_draft_invoices_receive_no_reminder(): void
    {
        Mail::fake();
        Http::fake();
        $this->enableFonnte();

        $paid = $this->overdueInvoice(1, InvoiceStatus::Paid);
        $cancelled = $this->overdueInvoice(1, InvoiceStatus::Cancelled);
        $draft = $this->overdueInvoice(1, InvoiceStatus::Draft);

        $this->artisan('crm:send-overdue-reminders')
            ->expectsOutputToContain('Tidak ada pengingat')
            ->assertSuccessful();

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertDatabaseCount('invoice_reminders', 0);

        foreach ([$paid, $cancelled, $draft] as $invoice) {
            $this->assertDatabaseMissing('invoice_reminders', ['invoice_id' => $invoice->id]);
        }
    }

    // ---------- tujuan/kredensial kosong: tanpa exception, tanpa baris ----------

    public function test_missing_email_number_and_token_skip_silently_without_rows(): void
    {
        Mail::fake();
        Http::fake();
        config(['crm.fonnte.enabled' => false, 'crm.fonnte.token' => '']);

        $invoice = $this->overdueInvoice(1, InvoiceStatus::Sent, ['email' => null, 'whatsapp' => null]);

        $this->artisan('crm:send-overdue-reminders')
            ->expectsOutputToContain('Tidak ada pengingat')
            ->assertSuccessful();

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertDatabaseCount('invoice_reminders', 0);

        // Status & tautan tidak berubah saat tidak ada pengiriman.
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNull($invoice->public_token);
    }

    public function test_channel_without_its_destination_is_not_recorded_while_other_channel_is(): void
    {
        Mail::fake();
        Http::fake();
        $this->enableFonnte();

        // Email ada, nomor WhatsApp tidak → hanya baris email.
        $invoice = $this->overdueInvoice(1, InvoiceStatus::Sent, ['whatsapp' => null]);

        $this->artisan('crm:send-overdue-reminders')->assertSuccessful();

        Mail::assertSent(InvoiceReminderMailable::class, 1);
        Http::assertNothingSent();
        $this->assertDatabaseHas('invoice_reminders', ['invoice_id' => $invoice->id, 'channel' => 'email']);
        $this->assertDatabaseMissing('invoice_reminders', ['invoice_id' => $invoice->id, 'channel' => 'whatsapp']);
        $this->assertDatabaseCount('invoice_reminders', 1);
    }

    // ---------- pesan pengingat ----------

    public function test_reminder_whatsapp_message_contains_invoice_details_and_payment_link(): void
    {
        $invoice = $this->overdueInvoice(7);
        $invoice->forceFill(['public_token' => str_repeat('b', 64)])->save();
        $invoice->load('client');

        $message = InvoiceReminderDelivery::whatsappMessage(
            $invoice,
            InvoiceReminderKind::H7
        );

        $this->assertStringContainsString($invoice->number, $message);
        $this->assertStringContainsString(rupiah($invoice->total), $message);
        $this->assertStringContainsString('lewat 7 hari', $message);
        $this->assertStringContainsString(
            route('invoices.public.show', ['token' => $invoice->public_token]),
            $message
        );
    }

    public function test_reminder_email_view_renders_details_and_payment_link(): void
    {
        $invoice = $this->overdueInvoice(1);
        $invoice->forceFill(['public_token' => str_repeat('c', 64)])->save();
        $invoice->load('client');

        $mailable = new InvoiceReminderMailable($invoice, InvoiceReminderKind::H1);
        $this->assertStringContainsString($invoice->number, $mailable->envelope()->subject);

        $html = view('emails.invoice-reminder', [
            'invoice' => $invoice,
            'business' => config('crm.business'),
            'bank' => config('crm.bank'),
            'paymentUrl' => InvoiceDelivery::paymentUrl($invoice),
            'kind' => InvoiceReminderKind::H1,
            'daysOverdue' => 1,
        ])->render();

        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString(rupiah($invoice->total), $html);
        $this->assertStringContainsString('melewati jatuh tempo', $html);
        $this->assertStringContainsString(
            route('invoices.public.show', ['token' => $invoice->public_token]),
            $html
        );
    }

    // ---------- scheduler ----------

    public function test_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('crm:send-overdue-reminders')
            ->assertSuccessful();
    }
}
