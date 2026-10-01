<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => 'SKU'.fake()->unique()->numerify('####'),
            'name' => fake()->words(3, true),
            'description' => null,
            'sales_price' => fake()->randomElement([75000, 150000, 250000, 500000, 1500000]),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
