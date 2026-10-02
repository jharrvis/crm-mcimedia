<?php

namespace Database\Factories;

use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory paket recurring (F4-11).
 *
 * Sengaja TIDAK membuat item: paket kosong tidak menghasilkan invoice
 * (generator melewatinya). Gunakan `withItems()` untuk paket siap generate.
 */
class RecurringPlanFactory extends Factory
{
    protected $model = RecurringPlan::class;

    public function definition(): array
    {
        return [
            'client_id' => ClientFactory::new(),
            'service_id' => null,
            'title' => 'Paket '.fake()->words(2, true),
            'cycle' => RecurringCycle::Monthly,
            'next_invoice_date' => Carbon::today()->toDateString(),
            'total' => 0,
            'active' => true,
            'auto_send' => false,
            'due_days' => 14,
            'notes' => null,
        ];
    }

    /** Paket dengan satu item; total invoice hasil generate = amount. */
    public function withItems(int $amount = 750000, string $description = 'Langganan'): static
    {
        return $this->afterCreating(function (RecurringPlan $plan) use ($amount, $description) {
            $plan->items()->create([
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $amount,
                'sort_order' => 0,
            ]);

            $plan->forceFill(['total' => $amount])->save();
        });
    }

    public function cycle(RecurringCycle $cycle): static
    {
        return $this->state(fn () => ['cycle' => $cycle]);
    }

    /** Paket yang periodenya jatuh tempo pada tanggal tertentu. */
    public function dueOn(Carbon $date): static
    {
        return $this->state(fn () => ['next_invoice_date' => $date->toDateString()]);
    }

    /** Paket yang tertaut ke sebuah layanan (opsional). */
    public function forService(Service $service): static
    {
        return $this->state(fn () => ['service_id' => $service->id]);
    }

    /** Masa tenggat invoice hasil generate (hari dari tanggal terbit). */
    public function dueDays(int $days): static
    {
        return $this->state(fn () => ['due_days' => $days]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function autoSend(bool $autoSend = true): static
    {
        return $this->state(fn () => ['auto_send' => $autoSend]);
    }
}