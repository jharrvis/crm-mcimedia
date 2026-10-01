<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Jobs\SendInvoiceEmailJob;
use App\Domains\Invoicing\Jobs\SendInvoiceWhatsappJob;
use App\Domains\Invoicing\Mail\InvoiceMailable;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Invoice draf dengan klien baru; kembalikan [invoice, client]. */
    private function draftInvoice(array $clientOverrides = [], int $total = 800000, array $invoiceOverrides = []): array
    {
        $client = ClientFactory::new()->create($clientOverrides);

        $invoice = InvoiceFactory::new()->withItems($total)->create(array_merge([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Draft,
        ], $invoiceOverrides));

        return [$invoice, $client];
    }

    private function enableFonnte(string $token = 'test-token'): void
    {
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => $token]);
    }

    // ---------- email ----------

    public function test_email_job_sends_mailable_with_pdf_attachment_and_marks_sent(): void
    {
        Mail::fake();
        [$invoice, $client] = $this->draftInvoice(['email' => 'klien@example.com']);

        (new SendInvoiceEmailJob($invoice))->handle();

        Mail::assertSent(InvoiceMailable::class, function (InvoiceMailable $mail) use ($client, $invoice) {
            $attachments = $mail->attachments();

            return $mail->hasTo($client->email)
                && count($attachments) === 1
                && $attachments[0]->as === $invoice->number.'.pdf'
                && $attachments[0]->mime === 'application/pdf';
        });

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
        $this->assertNotNull($invoice->public_token);
        $this->assertSame(64, strlen($invoice->public_token));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $invoice->getMorphClass(),
            'subject_id' => $invoice->id,
            'event' => 'email_sent',
        ]);
    }

    public function test_email_mailable_renders_pdf_and_public_payment_link(): void
    {
        [$invoice, $client] = $this->draftInvoice(['email' => 'klien@example.com']);
        $invoice->forceFill(['public_token' => str_repeat('a', 64)])->save();
        $invoice->load(['client', 'items']);

        $mailable = new InvoiceMailable($invoice);
        $this->assertStringStartsWith('%PDF', $mailable->renderPdf());

        $html = view('emails.invoice', [
            'invoice' => $invoice,
            'business' => config('crm.business'),
            'bank' => config('crm.bank'),
            'paymentUrl' => InvoiceDelivery::paymentUrl($invoice),
            'pdfUrl' => InvoiceDelivery::pdfUrl($invoice),
        ])->render();

        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString(rupiah($invoice->total), $html);
        $this->assertStringContainsString(
            route('invoices.public.show', ['token' => $invoice->public_token]),
            $html
        );
    }

    public function test_email_job_skips_without_client_email(): void
    {
        Mail::fake();
        [$invoice] = $this->draftInvoice(['email' => null]);

        (new SendInvoiceEmailJob($invoice))->handle();

        Mail::assertNothingSent();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->sent_at);
        $this->assertNull($invoice->public_token);
    }

    // ---------- WhatsApp / Fonnte ----------

    public function test_whatsapp_job_posts_to_fonnte_with_expected_header_and_payload(): void
    {
        Http::fake();
        $this->enableFonnte();
        [$invoice, $client] = $this->draftInvoice(['whatsapp' => '08123456789']);

        (new SendInvoiceWhatsappJob($invoice))->handle();

        $token = $invoice->fresh()->public_token;

        Http::assertSent(function ($request) use ($invoice, $token) {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request->hasHeader('Authorization', 'test-token')
                && $request['target'] === '628123456789'
                && $request['filename'] === $invoice->number.'.pdf'
                && $request['url'] === route('invoices.public.pdf', ['token' => $token])
                && str_contains((string) $request['message'], $invoice->number)
                && str_contains((string) $request['message'], route('invoices.public.show', ['token' => $token]));
        });

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
        $this->assertNotNull($invoice->public_token);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $invoice->getMorphClass(),
            'subject_id' => $invoice->id,
            'event' => 'whatsapp_sent',
        ]);
    }

    public function test_whatsapp_job_skips_without_token(): void
    {
        Http::fake();
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => '']);
        [$invoice] = $this->draftInvoice(['whatsapp' => '08123456789']);

        (new SendInvoiceWhatsappJob($invoice))->handle();

        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_whatsapp_job_skips_when_fonnte_disabled(): void
    {
        Http::fake();
        config(['crm.fonnte.enabled' => false, 'crm.fonnte.token' => 'test-token']);
        [$invoice] = $this->draftInvoice(['whatsapp' => '08123456789']);

        (new SendInvoiceWhatsappJob($invoice))->handle();

        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_whatsapp_job_skips_without_client_number(): void
    {
        Http::fake();
        $this->enableFonnte();
        [$invoice] = $this->draftInvoice(['whatsapp' => null]);

        (new SendInvoiceWhatsappJob($invoice))->handle();

        Http::assertNothingSent();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->sent_at);
    }

    public function test_whatsapp_number_is_normalized_to_international_format(): void
    {
        $this->assertSame('628123456789', InvoiceDelivery::normalizeWhatsapp('08123456789'));
        $this->assertSame('628123456789', InvoiceDelivery::normalizeWhatsapp('+62 812-3456-789'));
        $this->assertSame('628123456789', InvoiceDelivery::normalizeWhatsapp('628123456789'));
        $this->assertSame('628123456789', InvoiceDelivery::normalizeWhatsapp('812-3456-789'));
        $this->assertNull(InvoiceDelivery::normalizeWhatsapp(null));
        $this->assertNull(InvoiceDelivery::normalizeWhatsapp('   '));
    }

    // ---------- aksi admin: antre via queue ----------

    public function test_admin_can_queue_email_delivery(): void
    {
        Queue::fake();
        $this->login();
        [$invoice, $client] = $this->draftInvoice(['email' => 'klien@example.com']);

        $this->post(route('invoices.send-email', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(SendInvoiceEmailJob::class, fn ($job) => $job->invoice->is($invoice));

        // Status tidak berubah oleh aksi antre (job yang mengubah).
        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_admin_can_queue_whatsapp_delivery(): void
    {
        Queue::fake();
        $this->enableFonnte();
        $this->login();
        [$invoice] = $this->draftInvoice(['whatsapp' => '08123456789']);

        $this->post(route('invoices.send-whatsapp', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(SendInvoiceWhatsappJob::class, fn ($job) => $job->invoice->is($invoice));
    }

    public function test_admin_email_delivery_blocked_without_client_email(): void
    {
        Queue::fake();
        $this->login();
        [$invoice] = $this->draftInvoice(['email' => null]);

        $this->post(route('invoices.send-email', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_admin_whatsapp_delivery_blocked_when_fonnte_not_configured(): void
    {
        Queue::fake();
        config(['crm.fonnte.enabled' => false, 'crm.fonnte.token' => '']);
        $this->login();
        [$invoice] = $this->draftInvoice(['whatsapp' => '08123456789']);

        $this->post(route('invoices.send-whatsapp', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_admin_delivery_blocked_for_terminal_invoice(): void
    {
        Queue::fake();
        $this->login();
        [$invoice] = $this->draftInvoice(['email' => 'klien@example.com'], 800000, [
            'status' => InvoiceStatus::Paid,
        ]);

        $this->post(route('invoices.send-email', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    // ---------- UI detail invoice ----------

    public function test_show_page_renders_send_buttons(): void
    {
        $this->login();
        [$invoice] = $this->draftInvoice(['email' => 'klien@example.com', 'whatsapp' => '08123456789']);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Kirim Email')
            ->assertSee('Kirim WhatsApp')
            ->assertSee('Riwayat pengiriman');
    }

    public function test_show_page_disables_buttons_without_destination(): void
    {
        $this->login();
        [$invoice] = $this->draftInvoice(['email' => null, 'whatsapp' => null]);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Klien belum punya alamat email')
            ->assertSee('Klien belum punya nomor WhatsApp');
    }

    public function test_show_page_lists_delivery_history_from_activity_log(): void
    {
        Mail::fake();
        [$invoice, $client] = $this->draftInvoice(['email' => 'klien@example.com']);

        (new SendInvoiceEmailJob($invoice))->handle();

        $this->login();
        $this->get(route('invoices.show', $invoice->fresh()))
            ->assertOk()
            ->assertSee('dikirim via email ke');
    }
}
