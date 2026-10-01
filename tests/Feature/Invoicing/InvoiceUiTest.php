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

class InvoiceUiTest extends TestCase
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
            'quantity' => 2,
            'unit_price' => 150000,
        ], $overrides);
    }

    private function invoicePayload($client, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'service_id' => '',
            'title' => 'Perpanjangan Hosting',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'notes' => 'Terima kasih.',
            'items' => [
                $this->itemPayload(),
                $this->itemPayload(['description' => 'Management Server', 'quantity' => 1, 'unit_price' => 500000]),
            ],
        ], $overrides);
    }

    // ---------- akses ----------

    public function test_guest_redirected_to_login_from_index(): void
    {
        $this->get(route('invoices.index'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_to_login_from_pdf(): void
    {
        $invoice = InvoiceFactory::new()->withItems()->create();

        $this->get(route('invoices.pdf', $invoice))->assertRedirect(route('login'));
    }

    // ---------- index & filter ----------

    public function test_index_lists_invoices(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create();

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee($invoice->number);
    }

    public function test_index_filter_by_status(): void
    {
        $this->login();
        $draft = InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft, 'title' => 'DrafUnik']);
        $paid = InvoiceFactory::new()->create(['status' => InvoiceStatus::Paid, 'title' => 'LunasUnik']);

        $response = $this->get(route('invoices.index', ['status' => 'paid']));

        $response->assertOk();
        $response->assertSee($paid->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_filter_by_client(): void
    {
        $this->login();
        $clientA = ClientFactory::new()->create(['name' => 'Klien Alpha']);
        $clientB = ClientFactory::new()->create(['name' => 'Klien Beta']);
        $invoiceA = InvoiceFactory::new()->create(['client_id' => $clientA->id, 'title' => 'AlphaUnik']);
        $invoiceB = InvoiceFactory::new()->create(['client_id' => $clientB->id, 'title' => 'BetaUnik']);

        $response = $this->get(route('invoices.index', ['client_id' => $clientA->id]));

        // NB: nama kedua klien tetap muncul di dropdown filter — cek baris invoice-nya.
        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertSee('AlphaUnik');
        $response->assertDontSee($invoiceB->number);
        $response->assertDontSee('BetaUnik');
    }

    public function test_index_search_by_number_and_client_name(): void
    {
        $this->login();
        $client = ClientFactory::new()->create(['name' => 'Toko Mencari']);
        $found = InvoiceFactory::new()->create(['client_id' => $client->id, 'number' => 'INV-202610-0001']);
        $hidden = InvoiceFactory::new()->create(['number' => 'INV-202610-0002']);

        $this->get(route('invoices.index', ['q' => 'INV-202610-0001']))
            ->assertOk()
            ->assertSee($found->number)
            ->assertDontSee($hidden->number);

        $this->get(route('invoices.index', ['q' => 'Toko Mencari']))
            ->assertOk()
            ->assertSee($found->number)
            ->assertDontSee($hidden->number);
    }

    // ---------- create ----------

    public function test_create_page_renders(): void
    {
        $this->login();

        $this->get(route('invoices.create'))->assertOk()->assertSee('Item invoice');
    }

    public function test_store_creates_invoice_with_items_and_computes_totals(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('invoices.store'), $this->invoicePayload($client));

        $invoice = Invoice::with('items')->first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('success');

        $this->assertSame(2, $invoice->items->count());
        $this->assertSame(300000, $invoice->items[0]->amount);  // 2 * 150000
        $this->assertSame(500000, $invoice->items[1]->amount);  // 1 * 500000
        $this->assertSame(800000, $invoice->subtotal);
        $this->assertSame(800000, $invoice->total);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{4}$/', $invoice->number);
    }

    public function test_store_validates_at_least_one_item(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('invoices.store'), $this->invoicePayload($client, [
            'items' => [['description' => '', 'quantity' => 1, 'unit_price' => 0]],
        ]));

        $response->assertSessionHasErrors('items');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_store_validates_due_date_not_before_issue_date(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('invoices.store'), $this->invoicePayload($client, [
            'due_date' => now()->subDay()->toDateString(),
        ]));

        $response->assertSessionHasErrors('due_date');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_store_can_attach_service(): void
    {
        $this->login();
        $service = ServiceFactory::new()->create();

        $this->post(route('invoices.store'), $this->invoicePayload($service->client, [
            'service_id' => $service->id,
        ]));

        $invoice = Invoice::first();
        $this->assertSame($service->id, $invoice->service_id);
        $this->assertSame($service->client_id, $invoice->client_id);
    }

    public function test_store_rejects_service_of_another_client(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $foreignService = ServiceFactory::new()->create(); // milik klien lain

        $response = $this->post(route('invoices.store'), $this->invoicePayload($client, [
            'service_id' => $foreignService->id,
        ]));

        $response->assertSessionHasErrors('service_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    // ---------- show & edit ----------

    public function test_show_page_displays_items_and_totals(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Rp 1.500.000')
            ->assertSee('Unduh PDF');
    }

    public function test_update_replaces_items_and_recalculates_totals(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(500000)->create();
        $oldItemId = $invoice->items()->first()->id;

        $response = $this->put(route('invoices.update', $invoice), $this->invoicePayload($invoice->client, [
            'title' => 'Judul Baru',
            'items' => [
                $this->itemPayload(['description' => 'Item Pengganti', 'quantity' => 3, 'unit_price' => 100000]),
            ],
        ]));

        $response->assertRedirect(route('invoices.show', $invoice));
        $invoice->refresh()->load('items');

        $this->assertSame('Judul Baru', $invoice->title);
        $this->assertSame(1, $invoice->items->count());
        $this->assertNotSame($oldItemId, $invoice->items[0]->id);
        $this->assertSame('Item Pengganti', $invoice->items[0]->description);
        $this->assertSame(300000, $invoice->items[0]->amount);
        $this->assertSame(300000, $invoice->total);
    }

    public function test_edit_blocked_for_terminal_invoice(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Paid]);

        $this->get(route('invoices.edit', $invoice))
            ->assertRedirect(route('invoices.show', $invoice));

        $response = $this->put(route('invoices.update', $invoice), $this->invoicePayload($invoice->client));
        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('error');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    // ---------- transisi status ----------

    public function test_mark_sent_transitions_draft_to_sent(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft]);

        $this->patch(route('invoices.send', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_mark_sent_rejected_for_paid_invoice(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Paid]);

        $this->patch(route('invoices.send', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_record_payment_creates_payment_and_marks_paid(): void
    {
        $user = $this->login();
        $invoice = InvoiceFactory::new()->withItems(800000)->create();
        $invoice->markSent();

        $response = $this->post(route('invoices.payments.store', $invoice), [
            'amount' => 800000,
            'method' => 'bank_transfer',
            'paid_at' => now()->toDateString(),
            'note' => 'Sudah masuk rekening',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invoice->refresh()->load('payments');
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertCount(1, $invoice->payments);

        $payment = $invoice->payments->first();
        $this->assertSame(800000, $payment->amount);
        $this->assertSame('bank_transfer', $payment->method);
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame($user->id, $payment->confirmed_by);
    }

    public function test_record_payment_rejected_for_cancelled_invoice(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Cancelled]);

        $this->post(route('invoices.payments.store', $invoice), [
            'amount' => 100000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_cancel_transitions_sent_to_cancelled(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Sent]);

        $this->patch(route('invoices.cancel', $invoice))->assertRedirect();

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_cancel_rejected_for_paid_invoice(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Paid]);

        $this->patch(route('invoices.cancel', $invoice))->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    // ---------- delete guard ----------

    public function test_delete_allowed_for_draft(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft]);

        $this->delete(route('invoices.destroy', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_delete_blocked_for_non_draft(): void
    {
        $this->login();

        foreach ([InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::Overdue, InvoiceStatus::Cancelled] as $status) {
            $invoice = InvoiceFactory::new()->create(['status' => $status]);

            $this->delete(route('invoices.destroy', $invoice))
                ->assertRedirect(route('invoices.show', $invoice))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        }
    }

    // ---------- PDF ----------

    public function test_pdf_returns_pdf_download_for_admin(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertDownload($invoice->number.'.pdf');
        // dompdf download() mengembalikan Response berisi binary PDF.
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }

    public function test_pdf_contains_invoice_details(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(1500000)->create();

        // Render template HTML langsung untuk memastikan data identitas & item masuk.
        $html = view('invoices.pdf', ['invoice' => $invoice->load(['client', 'items', 'payments'])])->render();

        $this->assertStringContainsString(config('crm.business.name'), $html);
        $this->assertStringContainsString(config('crm.bank.account_number'), $html);
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('Rp 1.500.000', $html);
        $this->assertStringNotContainsString('PPN', $html);
    }
}
