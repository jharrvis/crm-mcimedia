<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use Database\Factories\InvoiceFactory;
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
        $this->assertSame($service->id, $invoice->service_id);
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

    public function test_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('crm:generate-renewal-invoices')
            ->assertSuccessful();
    }
}
