<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Database\Factories\ProductFactory;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    // ---------- akses ----------

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('products.index'))->assertRedirect(route('login'));
    }

    // ---------- index ----------

    public function test_index_lists_products(): void
    {
        $this->login();
        $product = ProductFactory::new()->create(['name' => 'Hosting 1GB (SG)', 'sku' => 'SKU0012']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Hosting 1GB (SG)')
            ->assertSee('SKU0012')
            ->assertSee(rupiah($product->sales_price));
    }

    public function test_index_search_by_name_and_sku(): void
    {
        $this->login();
        $found = ProductFactory::new()->create(['name' => 'Domain web.id', 'sku' => 'SKU0002']);
        $hidden = ProductFactory::new()->create(['name' => 'VPS Besar', 'sku' => 'SKU9999']);

        $this->get(route('products.index', ['q' => 'web.id']))
            ->assertOk()
            ->assertSee('Domain web.id')
            ->assertDontSee('VPS Besar');

        $this->get(route('products.index', ['q' => 'SKU0002']))
            ->assertOk()
            ->assertSee($found->sku)
            ->assertDontSee($hidden->sku);
    }

    public function test_index_filter_by_status(): void
    {
        $this->login();
        ProductFactory::new()->create(['name' => 'Produk Aktif', 'is_active' => true]);
        ProductFactory::new()->create(['name' => 'Produk Nonaktif', 'is_active' => false]);

        $this->get(route('products.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Produk Nonaktif')
            ->assertDontSee('Produk Aktif');
    }

    // ---------- create / store ----------

    public function test_create_page_renders(): void
    {
        $this->login();

        $this->get(route('products.create'))->assertOk()->assertSee('Nama produk');
    }

    public function test_store_creates_product(): void
    {
        $this->login();

        $response = $this->post(route('products.store'), [
            'name' => 'Hosting SG 500MB',
            'sku' => 'SKU0006',
            'description' => 'Hosting tahunan',
            'sales_price' => 550000,
            'sort_order' => 3,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU0006',
            'name' => 'Hosting SG 500MB',
            'sales_price' => 550000,
            'is_active' => true,
        ]);
    }

    public function test_store_requires_name(): void
    {
        $this->login();

        $response = $this->post(route('products.store'), ['name' => '', 'sales_price' => 1000]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_store_rejects_negative_price(): void
    {
        $this->login();

        $response = $this->post(route('products.store'), ['name' => 'Produk', 'sales_price' => -1]);

        $response->assertSessionHasErrors('sales_price');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_store_rejects_duplicate_sku(): void
    {
        $this->login();
        ProductFactory::new()->create(['sku' => 'SKU0001']);

        $response = $this->post(route('products.store'), ['name' => 'Duplikat', 'sku' => 'SKU0001']);

        $response->assertSessionHasErrors('sku');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_sku_may_be_blank_for_multiple_products(): void
    {
        $this->login();

        $this->post(route('products.store'), ['name' => 'Tanpa SKU A', 'sku' => ''])->assertSessionHasNoErrors();
        $this->post(route('products.store'), ['name' => 'Tanpa SKU B', 'sku' => ''])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('products', 2);
    }

    // ---------- update ----------

    public function test_update_keeps_own_sku(): void
    {
        $this->login();
        $product = ProductFactory::new()->create(['sku' => 'SKU0005', 'name' => 'Domain TLD .com']);

        $response = $this->put(route('products.update', $product), [
            'name' => 'Domain TLD .com (baru)',
            'sku' => 'SKU0005',
            'sales_price' => 250000,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertSame('Domain TLD .com (baru)', $product->fresh()->name);
    }

    public function test_update_rejects_other_products_sku(): void
    {
        $this->login();
        ProductFactory::new()->create(['sku' => 'SKU0005']);
        $product = ProductFactory::new()->create(['sku' => 'SKU0011']);

        $response = $this->put(route('products.update', $product), ['name' => 'Bentrok', 'sku' => 'SKU0005']);

        $response->assertSessionHasErrors('sku');
        $this->assertSame('SKU0011', $product->fresh()->sku);
    }

    // ---------- toggle / delete ----------

    public function test_toggle_switches_active_state(): void
    {
        $this->login();
        $product = ProductFactory::new()->create(['is_active' => true]);

        $this->patch(route('products.toggle', $product))->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);

        $this->patch(route('products.toggle', $product))->assertRedirect();
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_delete_product(): void
    {
        $this->login();
        $product = ProductFactory::new()->create();

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    // ---------- seeder ----------

    public function test_seeder_is_idempotent_and_seeds_22_products(): void
    {
        $this->seed(ProductSeeder::class);
        $this->assertDatabaseCount('products', 22);

        $this->seed(ProductSeeder::class);
        $this->assertDatabaseCount('products', 22);

        $this->assertDatabaseHas('products', ['sku' => 'SKU0016', 'sales_price' => 6318382]);
    }
}
