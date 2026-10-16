<?php

namespace Database\Factories;

use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationNumber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        $issueDate = Carbon::today();

        return [
            // NB: sama seperti InvoiceFactory — model modular butuh referensi langsung.
            'client_id' => ClientFactory::new(),
            'number' => QuotationNumber::next($issueDate),
            'title' => 'Penawaran '.fake()->words(2, true),
            'issue_date' => $issueDate->toDateString(),
            'valid_until' => $issueDate->copy()->addDays(30)->toDateString(),
            'status' => QuotationStatus::Draft,
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
        ];
    }

    /** Quotation dengan satu item senilai $amount, total otomatis dihitung. */
    public function withItems(int $amount = 500000): static
    {
        return $this->afterCreating(function (Quotation $quotation) use ($amount) {
            $quotation->items()->create([
                'description' => fake()->words(3, true),
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'sort_order' => 0,
            ]);
            $quotation->recalculateTotals();
        });
    }
}