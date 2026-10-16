<?php

namespace Tests\Feature\Quotation;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Exceptions\InvalidQuotationTransition;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Models\QuotationItem;
use Database\Factories\InvoiceFactory;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    // ---------- Total & kalkulasi ----------

    public function test_recalculate_totals_sums_item_amounts(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->items()->create([
            'description' => 'Hosting Bisnis', 'quantity' => 2, 'unit_price' => 150000, 'amount' => 0,
        ]);
        $quotation->items()->create([
            'description' => 'Management Server', 'quantity' => 1, 'unit_price' => 500000, 'amount' => 0,
        ]);

        $quotation->recalculateTotals();

        $this->assertSame(300000, $quotation->items[0]->amount); // quantity * unit_price
        $this->assertSame(500000, $quotation->items[1]->amount);
        $this->assertSame(800000, $quotation->subtotal);
        $this->assertSame(800000, $quotation->total); // tanpa diskon: total = subtotal
    }

    public function test_discount_reduces_total(): void
    {
        $quotation = QuotationFactory::new()->create(['discount' => 200000]);
        $quotation->items()->create([
            'description' => 'Paket Website', 'quantity' => 1, 'unit_price' => 1500000, 'amount' => 0,
        ]);

        $quotation->recalculateTotals();

        $this->assertSame(1500000, $quotation->subtotal);
        $this->assertSame(1300000, $quotation->total);
    }

    public function test_total_clamped_to_zero_when_discount_exceeds_subtotal(): void
    {
        $quotation = QuotationFactory::new()->create(['discount' => 5000000]);
        $quotation->items()->create([
            'description' => 'Domain', 'quantity' => 1, 'unit_price' => 150000, 'amount' => 0,
        ]);

        $quotation->recalculateTotals();

        $this->assertSame(150000, $quotation->subtotal);
        $this->assertSame(0, $quotation->total); // tidak boleh negatif
    }

    public function test_recalculate_totals_fixes_stale_item_amount(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->items()->create([
            'description' => 'Domain', 'quantity' => 1, 'unit_price' => 150000, 'amount' => 999999,
        ]);

        $quotation->recalculateTotals();

        $this->assertSame(150000, $quotation->items()->first()->amount);
        $this->assertSame(150000, $quotation->fresh()->total);
    }

    // ---------- Transisi status ----------

    public function test_mark_sent_sets_status_and_sent_at(): void
    {
        $quotation = QuotationFactory::new()->create();
        $this->assertNull($quotation->sent_at);

        $quotation->markSent();

        $this->assertSame(QuotationStatus::Sent, $quotation->status);
        $this->assertNotNull($quotation->sent_at);
    }

    public function test_mark_accepted_sets_status_and_responded_at(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->markSent();

        $quotation->markAccepted();

        $this->assertSame(QuotationStatus::Accepted, $quotation->status);
        $this->assertNotNull($quotation->responded_at);
    }

    public function test_mark_rejected_sets_status_and_responded_at(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->markSent();

        $quotation->markRejected();

        $this->assertSame(QuotationStatus::Rejected, $quotation->status);
        $this->assertNotNull($quotation->responded_at);
    }

    public function test_mark_expired_sets_status(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->markSent();

        $quotation->markExpired();

        $this->assertSame(QuotationStatus::Expired, $quotation->status);
    }

    public function test_mark_converted_links_invoice(): void
    {
        // Q-3: konversi hanya dari status accepted (disetujui klien).
        $quotation = QuotationFactory::new()->create();
        $quotation->markSent();
        $quotation->markAccepted();

        $invoice = InvoiceFactory::new()->create();

        $quotation->markConverted($invoice);

        $this->assertSame(QuotationStatus::Converted, $quotation->status);
        $this->assertSame($invoice->id, $quotation->converted_invoice_id);
        $this->assertTrue($quotation->convertedInvoice instanceof Invoice);
    }

    public function test_accepted_is_terminal(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->markSent();
        $quotation->markAccepted();

        $this->expectException(InvalidQuotationTransition::class);
        $quotation->markRejected();
    }

    public function test_rejected_is_terminal(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->markSent();
        $quotation->markRejected();

        $this->expectException(InvalidQuotationTransition::class);
        $quotation->markAccepted();
    }

    public function test_expired_is_terminal(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->markExpired();

        $this->expectException(InvalidQuotationTransition::class);
        $quotation->markSent();
    }

    public function test_converted_is_terminal(): void
    {
        $quotation = QuotationFactory::new()->create();
        $quotation->markSent();
        $quotation->markAccepted();
        $quotation->markConverted(InvoiceFactory::new()->create());

        $this->expectException(InvalidQuotationTransition::class);
        $quotation->markAccepted();
    }

    // ---------- Scope ----------

    public function test_scope_pending_only_sent(): void
    {
        QuotationFactory::new()->create(); // draft
        QuotationFactory::new()->create()->markSent();
        $rejected = QuotationFactory::new()->create();
        $rejected->markSent();
        $rejected->markRejected();

        $this->assertSame(1, Quotation::pending()->count());
        $this->assertSame(QuotationStatus::Sent, Quotation::pending()->first()->status);
    }

    public function test_scope_actionable_draft_and_sent(): void
    {
        QuotationFactory::new()->create(); // draft
        QuotationFactory::new()->create()->markSent();
        $accepted = QuotationFactory::new()->create();
        $accepted->markSent();
        $accepted->markAccepted();

        $this->assertSame(2, Quotation::actionable()->count());
    }

    // ---------- Cascade delete ----------

    public function test_deleting_quotation_cascades_to_items(): void
    {
        $quotation = QuotationFactory::new()->create();
        QuotationItem::factory()->count(3)->create(['quotation_id' => $quotation->id]);

        $this->assertSame(3, QuotationItem::count());

        $quotation->delete();

        $this->assertSame(0, QuotationItem::count());
    }
}
