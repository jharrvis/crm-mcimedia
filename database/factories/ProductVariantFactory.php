<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['1GB', '2GB', '4GB']),
            'sku' => 'SKU-V'.fake()->unique()->numerify('####'),
            'sales_price' => fake()->randomElement([550000, 1100000, 2200000]),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
