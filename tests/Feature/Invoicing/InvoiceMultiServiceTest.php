<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F4-8: invoice gabungan — satu invoice mencakup banyak layanan sekaligus.
 * Kasus Indoboga: banyak website, dibayar bulanan dalam satu invoice.
 */
class InvoiceMultiServiceTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Hosting Bisnis',
            'quantity' => 1,
            'unit_price' => 150000,
        ], $overrides);
    }

    private function invoicePayload($client, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'service_ids' => [],
            'title' => 'Tagihan bulanan website',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'notes' => null,
            'items' => [$this->itemPayload()],
        ], $overrides);
    }

    /** Tiga website milik klien sama, dibayar dalam satu invoice. */
    private function combinedInvoice(): Invoice
    {
        $client = ClientFactory::new()->create();
        $services = collect([
            ServiceFactory::new()->create(['client_id' => $client->id, 'name' => 'Website Utama']),
            ServiceFactory::new()->create(['client_id' => $client->id, 'name' => 'Website Toko Online']),
            ServiceFactory::new()->create(['client_id' => $client->id, 'name' => 'Website Blog']),
        ]);

        $invoice = InvoiceFactory::new()->forServices($services)->create([
            'client_id' => $client->id,
            'title' => 'Tagihan bulanan website',
        ]);

        foreach ($services as $sort => $service) {
            $invoice->items()->create([
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => $service->price,
                'amount' => 0,
                'sort_order' => $sort,
            ]);
        }
        $invoice->recalculateTotals();

        return $invoice;
    }

    // ---------- form create/edit ----------

    public function test_create_form_offers_multi_select_for_services(): void
    {
        $this->login();
        $service = ServiceFactory::new()->create(['name' => 'Website Utama']);

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('Layanan terkait')
            ->assertSee('Isi dari layanan dipilih')
            // Checkbox multi (name="service_ids[]"), bukan select tunggal.
            ->assertSee('name="service_ids[]"', false)
            ->assertSee($service->name);
    }

    public function test_edit_form_preselects_all_services_of_invoice(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk();

        foreach ($invoice->services as $service) {
            $response->assertSee(
                'name="service_ids[]" value="'.$service->id.'"',
                false
            );
        }
    }

    public function test_store_creates_combined_invoice_from_many_services(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $services = collect([
            ServiceFactory::new()->create(['client_id' => $client->id, 'price' => 150000]),
            ServiceFactory::new()->create(['client_id' => $client->id, 'price' => 300000]),
            ServiceFactory::new()->create(['client_id' => $client->id, 'price' => 500000]),
        ]);

        $response = $this->post(route('invoices.store'), $this->invoicePayload($client, [
            'service_ids' => $services->pluck('id')->all(),
            'items' => [
                $this->itemPayload(['description' => 'Website Utama', 'unit_price' => 150000]),
                $this->itemPayload(['description' => 'Website Toko', 'unit_price' => 300000]),
                $this->itemPayload(['description' => 'Website Blog', 'unit_price' => 500000]),
            ],
        ]));

        $invoice = Invoice::with('services')->firstOrFail();

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertCount(3, $invoice->services);
        $this->assertSame(950000, $invoice->total);
    }

    public function test_store_accepts_invoice_without_any_service(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $this->post(route('invoices.store'), $this->invoicePayload($client))
            ->assertSessionHas('success');

        $invoice = Invoice::firstOrFail();
        $this->assertCount(0, $invoice->services);
    }

    /** Checkbox tanpa centang tidak boleh menggagalkan submit. */
    public function test_store_ignores_blank_service_ids(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $this->post(route('invoices.store'), $this->invoicePayload($client, [
            'service_ids' => [''],
        ]))->assertSessionHas('success');

        $this->assertCount(0, Invoice::firstOrFail()->services);
    }

    // ---------- halaman detail & PDF ----------

    public function test_show_page_lists_every_service_on_a_combined_invoice(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk()->assertSee('Layanan terkait (3)');

        foreach ($invoice->services as $service) {
            $response->assertSee($service->name);
        }
    }

    public function test_show_page_handles_invoice_without_services(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems()->create();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Layanan terkait (0)');
    }

    public function test_pdf_renders_all_services_of_combined_invoice(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertOk();

        // Respons PDF berupa binary (bukan streamed) — cek magic byte-nya.
        $this->assertStringStartsWith('%PDF', substr($response->getContent(), 0, 4));

        // Nama layanan tampil di PDF; isi PDF biner tidak bisa di-assert sebagai
        // teks, jadi template HTML-nya yang dicek.
        foreach ($invoice->services as $service) {
            $this->assertStringContainsString(
                e($service->name),
                $this->renderPdfHtml($invoice)
            );
        }
    }

    public function test_public_invoice_page_renders_combined_services(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();
        $invoice->markSent();

        $this->get(route('invoices.public.show', ['token' => $invoice->public_token]))
            ->assertOk();
    }

    /** Render template PDF invoice ke HTML (helper assertion). */
    private function renderPdfHtml(Invoice $invoice): string
    {
        $invoice->load(['client', 'services', 'items', 'payments']);

        return view('invoices.pdf', ['invoice' => $invoice])->render();
    }

    // ---------- integritas data ----------

    public function test_combined_invoice_total_is_sum_of_all_items(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();

        $this->assertSame(3, $invoice->items()->count());
        $this->assertSame(
            $invoice->items->sum(fn ($item) => $item->quantity * $item->unit_price),
            $invoice->total
        );
    }

    public function test_renewal_command_skips_service_already_covered_by_combined_invoice(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();
        $invoice->update(['status' => InvoiceStatus::Sent]);

        $this->artisan('crm:generate-renewal-invoices')
            ->expectsOutputToContain('Tidak ada draf invoice perpanjangan yang dibuat.')
            ->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
    }

    /**
     * Skenario keamanan (QA): service_ids milik klien lain yang dikirim langsung
     * via POST harus ditolak dengan pesan jelas, dan tidak boleh ada baris yang
     * bocor ke tabel pivot meski layanan milik klien sendiri ikut dikirim.
     */
    public function test_posting_another_clients_service_is_rejected_without_pivot_leak(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $ownService = ServiceFactory::new()->create(['client_id' => $client->id]);
        $foreignService = ServiceFactory::new()->create(); // milik klien lain

        $response = $this->from(route('invoices.create'))->post(route('invoices.store'), $this->invoicePayload($client, [
            'service_ids' => [$ownService->id, $foreignService->id],
        ]));

        $response->assertSessionHasErrors('service_ids.1');
        $this->assertSame(
            'Layanan yang dipilih tidak milik klien ini.',
            session('errors')->first('service_ids.1')
        );

        // Tidak boleh ada invoice tersimpan, dan pivot tetap kosong total —
        // bahkan untuk layanan milik klien sendiri di indeks 0.
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_service', 0);
    }

    /** Skenario 8 (QA): hapus invoice -> semua baris pivot ikut hilang. */
    public function test_deleting_invoice_removes_its_pivot_rows(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();

        $this->assertDatabaseCount('invoice_service', 3);

        $this->delete(route('invoices.destroy', $invoice))->assertRedirect();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_service', 0);
    }

    /** Skenario 8 (QA): hapus layanan -> invoice tetap utuh, tautan hilang. */
    public function test_deleting_service_keeps_invoice_and_detaches_link(): void
    {
        $this->login();
        $invoice = $this->combinedInvoice();
        $removed = $invoice->services->firstWhere('name', 'Website Toko Online');

        $this->delete(route('services.destroy', $removed))->assertRedirect();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_service', 2);
        $this->assertDatabaseMissing('services', ['id' => $removed->id]);
        $this->assertDatabaseMissing('invoice_service', [
            'invoice_id' => $invoice->id,
            'service_id' => $removed->id,
        ]);
        // Dua layanan lain tetap tertaut ke invoice yang sama.
        $this->assertSame(
            2,
            $invoice->fresh()->services()->count()
        );
    }
}
