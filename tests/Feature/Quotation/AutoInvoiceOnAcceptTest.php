<?php

namespace Tests\Feature\Quotation;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Jobs\SendInvoiceEmailJob;
use App\Domains\Invoicing\Jobs\SendInvoiceWhatsappJob;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationDelivery;
use Database\Factories\ClientFactory;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auto-buat & kirim invoice saat klien menyetujui penawaran (t_f983dec9).
 *
 * Klien klik "Setuju" di halaman publik -> quotation accepted lalu otomatis
 * dikonversi ke invoice (1 saja walau diklik ganda) dan job pengiriman
 * email/WhatsApp diantre. Kegagalan pembuatan invoice TIDAK boleh
 * menggagalkan respons "Setuju" ke klien.
 */
class AutoInvoiceOnAcceptTest extends TestCase
{
    use RefreshDatabase;

    /** Penawaran terkirim dengan tautan publik aktif. */
    private function sentQuotation(array $overrides = []): Quotation
    {
        return QuotationFactory::new()->withItems(800000)->create(array_merge([
            'status' => QuotationStatus::Sent,
            'public_token' => Str::random(64),
        ], $overrides));
    }

    public function test_accept_creates_invoice_and_queues_email_and_whatsapp_delivery(): void
    {
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => 'dummy-token']);
        Queue::fake([SendInvoiceEmailJob::class, SendInvoiceWhatsappJob::class]);

        $quotation = $this->sentQuotation();

        $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
            ->assertSessionHas('success');

        $quotation->refresh();
        $this->assertSame(QuotationStatus::Converted, $quotation->status);
        $this->assertNotNull($quotation->converted_invoice_id);

        $invoice = $quotation->convertedInvoice;
        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame($quotation->client_id, $invoice->client_id);
        $this->assertSame(800000, (int) $invoice->total);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertCount(1, $invoice->items()->get());

        Queue::assertPushed(SendInvoiceEmailJob::class, 1);
        Queue::assertPushed(SendInvoiceWhatsappJob::class, 1);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $invoice->getMorphClass(),
            'subject_id' => $invoice->id,
            'event' => QuotationDelivery::EVENT_INVOICE_AUTO_CREATED,
        ]);
    }

    public function test_accept_without_email_and_whatsapp_still_creates_invoice_and_skips_delivery(): void
    {
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => 'dummy-token']);
        Queue::fake([SendInvoiceEmailJob::class, SendInvoiceWhatsappJob::class]);

        $client = ClientFactory::new()->create(['email' => null, 'whatsapp' => null]);
        $quotation = $this->sentQuotation(['client_id' => $client->id]);

        $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
            ->assertSessionHas('success');

        $quotation->refresh();
        $this->assertSame(QuotationStatus::Converted, $quotation->status);
        $this->assertNotNull($quotation->converted_invoice_id);

        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        Queue::assertNotPushed(SendInvoiceWhatsappJob::class);
    }

    public function test_whatsapp_job_skipped_when_fonnte_not_configured(): void
    {
        config(['crm.fonnte.enabled' => false, 'crm.fonnte.token' => '']);
        Queue::fake([SendInvoiceEmailJob::class, SendInvoiceWhatsappJob::class]);

        $quotation = $this->sentQuotation();

        $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
            ->assertSessionHas('success');

        Queue::assertPushed(SendInvoiceEmailJob::class, 1);
        Queue::assertNotPushed(SendInvoiceWhatsappJob::class);
    }

    public function test_double_accept_creates_only_one_invoice(): void
    {
        Queue::fake([SendInvoiceEmailJob::class, SendInvoiceWhatsappJob::class]);

        $quotation = $this->sentQuotation();

        $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
            ->assertSessionHas('success');

        // Klik kedua: guard canBeAnswered menolak (status sudah converted).
        $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(QuotationStatus::Converted, $quotation->fresh()->status);
    }

    public function test_convert_failure_keeps_client_response_intact_and_logs_for_admin(): void
    {
        Queue::fake([SendInvoiceEmailJob::class, SendInvoiceWhatsappJob::class]);

        $quotation = $this->sentQuotation();

        // Simulasi kegagalan konversi: tabel invoice tidak tersedia.
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('invoices');

        $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
            ->assertSessionHas('success');

        $quotation->refresh();
        // Respons klien tetap sukses dan penawaran tercatat diterima.
        $this->assertSame(QuotationStatus::Accepted, $quotation->status);
        $this->assertNull($quotation->converted_invoice_id);

        // Admin diberi tahu lewat activity log supaya bisa buat invoice manual.
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $quotation->getMorphClass(),
            'subject_id' => $quotation->id,
            'event' => QuotationDelivery::EVENT_AUTO_INVOICE_FAILED,
        ]);

        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        Queue::assertNotPushed(SendInvoiceWhatsappJob::class);
    }
}