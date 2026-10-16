<?php

namespace Tests\Feature\Quotation;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Exceptions\InvalidQuotationTransition;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationConverter;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationConvertTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::factory()->create());
    }

    private function acceptedQuotation(array $attrs = [], array $items = []): Quotation
    {
        $quotation = QuotationFactory::new()->create($attrs);

        foreach ($items as $index => [$description, $qty, $price]) {
            $quotation->items()->create([
                'description' => $description,
                'quantity' => $qty,
                'unit_price' => $price,
                'amount' => $qty * $price,
                'sort_order' => $index,
            ]);
        }

        $quotation->recalculateTotals();
        $quotation->markSent();
        $quotation->markAccepted();

        return $quotation->fresh(['items']);
    }

    public function test_convert_rejects_non_accepted_status(): void
    {
        foreach ([QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Rejected, QuotationStatus::Expired] as $status) {
            $quotation = QuotationFactory::new()->withItems(500000)->create(['status' => QuotationStatus::Draft]);

            if ($status === QuotationStatus::Sent) {
                $quotation->markSent();
            } elseif ($status === QuotationStatus::Rejected) {
                $quotation->markSent();
                $quotation->markRejected();
            } elseif ($status === QuotationStatus::Expired) {
                $quotation->markSent();
                $quotation->markExpired();
            }

            $this->expectException(InvalidQuotationTransition::class);
            QuotationConverter::convert($quotation->fresh(['items']));
        }
    }

    public function test_convert_copies_items_one_to_one_and_totals_match(): void
    {
        $quotation = $this->acceptedQuotation([], [
            ['Pengembangan Website', 1, 2500000],
            ['Maintenance Bulanan', 3, 300000],
        ]);

        $invoice = QuotationConverter::convert($quotation);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($quotation->client_id, $invoice->client_id);
        $this->assertSame($quotation->title, $invoice->title);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('Pengembangan Website', $invoice->items[0]->description);
        $this->assertSame(1, $invoice->items[0]->quantity);
        $this->assertSame(2500000, $invoice->items[0]->unit_price);
        $this->assertSame(3, $invoice->items[1]->quantity);
        $this->assertSame(300000, $invoice->items[1]->unit_price);
        $this->assertSame($quotation->total, $invoice->total);
        $this->assertStringContainsString($quotation->number, $invoice->notes);
    }

    public function test_convert_distributes_discount_proportionally_with_rounding_to_last_item(): void
    {
        // Subtotal 3.400.000, diskon 400.000 -> total 3.000.000.
        // Porsi: 2.500.000/3.400.000*400.000 = 294.117,6 -> floor 294.117,
        // baris 2 = 105.883. Unit: 2.205.883 dan 264.706/264.705/264.706.
        $quotation = $this->acceptedQuotation(['discount' => 400000], [
            ['Pengembangan Website', 1, 2500000],
            ['Maintenance Bulanan', 3, 300000],
        ]);

        $this->assertSame(3400000, $quotation->subtotal);
        $this->assertSame(3000000, $quotation->total);

        $invoice = QuotationConverter::convert($quotation);

        $this->assertSame(3000000, $invoice->total);
        $this->assertStringContainsString($quotation->number, $invoice->notes);
        $this->assertStringContainsString('400.000', $invoice->notes);
    }

    public function test_convert_with_odd_discount_rounding_stays_exact(): void
    {
        // Diskon 7 rupiah atas 3 item — sisa pembulatan ke item terakhir.
        $quotation = $this->acceptedQuotation(['discount' => 7], [
            ['Item A', 1, 100000],
            ['Item B', 1, 100000],
            ['Item C', 1, 100000],
        ]);

        $invoice = QuotationConverter::convert($quotation);

        $this->assertSame($quotation->total, $invoice->total);
        $this->assertSame(299993, $invoice->total);
    }

    public function test_second_convert_is_rejected(): void
    {
        $quotation = $this->acceptedQuotation([], [
            ['Jasa Desain', 1, 1000000],
        ]);

        QuotationConverter::convert($quotation);

        $this->expectException(InvalidQuotationTransition::class);
        QuotationConverter::convert($quotation->fresh(['items']));
    }

    public function test_convert_creates_bidirectional_link(): void
    {
        $quotation = $this->acceptedQuotation([], [
            ['Jasa Desain', 1, 1000000],
        ]);

        $invoice = QuotationConverter::convert($quotation);

        $quotation = $quotation->fresh();
        $this->assertSame(QuotationStatus::Converted, $quotation->status);
        $this->assertSame($invoice->id, $quotation->converted_invoice_id);
        $this->assertSame($invoice->id, $quotation->convertedInvoice->id);
        $this->assertSame($quotation->id, $invoice->sourceQuotation->id);
    }

    public function test_convert_endpoint_only_from_accepted(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $draft = QuotationFactory::new()->create(['client_id' => $client->id]);
        $draft->items()->create([
            'description' => 'Jasa', 'quantity' => 1,
            'unit_price' => 100000, 'amount' => 100000, 'sort_order' => 0,
        ]);
        $draft->recalculateTotals();

        $this->post(route('quotations.convert', $draft))
            ->assertRedirect(route('quotations.show', $draft))
            ->assertSessionHas('error');
        $this->assertDatabaseCount('invoices', 0);

        $draft->markSent();
        $draft->markAccepted();

        $this->post(route('quotations.convert', $draft))
            ->assertRedirect();
        $this->assertDatabaseCount('invoices', 1);

        // Upaya kedua ditolak.
        $this->post(route('quotations.convert', $draft->fresh()))
            ->assertSessionHas('error');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_invoice_show_links_source_quotation(): void
    {
        $this->login();
        $quotation = $this->acceptedQuotation([], [
            ['Jasa Desain', 1, 1000000],
        ]);

        $invoice = QuotationConverter::convert($quotation);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee($quotation->number);
    }

    public function test_quotation_show_links_converted_invoice(): void
    {
        $this->login();
        $quotation = $this->acceptedQuotation([], [
            ['Jasa Desain', 1, 1000000],
        ]);

        $invoice = QuotationConverter::convert($quotation);

        $this->get(route('quotations.show', $quotation->fresh()))
            ->assertOk()
            ->assertSee($invoice->number);
    }
}
