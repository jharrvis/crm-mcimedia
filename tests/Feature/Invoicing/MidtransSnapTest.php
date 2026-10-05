<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use App\Domains\Invoicing\Services\MidtransSnap;
use App\Models\User;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * t_d4bd0b03: integrasi Midtrans Snap untuk pembayaran invoice publik.
 *
 * Cakupan: konfigurasi/env, pembuatan Snap token, tampilan tombol bayar
 * online, webhook notifikasi (signature, idempotensi, nominal, status gagal,
 * penolakan invoice non-tagih), sertamtx isolasi dari jalur transfer bank.
 */
class MidtransSnapTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB_server_key_dummy';

    protected function setUp(): void
    {
        parent::setUp();

        // Default: fitur aktif dengan kredensial dummy & endpoint可控.
        config([
            'crm.midtrans.enabled' => true,
            'crm.midtrans.server_key' => self::SERVER_KEY,
            'crm.midtrans.client_key' => 'SB_client_key_dummy',
            'crm.midtrans.is_production' => false,
            'crm.midtrans.snap_base_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        ]);
    }

    private function sentInvoice(int $total = 800000, array $overrides = []): Invoice
    {
        return InvoiceFactory::new()->withItems($total)->create(array_merge([
            'status' => InvoiceStatus::Sent,
            'public_token' => Str::random(64),
        ], $overrides));
    }

    private function fakeSnap(string $token = 'snap-token-abc'): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response(['token' => $token], 201),
        ]);
    }

    /** Payload notifikasi Midtrans dengan signature_key valid. */
    private function notification(array $overrides = []): array
    {
        $payload = array_merge([
            'order_id' => 'order-123',
            'status_code' => '200',
            'gross_amount' => '800000.00',
            'transaction_status' => 'capture',
            'transaction_id' => 'txn-123',
            'payment_type' => 'qris',
        ], $overrides);

        $payload['signature_key'] = hash('sha512',
            $payload['order_id'].
            $payload['status_code'].
            $payload['gross_amount'].
            self::SERVER_KEY
        );

        return $payload;
    }

    private function pendingSnapPayment(Invoice $invoice, string $orderId = 'order-123', int $amount = 800000): Payment
    {
        return $invoice->payments()->create([
            'amount' => $amount,
            'method' => MidtransSnap::METHOD,
            'status' => 'pending',
            'paid_at' => now(),
            'upstream_order_id' => $orderId,
            'snap_token' => 'snap-token-abc',
            'upstream_raw_response' => ['token' => 'snap-token-abc'],
        ]);
    }

    // ---------- konfigurasi ----------

    public function test_is_enabled_requires_env_keys(): void
    {
        config(['crm.midtrans.enabled' => false]);
        $this->assertFalse(MidtransSnap::isEnabled());

        config(['crm.midtrans.enabled' => true, 'crm.midtrans.server_key' => '']);
        $this->assertFalse(MidtransSnap::isEnabled());

        config(['crm.midtrans.server_key' => self::SERVER_KEY, 'crm.midtrans.client_key' => '']);
        $this->assertFalse(MidtransSnap::isEnabled());

        config(['crm.midtrans.client_key' => 'SB_client_key_dummy']);
        $this->assertTrue(MidtransSnap::isEnabled());
    }

    public function test_verify_signature_matches_midtrans_algorithm(): void
    {
        $payload = $this->notification();

        $this->assertTrue(MidtransSnap::verifySignature($payload));

        // Salah satu field diubah -> signature tidak cocok.
        $tampered = $payload;
        $tampered['gross_amount'] = '1.00';
        $this->assertFalse(MidtransSnap::verifySignature($tampered));

        // Signature kosong ditolak.
        $this->assertFalse(MidtransSnap::verifySignature(['order_id' => 'order-123']));
    }

    // ---------- pembuatan Snap token (halaman publik) ----------

    public function test_public_page_shows_online_payment_button_when_enabled(): void
    {
        $invoice = $this->sentInvoice();

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertSee('Bayar online');
        $response->assertSee('snap-pay-btn', false);
        $response->assertSee('app.sandbox.midtrans.com/snap/snap.js', false);
    }

    public function test_public_page_hides_online_payment_when_disabled(): void
    {
        config(['crm.midtrans.enabled' => false]);

        $invoice = $this->sentInvoice();

        $response = $this->get(route('invoices.public.show', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertDontSee('snap-pay-btn', false);
        // Jalur transfer bank tetap utuh sebagai fallback.
        $response->assertSee('Instruksi transfer');
    }

    public function test_snap_token_endpoint_returns_token_and_creates_pending_payment(): void
    {
        $this->fakeSnap();

        $invoice = $this->sentInvoice(800000);

        $response = $this->postJson(route('invoices.public.snap-token', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertJsonPath('snap_token', 'snap-token-abc');
        $response->assertJsonPath('client_key', 'SB_client_key_dummy');

        $payment = Payment::where('method', MidtransSnap::METHOD)->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(800000, $payment->amount);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertNotNull($payment->upstream_order_id);

        // Invoice belum berubah status sebelum notifikasi.
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_snap_token_endpoint_sends_server_key_and_invoice_amount_to_midtrans(): void
    {
        $this->fakeSnap();

        $invoice = $this->sentInvoice(800000);

        $this->postJson(route('invoices.public.snap-token', ['token' => $invoice->public_token]))->assertOk();

        Http::assertSent(function ($request) use ($invoice) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Basic '.base64_encode(':'.self::SERVER_KEY))
                && $body['transaction_details']['gross_amount'] === $invoice->total
                && str_starts_with((string) $body['transaction_details']['order_id'], 'inv-'.$invoice->id.'-')
                && str_ends_with((string) $request->url(), '/snap/v1/transactions');
        });
    }

    public function test_snap_token_endpoint_reuses_pending_transaction(): void
    {
        $this->fakeSnap();

        $invoice = $this->sentInvoice();
        $payment = $this->pendingSnapPayment($invoice);

        $response = $this->postJson(route('invoices.public.snap-token', ['token' => $invoice->public_token]));

        $response->assertOk();
        $response->assertJsonPath('snap_token', 'snap-token-abc');

        // Tidak ada panggilan API tambahan & tidak ada payment baru.
        Http::assertNothingSent();
        $this->assertSame(1, $invoice->payments()->where('method', MidtransSnap::METHOD)->count());
        $this->assertSame($payment->id, $invoice->payments()->where('method', MidtransSnap::METHOD)->first()->id);
    }

    public function test_snap_token_endpoint_rejects_cancelled_and_terminal_invoice(): void
    {
        $this->fakeSnap();

        $cancelled = $this->sentInvoice(800000, ['status' => InvoiceStatus::Cancelled]);
        $this->postJson(route('invoices.public.snap-token', ['token' => $cancelled->public_token]))
            ->assertStatus(422);

        $paid = $this->sentInvoice(800000, ['status' => InvoiceStatus::Paid]);
        $this->postJson(route('invoices.public.snap-token', ['token' => $paid->public_token]))
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_snap_token_endpoint_returns_503_when_disabled(): void
    {
        config(['crm.midtrans.enabled' => false]);

        $invoice = $this->sentInvoice();

        $this->postJson(route('invoices.public.snap-token', ['token' => $invoice->public_token]))
            ->assertStatus(503);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_snap_token_endpoint_surfaces_midtrans_failure_as_502(): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response(['error_messages' => ['denied']], 400),
        ]);

        $invoice = $this->sentInvoice();

        $response = $this->postJson(route('invoices.public.snap-token', ['token' => $invoice->public_token]));

        $response->assertStatus(502);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_snap_token_endpoint_rejects_termin_parent_invoice(): void
    {
        $this->fakeSnap();

        $parent = $this->sentInvoice(800000);
        $parent->terminInvoices()->create([
            'client_id' => $parent->client_id,
            'number' => 'INV-202610-0002',
            'title' => 'Termin 1',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'status' => InvoiceStatus::Draft,
            'subtotal' => 400000,
            'total' => 400000,
        ]);

        $this->postJson(route('invoices.public.snap-token', ['token' => $parent->public_token]))
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    // ---------- webhook notifikasi ----------

    public function test_webhook_confirmation_marks_payment_confirmed_and_invoice_paid(): void
    {
        $invoice = $this->sentInvoice();
        $payment = $this->pendingSnapPayment($invoice);

        $response = $this->postJson(route('midtrans.notification'), $this->notification());

        $response->assertOk();

        $payment->refresh();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('capture', $payment->upstream_transaction_status);
        $this->assertSame('qris', $payment->upstream_payment_type);
        $this->assertSame('txn-123', $payment->upstream_transaction_id);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_webhook_settlement_also_marks_invoice_paid(): void
    {
        $invoice = $this->sentInvoice();
        $this->pendingSnapPayment($invoice);

        $this->postJson(route('midtrans.notification'), $this->notification([
            'transaction_status' => 'settlement',
        ]))->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_webhook_is_idempotent_on_repeated_notification(): void
    {
        $invoice = $this->sentInvoice();
        $this->pendingSnapPayment($invoice);

        $payload = $this->notification();

        $this->postJson(route('midtrans.notification'), $payload)->assertOk();
        $this->postJson(route('midtrans.notification'), $payload)->assertOk();
        $this->postJson(route('midtrans.notification'), $payload)->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame('confirmed', $invoice->payments()->first()->status);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $invoice = $this->sentInvoice();
        $payment = $this->pendingSnapPayment($invoice);

        $payload = $this->notification();
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson(route('midtrans.notification'), $payload)->assertStatus(403);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_webhook_requires_signature_presence(): void
    {
        $payload = $this->notification();
        unset($payload['signature_key']);

        $this->postJson(route('midtrans.notification'), $payload)->assertStatus(403);
    }

    public function test_webhook_returns_200_for_unknown_order_id(): void
    {
        $this->postJson(route('midtrans.notification'), $this->notification())
            ->assertOk();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_webhook_pending_status_keeps_payment_pending(): void
    {
        $invoice = $this->sentInvoice();
        $payment = $this->pendingSnapPayment($invoice);

        $this->postJson(route('midtrans.notification'), $this->notification([
            'transaction_status' => 'pending',
            'payment_type' => 'bank_transfer',
            'fraud_status' => 'accept',
        ]))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_webhook_expired_status_rejects_payment_without_touching_invoice(): void
    {
        $invoice = $this->sentInvoice();
        $payment = $this->pendingSnapPayment($invoice);

        $this->postJson(route('midtrans.notification'), $this->notification([
            'transaction_status' => 'expire',
            'status_code' => '200',
        ]))->assertOk();

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_webhook_with_amount_mismatch_does_not_pay_invoice(): void
    {
        $invoice = $this->sentInvoice(800000);
        $payment = $this->pendingSnapPayment($invoice, amount: 800000);

        $this->postJson(route('midtrans.notification'), $this->notification([
            'gross_amount' => '500000.00',
        ]))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_webhook_denies_lunasi_when_invoice_already_paid(): void
    {
        $invoice = $this->sentInvoice(800000, ['status' => InvoiceStatus::Paid]);
        $payment = $this->pendingSnapPayment($invoice);

        $this->postJson(route('midtrans.notification'), $this->notification())->assertOk();

        // Payment terkonfirmasi, invoice tetap paid (tidak error 500).
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_webhook_returns_503_when_midtrans_disabled(): void
    {
        config(['crm.midtrans.enabled' => false]);

        $invoice = $this->sentInvoice();
        $payment = $this->pendingSnapPayment($invoice);

        $this->postJson(route('midtrans.notification'), $this->notification())->assertStatus(503);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    // ---------- isolasi dari jalur pembayaran manual ----------

    public function test_manual_bank_transfer_payment_is_untouched_by_webhook(): void
    {
        $invoice = $this->sentInvoice();
        $payment = $invoice->payments()->create([
            'amount' => 800000,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'paid_at' => now(),
            'sender_name' => 'Budi',
        ]);

        // Notifikasi untuk order yang tidak ada -> tidak menautkan ke payment manual.
        $this->postJson(route('midtrans.notification'), $this->notification())->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->upstream_order_id);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_gateway_payment_shows_in_admin_invoice_detail(): void
    {
        $invoice = $this->sentInvoice();
        $payment = $this->pendingSnapPayment($invoice);

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee($payment->upstream_order_id);
    }

    /** Sufiks acak order id (16 karakter) — dibangkitkan per request. */
    private function orderIdSuffix(): string
    {
        return (string) Payment::where('method', MidtransSnap::METHOD)->value('upstream_order_id');
    }
}
