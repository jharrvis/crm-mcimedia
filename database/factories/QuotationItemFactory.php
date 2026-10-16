<?php

namespace Database\Factories;

use App\Domains\Quotation\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuotationItemFactory extends Factory
{
    protected $model = QuotationItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomElement([150000, 300000, 500000, 1200000]);

        return [
            'quotation_id' => QuotationFactory::new(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $quantity * $unitPrice,
            'sort_order' => 0,
        ];
    }
}