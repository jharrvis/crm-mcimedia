<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Invoice terkirim dengan tautan publik aktif. */
    private function sentInvoice(int $total = 800000, array $overrides = []): Invoice
    {
        return InvoiceFactory::new()->withItems($total)->create(array_merge([
            'status' => InvoiceStatus::Sent,
            'public_token' => Str::random(64),
        ], $overrides));
    }

    // ---------- akses halaman publik ----------

    public function test_valid_token_displays_invoice(): void
    {
        config(['crm.bank.accounts' => [
            ['name' => 'BCA', 'account_number' => '1111222233', 'account_holder' => 'Nama Pemilik'],
            ['name' => 'Bank Jateng', 'account_number' => '4444555566', 'account_holder' => 'Nama Pemilik'],
        ]]);

        $invoice = $this->sentInvoice(800000);

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertSee($invoice->number);
        $response->assertSee($invoice->client->name);
        $response->assertSee('Rp 800.000');
        $response->assertSee('Instruksi transfer');
        $response->assertSee('1111222233');
        $response->assertSee('4444555566');
        $response->assertSee('Unduh PDF invoice');
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->get(route('invoices.public.show', ['token' => Str::random(64)]))->assertNotFound();
    }

    public function test_revoked_token_returns_404(): void
    {
        $invoice = $this->sentInvoice();
        $token = $invoice->public_token;

        $invoice->update(['public_token' => null]);

        $this->get(route('invoices.public.show', ['token' => $token]))->assertNotFound();
    }

    public function test_draft_invoice_without_token_is_not_accessible(): void
    {
        $invoice = InvoiceFactory::new()->withItems(500000)->create(['status' => InvoiceStatus::Draft]);

        $this->assertNull($invoice->public_token);

        // Token apa pun tidak cocok dengan invoice draf (public_token NULL).
        $this->get(route('invoices.public.show', ['token' => Str::random(64)]))->assertNotFound();
        $this->assertFalse($invoice->hasPublicLink());
    }

    public function test_mark_sent_generates_public_token(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft]);
        $this->assertNull($invoice->public_token);

        $this->patch(route('invoices.send', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertNotNull($invoice->public_token);
        $this->assertSame(64, strlen($invoice->public_token));
        $this->assertTrue($invoice->hasPublicLink());
    }

    public function test_mark_sent_keeps_existing_token(): void
    {
        $this->login();
        $existing = Str::random(64);
        $invoice = InvoiceFactory::new()->create([
            'status' => InvoiceStatus::Draft,
            'public_token' => $existing,
        ]);

        $this->patch(route('invoices.send', $invoice))->assertRedirect();

        $this->assertSame($existing, $invoice->fresh()->public_token);
    }

    public function test_public_page_does_not_leak_other_client_or_private_data(): void
    {
        $mine = ClientFactory::new()->create([
            'name' => 'Klien Pemilik',
            'email' => 'rahasia-klien@example.com',
            'whatsapp' => '081299998888',
            'address' => 'Jl. Rahasia No. 1',
        ]);
        $other = ClientFactory::new()->create(['name' => 'Klien Lain Corp']);

        $invoice = $this->sentInvoice(800000, [
            'client_id' => $mine->id,
            'notes' => 'CATATAN_INTERNAL_JANGAN_TAMPIL',
        ]);
        $otherInvoice = InvoiceFactory::new()->create(['client_id' => $other->id, 'number' => 'INV-000000-9999']);

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertSee('Klien Pemilik');           // data invoice ini sendiri boleh tampil
        $response->assertDontSee('Klien Lain Corp');     // klien lain tidak
        $response->assertDontSee($otherInvoice->number);
        $response->assertDontSee('rahasia-klien@example.com'); // email klien tidak di halaman publik
        $response->assertDontSee('081299998888');              // WA klien tidak
        $response->assertDontSee('CATATAN_INTERNAL_JANGAN_TAMPIL'); // catatan internal tidak
    }

    public function test_cancelled_invoice_shows_info_page(): void
    {
        $invoice = $this->sentInvoice(800000, ['status' => InvoiceStatus::Cancelled]);

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertSee('dibatalkan');
        $response->assertSee($invoice->number);
        $response->assertDontSee('Kirim konfirmasi transfer');
    }

    public function test_public_pdf_downloads_same_document(): void
    {
        $invoice = $this->sentInvoice(1500000);

        $response = $this->get(route('invoices.public.pdf', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertDownload($invoice->number.'.pdf');
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }

    // ---------- konfirmasi transfer oleh klien ----------

    public function test_public_confirmation_creates_pending_payment_and_keeps_invoice_unpaid(): void
    {
        $invoice = $this->sentInvoice(800000);

        $response = $this->post(route('invoices.public.payments.store', ['token' => $invoice->public_token]), [
            'sender_name' => 'Budi Santoso',
            'amount' => 800000,
            'paid_at' => now()->toDateString(),
            'note' => 'Transfer via BCA',
        ]);

        $response->assertRedirect(route('invoices.public.show', ['token' => $invoice->public_token]));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'sender_name' => 'Budi Santoso',
            'amount' => 800000,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'confirmed_by' => null,
        ]);

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_at);
    }

    public function test_public_confirmation_rejects_amount_over_total(): void
    {
        $invoice = $this->sentInvoice(800000);

        $response = $this->post(route('invoices.public.payments.store', ['token' => $invoice->public_token]), [
            'sender_name' => 'Budi',
            'amount' => 900000,
            'paid_at' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_public_confirmation_rejects_non_positive_amount(): void
    {
        $invoice = $this->sentInvoice(800000);

        $this->post(route('invoices.public.payments.store', ['token' => $invoice->public_token]), [
            'sender_name' => 'Budi',
            'amount' => 0,
            'paid_at' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_public_confirmation_requires_sender_name(): void
    {
        $invoice = $this->sentInvoice(800000);

        $this->post(route('invoices.public.payments.store', ['token' => $invoice->public_token]), [
            'sender_name' => '',
            'amount' => 800000,
            'paid_at' => now()->toDateString(),
        ])->assertSessionHasErrors('sender_name');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_public_confirmation_blocks_identical_duplicate_within_window(): void
    {
        $invoice = $this->sentInvoice(800000);

        PaymentFactory::new()->create([
            'invoice_id' => $invoice->id,
            'amount' => 800000,
            'status' => 'pending',
            'paid_at' => now()->toDateString(),
        ]);

        $response = $this->post(route('invoices.public.payments.store', ['token' => $invoice->public_token]), [
            'sender_name' => 'Budi',
            'amount' => 800000,
            'paid_at' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_public_confirmation_blocked_for_paid_invoice(): void
    {
        $invoice = $this->sentInvoice(800000, ['status' => InvoiceStatus::Paid]);

        $response = $this->post(route('invoices.public.payments.store', ['token' => $invoice->public_token]), [
            'sender_name' => 'Budi',
            'amount' => 800000,
            'paid_at' => now()->toDateString(),
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('payments', 0);
    }

    // ---------- verifikasi admin ----------

    public function test_admin_confirms_pending_payment_and_marks_invoice_paid(): void
    {
        $user = $this->login();
        $invoice = $this->sentInvoice(800000);
        $payment = PaymentFactory::new()->create([
            'invoice_id' => $invoice->id,
            'amount' => 800000,
            'status' => 'pending',
            'paid_at' => now()->toDateString(),
        ]);

        $response = $this->patch(route('invoices.payments.confirm', [$invoice, $payment]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame($user->id, $payment->confirmed_by);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_admin_rejects_pending_payment_without_touching_invoice(): void
    {
        $this->login();
        $invoice = $this->sentInvoice(800000);
        $payment = PaymentFactory::new()->create([
            'invoice_id' => $invoice->id,
            'amount' => 800000,
            'status' => 'pending',
            'paid_at' => now()->toDateString(),
        ]);

        $response = $this->patch(route('invoices.payments.reject', [$invoice, $payment]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => $payment->getMorphClass(), 'subject_id' => $payment->id]);
    }

    public function test_admin_cannot_process_payment_of_another_invoice(): void
    {
        $this->login();
        $invoiceA = $this->sentInvoice(800000);
        $invoiceB = $this->sentInvoice(800000);
        $paymentB = PaymentFactory::new()->create([
            'invoice_id' => $invoiceB->id,
            'amount' => 800000,
            'status' => 'pending',
            'paid_at' => now()->toDateString(),
        ]);

        $this->patch(route('invoices.payments.confirm', [$invoiceA, $paymentB]))->assertNotFound();
        $this->patch(route('invoices.payments.reject', [$invoiceA, $paymentB]))->assertNotFound();

        $this->assertSame('pending', $paymentB->fresh()->status);
    }

    public function test_admin_cannot_double_process_payment(): void
    {
        $this->login();
        $invoice = $this->sentInvoice(800000);
        $payment = PaymentFactory::new()->create([
            'invoice_id' => $invoice->id,
            'amount' => 800000,
            'status' => 'confirmed',
            'paid_at' => now()->toDateString(),
        ]);

        $this->patch(route('invoices.payments.reject', [$invoice, $payment]))
            ->assertSessionHas('error');

        $this->assertSame('confirmed', $payment->fresh()->status);
    }

    // ---------- manajemen tautan di admin ----------

    public function test_admin_generates_token_for_sent_invoice_without_link(): void
    {
        $this->login();
        $invoice = $this->sentInvoice(800000, ['public_token' => null]);

        $this->post(route('invoices.payment-link.generate', $invoice))->assertSessionHas('success');

        $invoice->refresh();
        $this->assertNotNull($invoice->public_token);
        $this->assertSame(64, strlen($invoice->public_token));
    }

    public function test_admin_revokes_token(): void
    {
        $this->login();
        $invoice = $this->sentInvoice(800000);

        $this->delete(route('invoices.payment-link.revoke', $invoice))->assertSessionHas('success');

        $this->assertNull($invoice->fresh()->public_token);
    }

    public function test_generate_link_blocked_for_draft_invoice(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->withItems(500000)->create(['status' => InvoiceStatus::Draft]);

        $this->post(route('invoices.payment-link.generate', $invoice))->assertSessionHas('error');

        $this->assertNull($invoice->fresh()->public_token);
    }

    public function test_show_page_displays_public_link_and_pending_actions(): void
    {
        $this->login();
        $invoice = $this->sentInvoice(800000);
        PaymentFactory::new()->create([
            'invoice_id' => $invoice->id,
            'amount' => 800000,
            'status' => 'pending',
            'paid_at' => now()->toDateString(),
        ]);

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Salin tautan bayar');
        $response->assertSee('Cabut tautan');
        $response->assertSee(route('invoices.public.show', ['token' => $invoice->public_token]));
        $response->assertSee('Konfirmasi');
        $response->assertSee('Tolak');
    }

    public function test_public_routes_are_not_behind_auth(): void
    {
        $invoice = $this->sentInvoice(800000);

        // Tanpa actingAs — halaman publik harus tetap 200.
        $this->get(route('invoices.public.show', ['token' => $invoice->public_token]))->assertOk();
    }
}
