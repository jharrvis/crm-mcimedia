<?php

namespace Tests\Feature\Quotation;

use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationNumber;
use Database\Factories\QuotationFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuotationNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_number_format_is_quo_yyyymm_sequence(): void
    {
        $number = QuotationNumber::next();

        $this->assertMatchesRegularExpression('/^QUO-\d{6}-\d{4}$/', $number);
        $this->assertStringStartsWith('QUO-'.now()->format('Ym').'-', $number);
    }

    public function test_first_quotation_of_month_gets_sequence_0001(): void
    {
        $this->assertSame('QUO-'.now()->format('Ym').'-0001', QuotationNumber::next());
    }

    public function test_sequence_increments_per_created_quotation(): void
    {
        $month = now()->format('Ym');

        $first = QuotationFactory::new()->create();
        $second = QuotationFactory::new()->create();

        $this->assertSame("QUO-{$month}-0001", $first->number);
        $this->assertSame("QUO-{$month}-0002", $second->number);
    }

    public function test_sequence_continues_from_latest_quotation_of_that_month(): void
    {
        QuotationFactory::new()->create(['number' => 'QUO-202601-0007']);

        $this->assertSame('QUO-202601-0008', QuotationNumber::next(Carbon::parse('2026-01-20')));
    }

    public function test_sequence_resets_for_a_new_month(): void
    {
        QuotationFactory::new()->create(['number' => 'QUO-202601-0007']);

        $this->assertSame('QUO-202602-0001', QuotationNumber::next(Carbon::parse('2026-02-01')));
    }

    public function test_number_column_is_unique(): void
    {
        $quotation = QuotationFactory::new()->create();

        $this->expectException(QueryException::class);
        Quotation::create($quotation->only([
            'client_id', 'number', 'issue_date', 'valid_until', 'status',
        ]));
    }
}