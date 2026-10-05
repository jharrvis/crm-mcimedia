<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integrasi Midtrans Snap untuk pembayaran invoice.
 *
 * Alur:
 * 1. createSnapToken($invoice) — buat transaksi di Midtrans, dapat snap token
 * 2. Klien bayar via popup Snap.js (metode otomatis ikut yang aktif di dashboard)
 * 3. Midtrans kirim webhook ke /api/midtrans/notification
 * 4. handleNotification() — verifikasi signature, update status invoice + catat payment
 */
class MidtransService
{
    /** Buat Snap transaction untuk invoice, return snap token. */
    public function createSnapToken(Invoice $invoice): string
    {
        $invoice->loadMissing(['client', 'items']);

        // Jika sudah ada snap token yang masih valid, pakai ulang
        if ($invoice->midtrans_snap_token && $invoice->midtrans_order_id) {
            $status = $this->getTransactionStatus($invoice->midtrans_order_id);
            if (in_array($status, ['pending', 'authorize', null], true)) {
                return $invoice->midtrans_snap_token;
            }
        }

        $orderId = 'INV-'.$invoice->number.'-'.time();

        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $invoice->total,
            ],
            'customer_details' => [
                'first_name' => $invoice->client->name ?? 'Customer',
                'email' => $invoice->client->email ?? '',
                'phone' => $invoice->client->phone ?? '',
            ],
            'item_details' => $invoice->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'price' => (int) $item->price,
                'quantity' => (int) $item->qty,
                'name' => mb_substr($item->description, 0, 50),
            ])->values()->all(),
            'callbacks' => [
                'finish' => route('invoices.public.show', $invoice->public_token),
            ],
        ];

        $response = Http::withBasicAuth(config('midtrans.server_key'), '')
            ->post(config('midtrans.api_base').'/snap/v1/transactions', $params);

        if (! $response->successful()) {
            Log::error('Midtrans Snap gagal', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Gagal membuat transaksi Midtrans: '.$response->body());
        }

        $token = $response->json('token');

        $invoice->update([
            'midtrans_order_id' => $orderId,
            'midtrans_snap_token' => $token,
            'midtrans_transaction_status' => 'pending',
        ]);

        return $token;
    }

    /** Ambil status transaksi dari Midtrans API. */
    public function getTransactionStatus(string $orderId): ?string
    {
        $response = Http::withBasicAuth(config('midtrans.server_key'), '')
            ->get(config('midtrans.api_base').'/v2/'.$orderId.'/status');

        return $response->successful() ? $response->json('transaction_status') : null;
    }

    /**
     * Handle webhook notification dari Midtrans.
     * Verifikasi signature_key = SHA512(order_id + status_code + gross_amount + server_key).
     */
    public function handleNotification(array $payload): bool
    {
        $orderId = $payload['order_id'] ?? '';
        $statusCode = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';
        $signatureKey = $payload['signature_key'] ?? '';

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.config('midtrans.server_key'));

        if (! hash_equals($expected, $signatureKey)) {
            Log::warning('Midtrans webhook: signature tidak valid', ['order_id' => $orderId]);

            return false;
        }

        $invoice = Invoice::where('midtrans_order_id', $orderId)->first();

        if (! $invoice) {
            Log::warning('Midtrans webhook: invoice tidak ditemukan', ['order_id' => $orderId]);

            return false;
        }

        $transactionStatus = $payload['transaction_status'] ?? '';
        $paymentType = $payload['payment_type'] ?? '';
        $fraudStatus = $payload['fraud_status'] ?? '';

        $invoice->update(['midtrans_transaction_status' => $transactionStatus]);

        // Status yang dianggap lunas
        $isPaid = $transactionStatus === 'capture' && $fraudStatus !== 'deny'
            || $transactionStatus === 'settlement';

        if ($isPaid && $invoice->status !== InvoiceStatus::Paid) {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ]);

            Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => (int) $grossAmount,
                'method' => 'midtrans_'.$paymentType,
                'status' => 'confirmed',
                'paid_at' => now(),
                'note' => 'Midtrans '.$paymentType.' / '.$orderId,
            ]);

            Log::info('Midtrans: invoice lunas', [
                'invoice' => $invoice->number,
                'order_id' => $orderId,
                'amount' => $grossAmount,
            ]);
        }

        return true;
    }
}
