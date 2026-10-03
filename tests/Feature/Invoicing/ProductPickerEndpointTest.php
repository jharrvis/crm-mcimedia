<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductCategory;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint picker produk (UX-2): live search + split button simpan invoice.
 * - GET  products/picker/search  → JSON {query, products:[{id,sku,name,sales_price,variants}]}
 * - POST products/picker/quick-create → 201 {product}
 * - save_action: draft (default) | send | confirm
 */
class ProductPickerEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    // ---------- search ----------

    public function test_search_filters_by_name_and_sku_server_side(): void
    {
        $this->login();
        $hosting = ProductFactory::new()->create(['name' => 'Hosting 1GB (SG)', 'sku' => 'SKU-HOST-1GB', 'sales_price' => 550000, 'is_active' => true]);
        ProductFactory::new()->create(['name' => 'Domain .com', 'sales_price' => 150000, 'is_active' => true]);

        $res = $this->getJson(route('products.picker.search', ['q' => 'Hosting']));
        $res->assertOk()->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $hosting->id)
            ->assertJsonPath('products.0.sku', 'SKU-HOST-1GB');
    }

    public function test_search_includes_variants_but_excludes_inactive_products_and_variants(): void
    {
        $this->login();
        $hosting = ProductFactory::new()->create(['name' => 'Hosting VPS', 'is_active' => true]);
        ProductVariant::factory()->create(['product_id' => $hosting->id, 'name' => '4GB', 'sales_price' => 900000, 'is_active' => true]);
        ProductVariant::factory()->create(['product_id' => $hosting->id, 'name' => '8GB (Nonaktif)', 'is_active' => false]);

        ProductFactory::new()->create(['name' => 'Produk Mati', 'is_active' => false]);

        $res = $this->getJson(route('products.picker.search', ['q' => 'VPS']));
        $res->assertOk()->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $hosting->id)
            ->assertJsonCount(1, 'products.0.variants')
            ->assertJsonPath('products.0.variants.0.name', '4GB');

        // Varian nonaktif tidak muncul; produk nonaktif tidak muncul.
        $res2 = $this->getJson(route('products.picker.search', ['q' => 'Produk Mati']));
        $res2->assertOk()->assertJsonCount(0, 'products');
    }

    public function test_search_rejects_guests(): void
    {
        $this->getJson(route('products.picker.search', ['q' => 'x']))->assertUnauthorized();
    }

    // ---------- quick-create ----------

    public function test_quick_create_returns_201_and_product_payload(): void
    {
        $this->login(); // user tanpa role = akses penuh (isAdmin)
        $category = ProductCategory::factory()->create(['name' => 'Hosting', 'slug' => 'hosting']);

        $res = $this->postJson(route('products.picker.quick-create'), [
            'name' => 'Hosting 5GB (SG)',
            'sales_price' => 750000,
            'category_id' => $category->id,
        ]);

        $res->assertCreated()
            ->assertJsonPath('product.name', 'Hosting 5GB (SG)')
            ->assertJsonPath('product.sales_price', 750000)
            ->assertJsonPath('product.category_id', $category->id);

        $this->assertDatabaseHas('products', ['name' => 'Hosting 5GB (SG)', 'is_active' => true]);
    }

    public function test_quick_create_validates_name_required(): void
    {
        $this->login();

        $this->postJson(route('products.picker.quick-create'), ['name' => '', 'sales_price' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_quick_create_rejects_negative_price(): void
    {
        $this->login();

        $this->postJson(route('products.picker.quick-create'), ['name' => 'Produk Baru', 'sales_price' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_price');
    }

    // ---------- split button: save_action ----------

    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => ClientFactory::new()->create()->id,
            'title' => 'Invoice UX-2',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['description' => 'Layanan hosting', 'quantity' => 1, 'unit_price' => 100000]],
        ], $overrides);
    }

    public function test_store_without_save_action_stays_draft(): void
    {
        $this->login();
        $this->post(route('invoices.store'), $this->invoicePayload());

        $this->assertSame(InvoiceStatus::Draft, Invoice::firstOrFail()->status);
    }

    public function test_store_with_save_action_confirm_marks_sent(): void
    {
        $this->login();
        $this->post(route('invoices.store'), $this->invoicePayload(['save_action' => 'confirm']));

        $this->assertSame(InvoiceStatus::Sent, Invoice::firstOrFail()->status);
    }

    public function test_store_with_save_action_send_stays_draft(): void
    {
        $this->login();
        $res = $this->from(route('invoices.create'))->post(route('invoices.store'), $this->invoicePayload(['save_action' => 'send']));

        $res->assertSessionHas('success');
        $this->assertSame(InvoiceStatus::Draft, Invoice::firstOrFail()->status);
    }

    public function test_store_with_unknown_save_action_is_rejected(): void
    {
        $this->login();
        $this->post(route('invoices.store'), $this->invoicePayload(['save_action' => 'hapus']))
            ->assertSessionHasErrors('save_action');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_update_with_save_action_confirm_marks_sent(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(50000)->create(['status' => InvoiceStatus::Draft]);

        $this->put(route('invoices.update', $invoice), $this->invoicePayload(['save_action' => 'confirm']));

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }
}
