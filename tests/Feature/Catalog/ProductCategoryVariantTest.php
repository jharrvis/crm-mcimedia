<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductCategory;
use App\Domains\Catalog\Models\ProductVariant;
use App\Models\User;
use Database\Factories\ProductCategoryFactory;
use Database\Factories\ProductFactory;
use Database\Seeders\ProductCategorySeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UX-1: kategori produk (CRUD + filter) & varian produk (CRUD di form produk).
 * Backward compatible: produk lama tanpa varian tetap jalan.
 */
class ProductCategoryVariantTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    // ---------- kategori: CRUD ----------

    public function test_guest_redirected_from_categories(): void
    {
        $this->get(route('product-categories.index'))->assertRedirect(route('login'));
    }

    public function test_create_and_store_category(): void
    {
        $this->login();

        $this->get(route('product-categories.create'))->assertOk()->assertSee('Nama kategori');

        $response = $this->post(route('product-categories.store'), [
            'name' => 'Hosting',
            'sort_order' => 1,
        ]);

        $response->assertRedirect(route('product-categories.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('product_categories', ['name' => 'Hosting', 'slug' => 'hosting']);
    }

    public function test_store_rejects_duplicate_category_name(): void
    {
        $this->login();
        ProductCategoryFactory::new()->create(['name' => 'Hosting', 'slug' => 'hosting']);

        $this->post(route('product-categories.store'), ['name' => 'Hosting'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('product_categories', 1);
    }

    public function test_store_requires_category_name(): void
    {
        $this->login();

        $this->post(route('product-categories.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('product_categories', 0);
    }

    public function test_update_category_name_regenerates_slug(): void
    {
        $this->login();
        $category = ProductCategoryFactory::new()->create(['name' => 'Hosting', 'slug' => 'hosting']);

        $this->get(route('product-categories.edit', $category))->assertOk()->assertSee('Hosting');

        $this->put(route('product-categories.update', $category), ['name' => 'Server Hosting'])
            ->assertRedirect(route('product-categories.index'));

        $this->assertSame('Server Hosting', $category->fresh()->name);
        $this->assertSame('server-hosting', $category->fresh()->slug);
    }

    public function test_index_lists_categories_with_product_count(): void
    {
        $this->login();
        $category = ProductCategoryFactory::new()->create(['name' => 'Hosting Unik']);
        ProductFactory::new()->count(2)->create(['category_id' => $category->id]);

        $this->get(route('product-categories.index'))
            ->assertOk()
            ->assertSee('Hosting Unik')
            ->assertSee('2 produk');
    }

    public function test_delete_category_keeps_products_but_clears_category(): void
    {
        $this->login();
        $category = ProductCategoryFactory::new()->create(['name' => 'Hosting']);
        $product = ProductFactory::new()->create(['category_id' => $category->id, 'name' => 'Produk Tetap Ada']);

        $this->delete(route('product-categories.destroy', $category))
            ->assertRedirect(route('product-categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
        // Produk tidak ikut terhapus; kategorinya dilepas (FK nullOnDelete).
        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => null]);
    }

    // ---------- produk: kategori ----------

    public function test_store_product_with_category(): void
    {
        $this->login();
        $category = ProductCategoryFactory::new()->create(['name' => 'Domain']);

        $this->post(route('products.store'), [
            'name' => 'Domain .com',
            'category_id' => $category->id,
            'sales_price' => 250000,
            'is_active' => '1',
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['name' => 'Domain .com', 'category_id' => $category->id]);
    }

    public function test_store_rejects_unknown_category(): void
    {
        $this->login();

        $this->post(route('products.store'), ['name' => 'Produk', 'category_id' => 9999])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_index_filters_products_by_category(): void
    {
        $this->login();
        $hosting = ProductCategoryFactory::new()->create(['name' => 'Hosting']);
        $domain = ProductCategoryFactory::new()->create(['name' => 'Domain']);

        ProductFactory::new()->create(['name' => 'Hosting Unik 1GB', 'category_id' => $hosting->id]);
        ProductFactory::new()->create(['name' => 'Domain Unik .com', 'category_id' => $domain->id]);
        ProductFactory::new()->create(['name' => 'Tanpa Kategori Unik', 'category_id' => null]);

        $this->get(route('products.index', ['category_id' => $hosting->id]))
            ->assertOk()
            ->assertSee('Hosting Unik 1GB')
            ->assertDontSee('Domain Unik .com')
            ->assertDontSee('Tanpa Kategori Unik');

        // Filter "0" = produk tanpa kategori.
        $this->get(route('products.index', ['category_id' => '0']))
            ->assertOk()
            ->assertSee('Tanpa Kategori Unik')
            ->assertDontSee('Domain Unik .com');

        // Tanpa filter = semua kategori.
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Hosting Unik 1GB')
            ->assertSee('Domain Unik .com')
            ->assertSee('Tanpa Kategori Unik');
    }

    public function test_index_search_also_matches_variant_name(): void
    {
        $this->login();
        $product = ProductFactory::new()->create(['name' => 'Hosting SG']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'VarianCari Unik 4GB']);
        ProductFactory::new()->create(['name' => 'Produk Lain']);

        $this->get(route('products.index', ['q' => 'VarianCari Unik']))
            ->assertOk()
            ->assertSee('Hosting SG')
            ->assertDontSee('Produk Lain');
    }

    public function test_index_shows_category_and_variant_count(): void
    {
        $this->login();
        $category = ProductCategoryFactory::new()->create(['name' => 'Hosting K Categori']);
        $product = ProductFactory::new()->create(['category_id' => $category->id]);
        ProductVariant::factory()->count(2)->create(['product_id' => $product->id]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Hosting K Categori')
            ->assertSee('2 varian');
    }

    // ---------- varian: CRUD di form produk ----------

    public function test_edit_form_renders_existing_variants(): void
    {
        $this->login();
        $product = ProductFactory::new()->create(['name' => 'Hosting SG']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'VarianTampil Unik 1GB']);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('VarianTampil Unik 1GB')
            ->assertSee('Varian produk');
    }

    public function test_update_product_creates_updates_and_deletes_variants(): void
    {
        $this->login();
        $product = ProductFactory::new()->create(['name' => 'Hosting SG', 'sales_price' => 1000000]);

        $keep = ProductVariant::factory()->create([
            'product_id' => $product->id, 'name' => '1GB', 'sku' => 'V-KEEP', 'sales_price' => 550000,
        ]);
        $drop = ProductVariant::factory()->create([
            'product_id' => $product->id, 'name' => 'Hapus', 'sku' => 'V-DROP', 'sales_price' => 1,
        ]);

        $response = $this->put(route('products.update', $product), [
            'name' => 'Hosting SG',
            'sales_price' => 1000000,
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [
                ['id' => $keep->id, 'name' => '1GB', 'sku' => 'V-KEEP', 'sales_price' => 600000, 'is_active' => '1'],
                ['name' => '2GB', 'sku' => 'V-BARU', 'sales_price' => 1100000, 'is_active' => '1'],
            ],
        ]);

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('success');

        // Varian lama yang dihapus hilang.
        $this->assertDatabaseMissing('product_variants', ['id' => $drop->id]);

        // Varian yang dipertahankan diperbarui (harga berubah tersimpan).
        $this->assertDatabaseHas('product_variants', ['id' => $keep->id, 'sales_price' => 600000]);

        // Varian baru dibuat dengan harga sendiri.
        $new = $product->variants()->where('sku', 'V-BARU')->first();
        $this->assertNotNull($new);
        $this->assertSame('2GB', $new->name);
        $this->assertSame(1100000, $new->sales_price);

        $this->assertSame(2, $product->variants()->count());
    }

    public function test_update_product_without_variant_field_keeps_variants(): void
    {
        $this->login();
        $product = ProductFactory::new()->create(['name' => 'Hosting SG']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => '1GB']);

        // Request lama (tanpa seksi varian) tidak boleh menghapus varian.
        $this->put(route('products.update', $product), ['name' => 'Hosting SG Baru', 'is_active' => '1'])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
        $this->assertSame('Hosting SG Baru', $product->fresh()->name);
    }

    public function test_update_rejects_variant_from_other_product(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();
        $other = ProductFactory::new()->create();
        $foreign = ProductVariant::factory()->create(['product_id' => $other->id, 'name' => 'Varian Asing']);

        $this->put(route('products.update', $product), [
            'name' => 'Produk',
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [['id' => $foreign->id, 'name' => 'Dibajak', 'sales_price' => 1]],
        ])->assertSessionHasErrors('variants');

        $this->assertDatabaseHas('product_variants', ['id' => $foreign->id, 'name' => 'Varian Asing']);
    }

    public function test_update_rejects_duplicate_variant_sku_in_form(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();

        $this->put(route('products.update', $product), [
            'name' => 'Produk',
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [
                ['name' => '1GB', 'sku' => 'V-DUP', 'sales_price' => 100],
                ['name' => '2GB', 'sku' => 'V-DUP', 'sales_price' => 200],
            ],
        ])->assertSessionHasErrors('variants');

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_update_rejects_variant_sku_used_by_other_variant(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();
        $other = ProductFactory::new()->create();
        ProductVariant::factory()->create(['product_id' => $other->id, 'sku' => 'V-TAKUTAKUT']);

        $this->put(route('products.update', $product), [
            'name' => 'Produk',
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [['name' => '1GB', 'sku' => 'V-TAKUTAKUT', 'sales_price' => 100]],
        ])->assertSessionHasErrors('variants');

        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_update_keeps_own_variant_sku_without_error(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'V-PUNYA-SENDIRI', 'sales_price' => 100,
        ]);

        $this->put(route('products.update', $product), [
            'name' => $product->name,
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [['id' => $variant->id, 'name' => '1GB', 'sku' => 'V-PUNYA-SENDIRI', 'sales_price' => 250]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sales_price' => 250]);
    }

    public function test_update_rejects_negative_and_missing_variant_price(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();

        $this->put(route('products.update', $product), [
            'name' => 'Produk',
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [['name' => '1GB', 'sales_price' => -5]],
        ])->assertSessionHasErrors('variants.0.sales_price');

        $this->put(route('products.update', $product), [
            'name' => 'Produk',
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [['name' => '1GB']],
        ])->assertSessionHasErrors('variants.0.sales_price');

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_blank_variant_row_is_ignored(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();

        $this->put(route('products.update', $product), [
            'name' => 'Produk',
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [
                ['name' => '', 'sku' => '', 'sales_price' => ''],
                ['name' => '1GB', 'sales_price' => 750000],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $product->variants()->count());
    }

    public function test_store_product_with_variants_in_one_request(): void
    {
        $this->login();
        $category = ProductCategoryFactory::new()->create(['name' => 'Hosting']);

        $this->post(route('products.store'), [
            'name' => 'Hosting SG',
            'category_id' => $category->id,
            'sales_price' => 0,
            'is_active' => '1',
            'variants_sync' => '1',
            'variants' => [
                ['name' => '1GB', 'sku' => 'V-NEW-1', 'sales_price' => 550000],
                ['name' => '2GB', 'sku' => 'V-NEW-2', 'sales_price' => 1100000],
            ],
        ])->assertRedirect(route('products.index'));

        $product = Product::where('name', 'Hosting SG')->firstOrFail();
        $this->assertSame(2, $product->variants()->count());
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'V-NEW-2', 'sales_price' => 1100000]);
    }

    public function test_deleting_product_deletes_its_variants(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();
        ProductVariant::factory()->count(2)->create(['product_id' => $product->id]);

        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseCount('product_variants', 0);
    }

    // ---------- backward compatibility ----------

    public function test_legacy_product_without_variants_still_works(): void
    {
        $this->login();
        $legacy = ProductFactory::new()->create([
            'name' => 'Produk Lama Tanpa Varian',
            'category_id' => null,
            'sku' => 'SKU-LAMA',
            'sales_price' => 350000,
        ]);

        $this->assertSame(0, $legacy->variants()->count());

        $this->get(route('products.index'))->assertOk()->assertSee('Produk Lama Tanpa Varian')->assertSee(rupiah(350000));
        $this->get(route('products.edit', $legacy))->assertOk()->assertSee('Belum ada varian');
        $this->get(route('products.create'))->assertOk();

        // Update lama (tanpa seksi varian) tetap menyimpan produk.
        $this->put(route('products.update', $legacy), ['name' => 'Produk Lama Diubah', 'sales_price' => 400000, 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $legacy->id, 'name' => 'Produk Lama Diubah']);
    }

    public function test_legacy_product_still_appears_in_invoice_picker_with_variants_present_elsewhere(): void
    {
        $this->login();
        $legacy = ProductFactory::new()->create(['name' => 'Produk Picker Lama', 'sales_price' => 120000, 'is_active' => true]);

        $withVariants = ProductFactory::new()->create(['name' => 'Produk Paker Varian', 'sales_price' => 900000, 'is_active' => true]);
        ProductVariant::factory()->count(2)->create(['product_id' => $withVariants->id]);

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('Produk Picker Lama')
            ->assertSee('data-price="120000"', false)
            ->assertSee('Produk Paker Varian')
            ->assertSee('data-price="900000"', false);
    }

    // ---------- seeder ----------

    public function test_category_seeder_is_idempotent_and_seeds_example_variants(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductCategorySeeder::class);

        $this->assertDatabaseCount('product_categories', 3);
        $this->assertDatabaseHas('product_categories', ['slug' => 'hosting', 'name' => 'Hosting']);
        $this->assertDatabaseHas('product_categories', ['slug' => 'domain', 'name' => 'Domain']);
        $this->assertDatabaseHas('product_categories', ['slug' => 'jasa', 'name' => 'Jasa']);

        $hosting = Product::with('variants')->where('sku', 'SKU-HOST-SG')->firstOrFail();
        $this->assertSame(3, $hosting->variants->count());
        $this->assertDatabaseHas('product_variants', ['product_id' => $hosting->id, 'name' => '1GB', 'sales_price' => 550000]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $hosting->id, 'name' => '2GB', 'sales_price' => 1100000]);

        // Produk lama dipetakan ke kategori sesuai kata kunci.
        $this->assertSame('hosting', Product::where('sku', 'SKU0012')->firstOrFail()->category?->slug);
        $this->assertSame('domain', Product::where('sku', 'SKU0005')->firstOrFail()->category?->slug);
        $this->assertSame('jasa', Product::where('sku', 'SKU0001')->firstOrFail()->category?->slug);

        // Jalankan lagi: tidak duplikat, pilihan admin tidak ditimpa.
        $this->seed(ProductCategorySeeder::class);

        $this->assertDatabaseCount('product_categories', 3);
        $this->assertSame(3, $hosting->fresh()->variants()->count());
    }
}