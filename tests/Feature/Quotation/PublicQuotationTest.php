<?php

namespace Tests\Feature\Quotation;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Invoicing\Jobs\SendInvoiceEmailJob;
use App\Domains\Invoicing\Jobs\SendInvoiceWhatsappJob;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Models\Quotation;
use Database\Factories\ClientFactory;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Q-2: halaman penawaran publik (magic link /q/{token}) — akses, respons
 * setuju/tolak klien, dan penandaan kedaluwarsa otomatis. Mirror pola
 * PublicInvoiceTest (F2-4).
 */
class PublicQuotationTest extends TestCase
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

    // ---------- akses halaman publik ----------

    public function test_valid_token_displays_quotation_detail(): void
    {
        $quotation = $this->sentQuotation();

        $response = $this->get(route('quotations.public.show', ['token' => $quotation->public_token]));

        $response->assertOk();
        $response->assertSee($quotation->number);
        $response->assertSee($quotation->client->name);
        $response->assertSee('Rp 800.000');
        $response->assertSee('Masa berlaku');
        $response->assertSee('Unduh PDF penawaran');
        // Penawaran sent & berlaku: tombol respons klien tampil.
        $response->assertSee('Setuju');
        $response->assertSee('Tolak');
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->get(route('quotations.public.show', ['token' => Str::random(64)]))->assertNotFound();
    }

    public function test_revoked_token_returns_404(): void
    {
        $quotation = $this->sentQuotation();
        $token = $quotation->public_token;

        $quotation->update(['public_token' => null]);

        $this->get(route('quotations.public.show', ['token' => $token]))->assertNotFound();
    }

    public function test_draft_quotation_without_token_is_not_accessible(): void
    {
        $quotation = QuotationFactory::new()->withItems()->create(['status' => QuotationStatus::Draft]);

        $this->assertNull($quotation->public_token);
        // Tidak ada rute alternatif untuk draf tanpa token.
        $this->get(route('quotations.public.show', ['token' => 'draft-no-token']))->assertNotFound();
    }

    // ---------- token saat dikirim ----------

    public function test_mark_sent_generates_public_token_once(): void
    {
        $quotation = QuotationFactory::new()->create();

        $quotation->markSent();

        $this->assertNotNull($quotation->public_token);
        $this->assertSame(64, strlen($quotation->public_token));

        $token = $quotation->public_token;
        $quotation->markSent(); // kirim ulang: token tidak diganti
        $this->assertSame($token, $quotation->public_token);
    }

    // ---------- respons klien setuju/tolak ----------

    public function test_accept_from_sent_marks_accepted_and_logs_activity(): void
    {
        // Job auto-invoice di-fake supaya tes publik ini hermetik
        // (perilaku auto-invoice diuji terpisah di AutoInvoiceOnAcceptTest).
        Queue::fake([SendInvoiceEmailJob::class, SendInvoiceWhatsappJob::class]);

        $quotation = $this->sentQuotation();

        $response = $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]));

        $response->assertSessionHas('success');
        $quotation->refresh();
        // Sejak auto-invoice (t_f983dec9), persetujuan klien langsung
        // mengonversi penawaran menjadi invoice (status akhir converted).
        $this->assertSame(QuotationStatus::Converted, $quotation->status);
        $this->assertNotNull($quotation->responded_at);
        $this->assertNotNull($quotation->converted_invoice_id);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $quotation->getMorphClass(),
            'subject_id' => $quotation->id,
            'event' => 'accepted',
        ]);
    }

    public function test_reject_from_sent_marks_rejected_and_logs_activity(): void
    {
        $quotation = $this->sentQuotation();

        $this->post(route('quotations.public.reject', ['token' => $quotation->public_token]))
            ->assertSessionHas('success');

        $quotation->refresh();
        $this->assertSame(QuotationStatus::Rejected, $quotation->status);
        $this->assertNotNull($quotation->responded_at);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $quotation->getMorphClass(),
            'subject_id' => $quotation->id,
            'event' => 'rejected',
        ]);
    }

    public function test_respond_blocked_from_terminal_status(): void
    {
        $accepted = $this->sentQuotation(['status' => QuotationStatus::Accepted]);
        $rejected = $this->sentQuotation(['status' => QuotationStatus::Rejected]);
        $expired = $this->sentQuotation(['status' => QuotationStatus::Expired]);

        foreach ([$accepted, $rejected, $expired] as $quotation) {
            $original = $quotation->status;

            $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
                ->assertSessionHas('error');
            $this->assertSame(
                $original,
                $quotation->fresh()->status,
                "Penawaran {$quotation->number} berstatus {$original->label()} tidak boleh berubah lewat tombol publik."
            );
        }
    }

    public function test_terminal_page_shows_status_without_action_buttons(): void
    {
        $quotation = $this->sentQuotation(['status' => QuotationStatus::Accepted]);

        $response = $this->get(route('quotations.public.show', ['token' => $quotation->public_token]));

        $response->assertOk();
        $response->assertSee('Disetujui', false);
        $response->assertDontSee(route('quotations.public.accept', ['token' => $quotation->public_token]));
    }

    // ---------- kedaluwarsa otomatis ----------

    public function test_opening_page_after_valid_until_expires_sent_quotation(): void
    {
        $quotation = $this->sentQuotation(['valid_until' => now()->subDay()->toDateString()]);

        $response = $this->get(route('quotations.public.show', ['token' => $quotation->public_token]));

        $response->assertOk();
        $this->assertSame(QuotationStatus::Expired, $quotation->fresh()->status);
        $response->assertSee('kedaluwarsa', false);
        $response->assertDontSee(route('quotations.public.accept', ['token' => $quotation->public_token]));
    }

    public function test_opening_page_after_valid_until_keeps_accepted_quotation_intact(): void
    {
        $quotation = $this->sentQuotation([
            'status' => QuotationStatus::Accepted,
            'valid_until' => now()->subDays(5)->toDateString(),
        ]);

        $this->get(route('quotations.public.show', ['token' => $quotation->public_token]))->assertOk();

        // Status final tidak ditimpa jadi expired oleh kunjungan lama.
        $this->assertSame(QuotationStatus::Accepted, $quotation->fresh()->status);
    }

    public function test_respond_after_valid_until_is_blocked_even_if_page_not_opened(): void
    {
        $quotation = $this->sentQuotation(['valid_until' => now()->subDay()->toDateString()]);

        $this->post(route('quotations.public.accept', ['token' => $quotation->public_token]))
            ->assertSessionHas('error');

        $this->assertSame(QuotationStatus::Sent, $quotation->fresh()->status);
    }

    // ---------- privasi ----------

    public function test_public_page_does_not_leak_private_admin_data(): void
    {
        $quotation = $this->sentQuotation();

        $response = $this->get(route('quotations.public.show', ['token' => $quotation->public_token]));

        $response->assertOk();
        $response->assertDontSee('converted_invoice_id');
        $response->assertDontSee('Terima & buat invoice');
        // PDF/admin URL tidak tersebar di halaman publik.
        $response->assertDontSee(route('quotations.pdf', $quotation));
    }
}
