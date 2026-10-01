<?php

namespace Database\Factories;

use App\Domains\Invoicing\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'amount' => fake()->randomElement([150000, 500000, 1200000]),
            'method' => 'bank_transfer',
            'status' => 'confirmed',
            'paid_at' => now(),
        ];
    }
}
