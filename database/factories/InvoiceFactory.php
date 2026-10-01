<?php

namespace Database\Factories;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumber;
use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $issueDate = Carbon::today();

        return [
            // NB: sama seperti ServiceFactory — model modular butuh referensi langsung.
            'client_id' => ClientFactory::new(),
            'number' => InvoiceNumber::next($issueDate),
            'title' => 'Invoice '.fake()->words(2, true),
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $issueDate->copy()->addDays(14)->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => 0,
            'total' => 0,
        ];
    }

    /** Invoice dengan satu item senilai $amount, total otomatis dihitung. */
    public function withItems(int $amount = 500000): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($amount) {
            $invoice->items()->create([
                'description' => fake()->words(3, true),
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'sort_order' => 0,
            ]);
            $invoice->recalculateTotals();
        });
    }

    public function forService(Service $service): static
    {
        return $this->state(fn () => [
            'client_id' => $service->client_id,
            'service_id' => $service->id,
        ]);
    }
}
