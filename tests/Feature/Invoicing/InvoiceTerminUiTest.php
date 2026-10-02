<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Services\Enums\ServiceStatus;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F4-10: tampilan termin di halaman detail invoice.
 */
class InvoiceTerminUiTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function contract(int $total = 1000000): Invoice
    {
        $invoice = InvoiceFactory::new()->create([
            'title' => 'Paket website + SEO',
            'status' => InvoiceStatus::Draft,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoice->items()->create([
            'description' => 'Paket website + SEO',
            'quantity' => 1,
            'unit_price' => $total,
            'amount' => $total,
            'sort_order' => 0,
        ]);
        $invoice->recalculateTotals();

        return $invoice->fresh();
    }

    private function split(Invoice $contract, array $percents = [30, 30, 40]): void
    {
        $rows = [];
        $i = 0;
        foreach ($percents as $percent) {
            $rows[] = [
                'percent' => $percent,
                'due_date' => now()->addDays(30 * ($i + 1))->toDateString(),
            ];
            $i++;
        }

        $this->post(route('invoices.termin.store', $contract), ['terms' => $rows])
            ->assertSessionHas('success');
    }

    public function test_draft_invoice_shows_split_button(): void
    {
        $this->login();
        $invoice = $this->contract();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Pecah menjadi termin');
    }

    public function test_paid_invoice_hides_split_button(): void
    {
        $this->login();
        $invoice = $this->contract();
        $invoice->markPaid();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Pecah menjadi termin');
    }

    public function test_contract_page_shows_termin_table_with_percentages(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $this->split($invoice);

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk()
            ->assertSee('Termin pembayaran (3)')
            ->assertSee('30%')
            ->assertSee('40%');

        // Nominal tiap termin tampil di tabel.
        $response->assertSee(rupiah(300000));
        $response->assertSee(rupiah(400000));

        // Total termin == nilai kontrak.
        $response->assertSee(rupiah(1000000));
    }

    public function test_termin_page_points_back_to_contract(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $this->split($invoice);

        $termin = $invoice->terminInvoices()->first();

        $this->get(route('invoices.show', $termin))
            ->assertOk()
            ->assertSee('Termin dari invoice kontrak')
            ->assertSee($invoice->number)
            ->assertSee('30%');
    }

    public function test_contract_loses_split_button_after_being_split(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $this->split($invoice);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            // Sudah punya termin — tombol pecah tidak muncul lagi.
            ->assertDontSee('Pecah menjadi termin');
    }

    public function test_split_form_shows_contract_value_and_termin_rows(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->get(route('invoices.termin.create', $invoice))
            ->assertOk()
            ->assertSee('Nilai kontrak')
            ->assertSee(rupiah(1000000))
            // Form input persentase & jatuh tempo per termin.
            ->assertSee('terms[0][percent]', false)
            ->assertSee('terms[0][due_date]', false)
            ->assertSee('Tambah termin');
    }

    public function test_split_form_is_unreachable_for_already_split_invoice(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $this->split($invoice);

        $this->get(route('invoices.termin.create', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');
    }

    public function test_invoice_without_value_cannot_open_split_form(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft]);

        $this->get(route('invoices.termin.create', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');
    }

    public function test_index_lists_termin_invoices_like_any_other(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $this->split($invoice);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee($invoice->number);

        foreach ($invoice->terminInvoices()->get() as $termin) {
            // Termin tetap invoice biasa: punya baris & aksi sendiri.
            $this->get(route('invoices.show', $termin))->assertOk();
        }
    }

    public function test_pdf_of_termin_renders_and_shows_contract_reference(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $this->split($invoice);

        $termin = $invoice->terminInvoices()->first();

        $response = $this->get(route('invoices.pdf', $termin));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', substr($response->getContent(), 0, 4));

        // Template PDF memuat penjelasan asal nilai termin.
        $termin->load(['client', 'services', 'items', 'payments']);
        $html = view('invoices.pdf', ['invoice' => $termin])->render();

        $this->assertStringContainsString(e($invoice->number), $html);
        $this->assertStringContainsString('30%', $html);
    }

    public function test_public_payment_page_works_for_a_termin(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $this->split($invoice);

        $termin = $invoice->terminInvoices()->first();
        $termin->markSent();

        // Termin punya tautan pembayaran publik sendiri.
        $this->assertNotNull($termin->fresh()->public_token);

        $this->get(route('invoices.public.show', ['token' => $termin->fresh()->public_token]))
            ->assertOk();
    }

    public function test_renewal_command_still_skips_service_covered_by_terms(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $service = ServiceFactory::new()->create([
            'client_id' => $client->id,
            'status' => ServiceStatus::Active,
            'end_date' => now()->addDays(10)->toDateString(),
            'price' => 300000,
        ]);

        $invoice = $this->contract(1000000);
        $invoice->syncServices([$service->id]);
        $this->split($invoice);

        foreach ($invoice->terminInvoices()->get() as $termin) {
            $termin->update(['status' => InvoiceStatus::Sent]);
        }

        $this->artisan('crm:generate-renewal-invoices')
            ->expectsOutputToContain('Tidak ada draf invoice perpanjangan yang dibuat.')
            ->assertSuccessful();
    }
}
