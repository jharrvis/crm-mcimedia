<?php

namespace Database\Seeders;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Kategori produk + pemetaan awal produk (UX-1).
 * Idempotent: updateOrCreate by slug untuk kategori, isi category_id hanya bila
 * masih kosong (tidak menimpa pilihan admin), varian contoh hanya dibuat bila
 * produk belum punya varian.
 */
class ProductCategorySeeder extends Seeder
{
    private const CATEGORIES = [
        ['slug' => 'hosting', 'name' => 'Hosting', 'sort_order' => 1],
        ['slug' => 'domain', 'name' => 'Domain', 'sort_order' => 2],
        ['slug' => 'jasa', 'name' => 'Jasa', 'sort_order' => 3],
    ];

    /** Kata di nama produk → slug kategori. Pemetaan konservatif (hosting/domain/jasa). */
    private const KEYWORDS = [
        'hosting' => 'hosting',
        'vps' => 'hosting',
        'domain' => 'domain',
        'desain' => 'jasa',
        'maintenance' => 'jasa',
        'instalasi' => 'jasa',
        'chatgpt' => 'jasa',
        'elevenlab' => 'jasa',
        'google drive' => 'jasa',
    ];

    public function run(): void
    {
        $categories = [];
        foreach (self::CATEGORIES as $c) {
            $categories[$c['slug']] = ProductCategory::updateOrCreate(
                ['slug' => $c['slug']],
                ['name' => $c['name'], 'sort_order' => $c['sort_order']],
            );
        }

        // Isi category_id hanya untuk produk yang masih null (tidak timpa pilihan admin).
        Product::query()->whereNull('category_id')->get()->each(function (Product $product) use ($categories) {
            $slug = $this->inferSlug($product->name);
            if ($slug !== null && isset($categories[$slug])) {
                $product->update(['category_id' => $categories[$slug]->id]);
            }
        });

        // Contoh produk dengan varian (UX-1): "Hosting SG" 1GB/2GB/4GB.
        $hosting = Product::updateOrCreate(
            ['sku' => 'SKU-HOST-SG'],
            [
                'name' => 'Hosting SG',
                'description' => 'Paket hosting bersama di Singapura',
                'sales_price' => 0, // harga melalui varian
                'is_active' => true,
                'category_id' => $categories['hosting']->id ?? null,
            ],
        );

        if ($hosting->variants()->count() === 0) {
            foreach ([['1GB', 550000], ['2GB', 1100000], ['4GB', 2200000]] as $i => [$name, $price]) {
                $hosting->variants()->create([
                    'name' => $name,
                    'sku' => "SKU-HOST-SG-".Str::slug($name),
                    'sales_price' => $price,
                    'is_active' => true,
                    'sort_order' => $i,
                ]);
            }
        }

        $this->command->info('Kategori produk + contoh varian siap.');
    }

    private function inferSlug(string $name): ?string
    {
        $name = strtolower($name);
        foreach (self::KEYWORDS as $keyword => $slug) {
            if (str_contains($name, $keyword)) {
                return $slug;
            }
        }

        return null;
    }
}
