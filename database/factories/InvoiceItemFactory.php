<?php

namespace Database\Factories;

use App\Domains\Invoicing\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomElement([150000, 300000, 500000, 1200000]);

        return [
            'invoice_id' => InvoiceFactory::new(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $quantity * $unitPrice,
            'sort_order' => 0,
        ];
    }
}
