<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Integrasi Midtrans Snap (t_d4bd0b03).
 *
 * Alur: halaman invoice publik (/pay/{token}) meminta Snap token
 * (transaction token) dari server → token dipakai script `snap.js` di browser
 * untuk membuka popup pembayaran. Notifikasi status masuk lewat webhook
 * (POST /payments/midtrans/notification) yang diverifikasi lewat
 * `signature_key` (HMAC SHA512 atas payload + server key).
 *
 * Prinsip yang dijaga:
 *  - Midtrans hanya BOLEH melunasi invoice (payment `confirmed` +
 *    `Invoice::markPaid()`), tidak pernah membuat invoice atau mengubah
 *    nominal — nominal selalu dari server (invoice.total).
 *  - Idempoten: notifikasi berulang untuk order_id yang sama tidak
 *    menggandakan pembayaran maupun tidak melempar error.
 *  - `enabled=false` (default) = fitur mati total, halaman publik tetap
 *    menampilkan instruksi transfer bank (tidak ada error).
 */
class MidtransSnap
{
    /** Status transaksi Midtrans yang berarti pembayaran berhasil. */
    public const SUCCESS_STATUSES = ['capture', 'settlement'];

    /** Status transaksi Midtrans yang berarti pembayaran gagal/expired. */
    public const FAILED_STATUSES = ['deny', 'cancel', 'expire', 'failure'];

    /** Nilai kolom `payments.method` untuk pembayaran via Snap. */
    public const METHOD = 'midtrans';

    /** Midtrans aktif: enabled + server key + client key terisi. */
    public static function isEnabled(): bool
    {
        return (bool) config('crm.midtrans.enabled')
            && filled(config('crm.midtrans.server_key'))
            && filled(config('crm.midtrans.client_key'));
    }

    /**
     * Snap token yang dibuat server untuk invoice. Token disimpan sebagai
     * payment `pending`(method gateway) supaya webhook bisa menautkannya
     * kembali ke invoice — bukan sekadar token sekali pakai di browser.
     *
     * @throws RuntimeException saat Midtrans tidak dikonfigurasi atau API menolak.
     */
    public static function createTransaction(Invoice $invoice): Payment
    {
        abort_unless($invoice->isCollectible(), 422, 'Invoice kontrak yang sudah dipecah menjadi termin tidak dapat dibayar.');
        abort_if($invoice->isTerminal(), 422, 'Invoice sudah lunas atau dibatalkan.');

        // Satu pembayaran pending per invoice: bila Snap token lama masih
        // ada, dipakai ulang; selain itu baris pending yang sama dipakai
        // ulang untuk transaksi baru (tidak membuat baris yatim).
        $existing = $invoice->payments()
            ->where('method', self::METHOD)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existing && filled($existing->snapToken())) {
            return $existing;
        }

        $orderId = self::orderId($invoice);
        $payload = self::transactionPayload($invoice, $orderId);

        $response = self::request()->post((string) config('crm.midtrans.snap_base_url'), $payload);

