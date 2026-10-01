<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceItem;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculate_totals_sums_item_amounts(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $invoice->items()->create([
            'description' => 'Hosting Bisnis', 'quantity' => 2, 'unit_price' => 150000, 'amount' => 0,
        ]);
        $invoice->items()->create([
            'description' => 'Management Server', 'quantity' => 1, 'unit_price' => 500000, 'amount' => 0,
        ]);

        $invoice->recalculateTotals();

        $this->assertSame(300000, $invoice->items[0]->amount); // quantity * unit_price
        $this->assertSame(500000, $invoice->items[1]->amount);
        $this->assertSame(800000, $invoice->subtotal);
        $this->assertSame(800000, $invoice->total); // tanpa PPN: total = subtotal
    }

    public function test_recalculate_totals_fixes_stale_item_amount(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $invoice->items()->create([
            'description' => 'Domain', 'quantity' => 1, 'unit_price' => 150000, 'amount' => 999999,
        ]);

        $invoice->recalculateTotals();

        $this->assertSame(150000, $invoice->items()->first()->amount);
        $this->assertSame(150000, $invoice->fresh()->total);
    }

    public function test_mark_sent_sets_status_and_sent_at(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $this->assertNull($invoice->sent_at);

        $invoice->markSent();

        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_mark_paid_sets_status_and_paid_at(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $invoice->markSent();

        $invoice->markPaid();

        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_cancel_sets_status_cancelled(): void
    {
        $invoice = InvoiceFactory::new()->create();

        $invoice->cancel();

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
    }

    public function test_paid_invoice_is_terminal(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $invoice->markPaid();

        $this->expectException(InvalidInvoiceTransition::class);
        $invoice->markSent();
    }

    public function test_cancelled_invoice_is_terminal(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $invoice->cancel();

        $this->expectException(InvalidInvoiceTransition::class);
        $invoice->markSent();
    }

    public function test_paid_invoice_cannot_be_cancelled_or_repaid(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $invoice->markPaid();

        try {
            $invoice->cancel();
            $this->fail('cancel() seharusnya ditolak untuk invoice lunas.');
        } catch (InvalidInvoiceTransition) {
            // diharapkan
        }

        $this->expectException(InvalidInvoiceTransition::class);
        $invoice->markPaid();
    }

    public function test_unpaid_scope_returns_sent_and_overdue_only(): void
    {
        $sent = InvoiceFactory::new()->create(['status' => InvoiceStatus::Sent]);
        $overdue = InvoiceFactory::new()->create(['status' => InvoiceStatus::Overdue]);
        InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft]);
        InvoiceFactory::new()->create(['status' => InvoiceStatus::Paid]);
        InvoiceFactory::new()->create(['status' => InvoiceStatus::Cancelled]);

        $ids = Invoice::unpaid()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$sent->id, $overdue->id], $ids);
    }

    public function test_overdue_scope_includes_marked_overdue_and_past_due_sent(): void
    {
        $marked = InvoiceFactory::new()->create(['status' => InvoiceStatus::Overdue]);
        $pastSent = InvoiceFactory::new()->create([
            'status' => InvoiceStatus::Sent,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $futureSent = InvoiceFactory::new()->create([
            'status' => InvoiceStatus::Sent,
            'due_date' => now()->addWeek()->toDateString(),
        ]);
        $pastDraft = InvoiceFactory::new()->create([
            'status' => InvoiceStatus::Draft,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $ids = Invoice::overdue()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$marked->id, $pastSent->id], $ids);
        $this->assertNotContains($futureSent->id, $ids);
        $this->assertNotContains($pastDraft->id, $ids);
    }

    public function test_deleting_invoice_cascades_to_items_and_payments(): void
    {
        $invoice = InvoiceFactory::new()->withItems()->create();
        $payment = PaymentFactory::new()->create(['invoice_id' => $invoice->id]);

        $invoice->delete();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_deleting_client_cascades_to_invoices(): void
    {
        $client = ClientFactory::new()->create();
        $invoice = InvoiceFactory::new()->withItems()->create(['client_id' => $client->id]);

        $client->delete();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseCount('invoice_items', 0);
    }

    public function test_deleting_service_nulls_invoice_service_id(): void
    {
        $service = ServiceFactory::new()->create();
        $invoice = InvoiceFactory::new()->create([
            'client_id' => $service->client_id,
            'service_id' => $service->id,
        ]);

        $service->delete();

        $this->assertNull($invoice->fresh()->service_id);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_invoice_belongs_to_client_and_service(): void
    {
        $service = ServiceFactory::new()->create();
        $invoice = InvoiceFactory::new()->create([
            'client_id' => $service->client_id,
            'service_id' => $service->id,
        ]);

        $this->assertSame($service->client_id, $invoice->client->id);
        $this->assertSame($service->id, $invoice->service->id);
        $this->assertInstanceOf(InvoiceItem::class, InvoiceFactory::new()->withItems()->create()->items->first());
    }

    public function test_payment_belongs_to_invoice(): void
    {
        $invoice = InvoiceFactory::new()->create();
        $payment = PaymentFactory::new()->create(['invoice_id' => $invoice->id]);

        $this->assertSame($invoice->id, $payment->invoice->id);
        $this->assertTrue($invoice->payments()->whereKey($payment->id)->exists());
    }
}
