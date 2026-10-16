<?php

namespace Tests\Feature\Quotation;

use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Jobs\SendQuotationEmailJob;
use App\Domains\Quotation\Jobs\SendQuotationWhatsappJob;
use App\Domains\Quotation\Mail\QuotationMailable;
use App\Domains\Quotation\Services\QuotationDelivery;
use Database\Factories\ClientFactory;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Q-2: pengiriman penawaran via email/WhatsApp (mirror F2-5).
 * Per-job + via action admin QuotationController.
 */
class QuotationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function draftQuotation(array $clientOverrides = [], int $total = 800000, array $overrides = []): array
    {
        $client = ClientFactory::new()->create($clientOverrides);
        $quotation = QuotationFactory::new()->withItems($total)->create(array_merge([
            'client_id' => $client->id,
            'status' => QuotationStatus::Draft,
        ], $overrides));

        return [$quotation, $client];
    }

    private function enableFonnte(string $token = 'test-token'): void
    {
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => $token, 'crm.fonnte.endpoint' => 'https://api.fonnte.com/send']);
    }

    // ---------- email ----------

    public function test_email_job_sends_mailable_with_pdf_and_marks_sent(): void
    {
        Mail::fake();
        [$quotation, $client] = $this->draftQuotation(['email' => 'klien@example.com']);

        (new SendQuotationEmailJob($quotation))->handle();

        Mail::assertSent(QuotationMailable::class, function (QuotationMailable $mail) use ($client, $quotation) {
            $attachments = $mail->attachments();

            return $mail->hasTo($client->email)
                && count($attachments) === 1
                && $attachments[0]->as === $quotation->number.'.pdf'
                && $attachments[0]->mime === 'application/pdf';
        });

        $quotation->refresh();
        $this->assertSame(QuotationStatus::Sent, $quotation->status);
        $this->assertNotNull($quotation->sent_at);
        $this->assertNotNull($quotation->public_token);
        $this->assertSame(64, strlen($quotation->public_token));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $quotation->getMorphClass(),
            'subject_id' => $quotation->id,
            'event' => QuotationDelivery::EVENT_EMAIL,
        ]);
    }

    public function test_email_job_skips_without_client_email(): void
    {
        Mail::fake();
        [$quotation] = $this->draftQuotation(['email' => null]);

        (new SendQuotationEmailJob($quotation))->handle();

        Mail::assertNothingSent();
        $quotation->refresh();
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertNull($quotation->sent_at);
        $this->assertNull($quotation->public_token);
    }

    public function test_email_mailable_renders_public_link(): void
    {
        $client = ClientFactory::new()->create(['email' => 'klien@example.com']);
        $quotation = QuotationFactory::new()->withItems(1200000)->create([
            'client_id' => $client->id,
            'status' => QuotationStatus::Draft,
        ]);
        $quotation->markSent();

        $html = (new QuotationMailable($quotation))->render();

        $this->assertStringContainsString($quotation->number, $html);
        $this->assertStringContainsString(route('quotations.public.show', ['token' => $quotation->public_token]), $html);
        $this->assertStringContainsString('Rp 1.200.000', $html);
    }

    public function test_quotation_delivery_action_dispatches_email_job(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        [$quotation] = $this->draftQuotation(['email' => 'klien@example.com']);

        \Illuminate\Support\Facades\Queue::fake();
        $response = $this->post(route('quotations.send-email', $quotation));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        \Illuminate\Support\Facades\Queue::assertPushed(SendQuotationEmailJob::class);
    }

    public function test_admin_send_email_blocked_when_terminal(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $quotation = QuotationFactory::new()->withItems()->create(['status' => QuotationStatus::Accepted]);

        \Illuminate\Support\Facades\Queue::fake();
        $this->post(route('quotations.send-email', $quotation))->assertSessionHas('error');
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    // ---------- WhatsApp / Fonnte ----------

    public function test_whatsapp_job_posts_to_fonnte_with_expected_header_and_payload(): void
    {
        Http::fake();
        $this->enableFonnte();
        [$quotation, $client] = $this->draftQuotation(['whatsapp' => '08123456789']);

        (new SendQuotationWhatsappJob($quotation))->handle();

        $token = $quotation->fresh()->public_token;

        Http::assertSent(function ($request) use ($quotation, $token) {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request->hasHeader('Authorization', 'test-token')
                && $request['target'] === '628123456789'
                && $request['filename'] === $quotation->number.'.pdf'
                && str_contains((string) $request['url'], $token)
                && str_contains((string) $request['message'], $quotation->number)
                && str_contains((string) $request['message'], route('quotations.public.show', ['token' => $token]));
        });

        $quotation->refresh();
        $this->assertSame(QuotationStatus::Sent, $quotation->status);
        $this->assertNotNull($quotation->sent_at);
        $this->assertNotNull($quotation->public_token);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $quotation->getMorphClass(),
            'subject_id' => $quotation->id,
            'event' => QuotationDelivery::EVENT_WHATSAPP,
        ]);
    }

    public function test_whatsapp_job_skips_without_token(): void
    {
        Http::fake();
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => '', 'crm.fonnte.endpoint' => 'https://api.fonnte.com/send']);
        [$quotation] = $this->draftQuotation(['whatsapp' => '08123456789']);

        (new SendQuotationWhatsappJob($quotation))->handle();

        Http::assertNothingSent();
        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);
    }

    public function test_whatsapp_job_skips_when_fonnte_disabled(): void
    {
        Http::fake();
        config(['crm.fonnte.enabled' => false, 'crm.fonnte.token' => 'test-token']);
        [$quotation] = $this->draftQuotation(['whatsapp' => '08123456789']);

        (new SendQuotationWhatsappJob($quotation))->handle();

        Http::assertNothingSent();
        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);
    }

    public function test_whatsapp_job_skips_without_client_number(): void
    {
        Http::fake();
        $this->enableFonnte();
        [$quotation] = $this->draftQuotation(['whatsapp' => null]);

        (new SendQuotationWhatsappJob($quotation))->handle();

        Http::assertNothingSent();
        $quotation->refresh();
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertNull($quotation->sent_at);
    }

    public function test_whatsapp_message_normalized_to_62_format(): void
    {
        $this->assertSame('628123456789', InvoiceDelivery::normalizeWhatsapp('08123456789'));
        $this->assertSame('628123456789', InvoiceDelivery::normalizeWhatsapp('+62 812-3456-789'));
        $this->assertSame('628123456789', InvoiceDelivery::normalizeWhatsapp('628123456789'));
    }

    public function test_whatsapp_action_dispatches_job(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        [$quotation] = $this->draftQuotation(['whatsapp' => '08123456789']);

        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => 't', 'crm.fonnte.endpoint' => 'https://api.fonnte.com/send']);
        \Illuminate\Support\Facades\Queue::fake();
        $response = $this->post(route('quotations.send-whatsapp', $quotation));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        \Illuminate\Support\Facades\Queue::assertPushed(SendQuotationWhatsappJob::class);
    }

    public function test_admin_send_whatsapp_blocked_when_terminal(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $quotation = QuotationFactory::new()->withItems()->create(['status' => QuotationStatus::Rejected]);

        \Illuminate\Support\Facades\Queue::fake();
        $this->post(route('quotations.send-whatsapp', $quotation))->assertSessionHas('error');
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }
}