        if ($response->failed()) {
            Log::warning('Gagal membuat transaksi Midtrans Snap.', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Midtrans menolak permintaan pembayaran. Silakan coba lagi atau hubungi kami.');
        }

        $token = (string) $response->json('token', '');

        if (blank($token)) {
            Log::warning('Respons Midtrans Snap tanpa token.', [
                'invoice' => $invoice->number,
            ]);

            throw new RuntimeException('Respons pembayaran tidak lengkap. Silakan coba lagi.');
        }

        $attributes = [
            'sender_name' => $invoice->client?->name,
            'amount' => (int) $invoice->total,
            'method' => self::METHOD,
            'status' => 'pending',
            'paid_at' => now(),
            'upstream_order_id' => $orderId,
            'snap_token' => $token,
            'upstream_raw_response' => $response->json(),
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return $invoice->payments()->create($attributes);
    }

    /**
     * Terapkan notifikasi Midtrans ke invoice terkait.
     *
     * Status sukses -> payment `confirmed` + invoice lunas (dibatasi
     * `Invoice::markPaid()`, jadi invoice yang tidak boleh dilunasi tetap aman).
     * Status gagal -> payment `rejected`, invoice TIDAK berubah.
     *
     * @return array<string, string|null> status payment & invoice setelah diproses
     */
    public static function applyNotification(Payment $payment, array $payload): array
    {
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');

        if ($payment->status === 'confirmed') {
            // Notifikasi berulang (Midtrans mengirim beberapa kali) — idempoten.
            return [
                'payment' => 'confirmed',
                'invoice' => $payment->invoice?->status?->value,
            ];
        }

        $transactionId = (string) ($payload['transaction_id'] ?? $payment->upstream_transaction_id);
        $paymentType = (string) ($payload['payment_type'] ?? '');
        $grossAmount = isset($payload['gross_amount']) ? (int) $payload['gross_amount'] : $payment->amount;

        if ($transactionStatus === 'pending') {
            // Pembayaran belum final (mis. transfer bank via VA) — tunggu.
            $payment->update([
                'upstream_transaction_status' => $transactionStatus,
                'upstream_transaction_id' => $transactionId,
                'upstream_payment_type' => $paymentType,
                'upstream_raw_response' => $payload,
            ]);

            return ['payment' => 'pending', 'invoice' => $payment->invoice?->status?->value];
        }

        $isSuccess = in_array($transactionStatus, self::SUCCESS_STATUSES, true);
        $isFailed = in_array($transactionStatus, self::FAILED_STATUSES, true);

        if (! $isSuccess && ! $isFailed) {
            // Status tak dikenal (mis. "authorize" tanpa capture): catat tanpa
            // mengubah apa pun supaya bisa ditinjau manual.
            $payment->update([
                'upstream_transaction_status' => $transactionStatus,
                'upstream_raw_response' => $payload,
            ]);

            return ['payment' => $payment->status, 'invoice' => $payment->invoice?->status?->value];
        }

        // Nominal dari Midtrans harus sama dengan tagihan; selisih berarti
        // data tidak sinkron sehingga tidak boleh melunasi invoice.
        if ($isSuccess && $grossAmount !== $payment->amount) {
            Log::error('Nominal pembayaran Midtrans tidak cocok dengan tagihan.', [
                'order_id' => $payment->upstream_order_id,
                'midtrans' => $grossAmount,
                'invoice' => $payment->amount,
            ]);

            $payment->update([
                'upstream_transaction_status' => $transactionStatus,
                'upstream_raw_response' => $payload,
            ]);

            return ['payment' => $payment->status, 'invoice' => $payment->invoice?->status?->value];
        }

        $payment->update([
            'status' => $isSuccess ? 'confirmed' : 'rejected',
            'upstream_transaction_status' => $transactionStatus,
            'upstream_transaction_id' => $transactionId,
            'upstream_payment_type' => $paymentType,
            'upstream_raw_response' => $payload,
            'confirmed_by' => null,
        ]);

        $invoice = $payment->invoice;

        if ($isSuccess && $invoice && ! $invoice->isTerminal()) {
            $invoice->markPaid();
        }

        return [
            'payment' => $payment->status,
            'invoice' => $invoice?->fresh()?->status?->value,
        ];
    }

    /**
     * Verifikasi `signature_key` dari notifikasi Midtrans:
     * SHA512( order_id + status_code + gross_amount + server_key ).
     * Perbandingan memakai hash_equals (timing-safe).
     */
    public static function verifySignature(array $payload): bool
    {
        $provided = (string) ($payload['signature_key'] ?? '');
        $serverKey = (string) config('crm.midtrans.server_key');

        if (blank($provided) || blank($serverKey)) {
            return false;
        }

        $expected = hash('sha512',
            ($payload['order_id'] ?? '').
            ($payload['status_code'] ?? '').
            ($payload['gross_amount'] ?? '').
            $serverKey
        );

        return hash_equals($expected, $provided);
    }

    /** Order id Midtrans: unik, <= 50 karakter, tanpa karakter khusus. */
    public static function orderId(Invoice $invoice): string
    {
        return 'inv-'.$invoice->id.'-'.strtolower(Str::random(16));
    }

    /** Payload transaksi Snap untuk invoice. */
    protected static function transactionPayload(Invoice $invoice, string $orderId): array
    {
        $business = config('crm.business');
        $items = $invoice->items->isEmpty()
            ? [[
                'id' => (string) $invoice->id,
                'price' => (int) $invoice->total,
                'quantity' => 1,
                'name' => 'Invoice '.$invoice->number,
            ]]
            : $invoice->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'price' => (int) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'name' => Str::limit($item->description, 40, ''),
            ])->all();

        return [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $invoice->total,
            ],
            'item_details' => $items,
            'customer_details' => array_filter([
                'first_name' => Str::limit($invoice->client?->contact_name ?: $invoice->client?->name, 40, ''),
                'email' => $invoice->client?->email,
                'phone' => InvoiceDelivery::normalizeWhatsapp($invoice->client?->whatsapp),
            ]),
            'expiry' => [
                // Batas pembayaran: tengah malam invoice jatuh tempo.
                'start_time' => Carbon::now()->toIso8601String(),
                'unit' => 'hour',
                'duration' => max(1, Carbon::today()->diffInHours($invoice->due_date, false) + 24),
            ],
            'callbacks' => array_filter([
                'finish' => route('invoices.public.show', ['token' => $invoice->public_token]),
            ]),
        ];
    }

    /** HTTP client terautentikasi Basic Auth server-key (basic:serverKey). */
    protected static function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withBasicAuth('', (string) config('crm.midtrans.server_key'))
            ->timeout(30);
    }
}
