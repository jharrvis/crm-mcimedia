<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Clients\Models\Client;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use Database\Factories\InvoiceFactory;
use Database\Factories\ProductFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateRenewalInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_draft_invoice_for_active_service_ending_within_window(): void
    {
        $service = ServiceFactory::new()->create([
            'name' => 'Hosting Bisnis',
            'status' => 'active',
            'price' => 600000,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')
            ->expectsOutputToContain('Draf invoice perpanjangan dibuat: 1')
            ->assertSuccessful();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($service->client_id, $invoice->client_id);
        $this->assertSame([$service->id], $invoice->services->pluck('id')->all());
        $this->assertSame($service->end_date->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame(600000, $invoice->total);
        $this->assertSame(600000, $invoice->subtotal);

        $item = $invoice->items()->firstOrFail();
        $this->assertSame('Hosting Bisnis', $item->description);
        $this->assertSame(1, $item->quantity);
        $this->assertSame(600000, $item->unit_price);
        $this->assertSame(600000, $item->amount);
    }

    public function test_second_run_creates_nothing_when_open_invoice_exists(): void
    {
        ServiceFactory::new()->create([
            'status' => 'active',
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();
        $this->assertDatabaseCount('invoices', 1);

        $this->artisan('crm:generate-renewal-invoices')
            ->expectsOutputToContain('Tidak ada draf invoice perpanjangan yang dibuat.')
            ->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_skips_service_with_sent_invoice(): void
    {
        $service = ServiceFactory::new()->create([
            'status' => 'active',
            'end_date' => now()->addDays(7)->toDateString(),
        ]);
        InvoiceFactory::new()->forService($service)->create(['status' => InvoiceStatus::Sent]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_new_draft_created_when_previous_invoice_is_paid(): void
    {
        $service = ServiceFactory::new()->create([
            'status' => 'active',
            'end_date' => now()->addDays(7)->toDateString(),
        ]);
        InvoiceFactory::new()->forService($service)->create(['status' => InvoiceStatus::Paid]);

        $this->artisan('crm:generate-renewal-invoices')
            ->expectsOutputToContain('Draf invoice perpanjangan dibuat: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_services_outside_window_or_inactive_are_ignored(): void
    {
        ServiceFactory::new()->create([
            'name' => 'Masih Lama',
            'status' => 'active',
            'end_date' => now()->addDays(90)->toDateString(),
        ]);
        ServiceFactory::new()->create([
            'name' => 'Sudah Lewat',
            'status' => 'active',
            'end_date' => now()->subDays(5)->toDateString(),
        ]);
        ServiceFactory::new()->create([
            'name' => 'Nonaktif',
            'status' => 'inactive',
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')
            ->expectsOutputToContain('Tidak ada draf invoice perpanjangan yang dibuat.')
            ->assertSuccessful();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_days_option_narrows_the_window(): void
    {
        ServiceFactory::new()->create([
            'status' => 'active',
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices', ['--days' => 5])->assertSuccessful();
        $this->assertDatabaseCount('invoices', 0);

        $this->artisan('crm:generate-renewal-invoices', ['--days' => 15])->assertSuccessful();
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_numbers_are_sequential_across_services(): void
    {
        $month = now()->format('Ym');
        ServiceFactory::new()->create(['status' => 'active', 'end_date' => now()->addDays(3)->toDateString()]);
        ServiceFactory::new()->create(['status' => 'active', 'end_date' => now()->addDays(9)->toDateString()]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $numbers = Invoice::orderBy('id')->pluck('number')->all();
        $this->assertSame(["INV-{$month}-0001", "INV-{$month}-0002"], $numbers);
    }

    public function test_uses_catalog_product_price_when_service_is_linked(): void
    {
        $product = ProductFactory::new()->create(['sales_price' => 999000]);
        $service = ServiceFactory::new()->create([
            'status' => 'active',
            'price' => 111000, // snapshot basi — harus diabaikan
            'product_id' => $product->id,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(999000, $invoice->total);
        $this->assertSame(999000, $invoice->items->first()->unit_price);
    }

    public function test_falls_back_to_service_price_when_no_product(): void
    {
        ServiceFactory::new()->create([
            'status' => 'active',
            'price' => 450000,
            'product_id' => null,
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $this->assertSame(450000, Invoice::firstOrFail()->total);
    }

    public function test_groups_services_sharing_the_same_domain_root_into_one_invoice(): void
    {
        $client = Client::factory()->create();
        $root = ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'contoh.com',
            'reference' => 'contoh.com',
            'type' => 'domain',
            'parent_id' => null,
            'end_date' => now()->addDays(8)->toDateString(),
        ]);
        $sub = ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'www.contoh.com',
            'reference' => 'www.contoh.com',
            'type' => 'domain',
            'parent_id' => $root->id,
            'end_date' => now()->addDays(12)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::firstOrFail();
        $this->assertEqualsCanonicalizing(
            [$root->id, $sub->id],
            $invoice->services->pluck('id')->all()
        );
        $this->assertCount(2, $invoice->items);
        $this->assertSame(
            $root->end_date->toDateString(),
            $invoice->due_date->toDateString()
        );
    }

    public function test_same_host_across_types_shares_one_invoice(): void
    {
        $client = Client::factory()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'contoh.com',
            'reference' => 'contoh.com',
            'type' => 'domain',
            'parent_id' => null,
            'end_date' => now()->addDays(6)->toDateString(),
        ]);
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'name' => 'Hosting contoh.com',
            'reference' => 'contoh.com',
            'type' => 'hosting',
            'parent_id' => null,
            'end_date' => now()->addDays(6)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, Invoice::firstOrFail()->services->count());
    }

    public function test_www_prefix_does_not_split_a_domain_group(): void
    {
        $client = Client::factory()->create();
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'reference' => 'www.contoh.com',
            'type' => 'domain',
            'parent_id' => null,
            'end_date' => now()->addDays(6)->toDateString(),
        ]);
        ServiceFactory::new()->create([
            'client_id' => $client->id,
            'reference' => 'contoh.com',
            'type' => 'hosting',
            'parent_id' => null,
            'end_date' => now()->addDays(6)->toDateString(),
        ]);

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
    }

    public function test_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('crm:generate-renewal-invoices')
            ->assertSuccessful();
    }
}
