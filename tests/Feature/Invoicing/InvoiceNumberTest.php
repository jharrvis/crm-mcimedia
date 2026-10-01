<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumber;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_number_format_is_inv_yyyymm_sequence(): void
    {
        $number = InvoiceNumber::next();

        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{4}$/', $number);
        $this->assertStringStartsWith('INV-'.now()->format('Ym').'-', $number);
    }

    public function test_first_invoice_of_month_gets_sequence_0001(): void
    {
        $this->assertSame('INV-'.now()->format('Ym').'-0001', InvoiceNumber::next());
    }

    public function test_sequence_increments_per_created_invoice(): void
    {
        $month = now()->format('Ym');

        $first = InvoiceFactory::new()->create();
        $second = InvoiceFactory::new()->create();

        $this->assertSame("INV-{$month}-0001", $first->number);
        $this->assertSame("INV-{$month}-0002", $second->number);
    }

    public function test_sequence_continues_from_latest_invoice_of_that_month(): void
    {
        InvoiceFactory::new()->create(['number' => 'INV-202601-0007']);

        $this->assertSame('INV-202601-0008', InvoiceNumber::next(Carbon::parse('2026-01-20')));
    }

    public function test_sequence_resets_for_a_new_month(): void
    {
        InvoiceFactory::new()->create(['number' => 'INV-202601-0007']);

        $this->assertSame('INV-202602-0001', InvoiceNumber::next(Carbon::parse('2026-02-01')));
    }

    public function test_number_column_is_unique(): void
    {
        $invoice = InvoiceFactory::new()->create();

        $this->expectException(QueryException::class);
        Invoice::create($invoice->only([
            'client_id', 'number', 'issue_date', 'due_date', 'status',
        ]));
    }
}
