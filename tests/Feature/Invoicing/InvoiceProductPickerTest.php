<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceProductPickerTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_form_lists_active_products_with_price_data(): void
    {
        $this->login();
        $product = ProductFactory::new()->create([
            'name' => 'Hosting 1GB (SG)',
            'sku' => 'SKU0012',
            'sales_price' => 1100000,
            'is_active' => true,
        ]);

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('Hosting 1GB (SG)')
            ->assertSee('data-price="1100000"', false)
            ->assertSee('data-name="Hosting 1GB (SG)"', false)
            ->assertSee(rupiah($product->sales_price));
    }

    public function test_form_hides_inactive_products(): void
    {
        $this->login();
        ProductFactory::new()->create(['name' => 'Produk Aktif Unik', 'is_active' => true]);
        ProductFactory::new()->create(['name' => 'Produk Nonaktif Unik', 'is_active' => false]);

        $response = $this->get(route('invoices.create'));

        $response->assertOk();
        $response->assertSee('Produk Aktif Unik');
        $response->assertDontSee('Produk Nonaktif Unik');
    }

    public function test_edit_form_lists_active_products(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(100000)->create();
        ProductFactory::new()->create(['name' => 'Produk Picker Edit', 'is_active' => true]);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Produk Picker Edit');
    }

    public function test_invoice_created_from_picked_product_has_correct_total(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $product = ProductFactory::new()->create([
            'name' => 'Hosting 10GB (SG)',
            'sku' => 'SKU0010',
            'sales_price' => 3000000,
            'is_active' => true,
        ]);

        // Item yang dikirim form = hasil picker: description = nama produk, unit_price = sales_price.
        $response = $this->post(route('invoices.store'), [
            'client_id' => $client->id,
            'service_id' => '',
            'title' => 'Perpanjangan Hosting',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'items' => [
                ['description' => $product->name, 'quantity' => 2, 'unit_price' => $product->sales_price],
            ],
        ]);

        $invoice = Invoice::with('items')->first();
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(1, $invoice->items->count());
        $this->assertSame('Hosting 10GB (SG)', $invoice->items[0]->description);
        $this->assertSame(6000000, $invoice->items[0]->amount);
        $this->assertSame(6000000, $invoice->total);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
    }
}
