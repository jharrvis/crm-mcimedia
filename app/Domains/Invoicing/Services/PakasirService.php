<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integrasi Pakasir API v2 untuk pembayaran invoice.
 *
 * Alur:
 * 1. createTransaction($invoice, $method) — buat transaksi di Pakasir,
 *    dapat payment_link / qr_string / va_number
 * 2. Klien bayar via halaman Pakasir (atau scan QR / transfer VA)
 * 3. Pakasir kirim webhook ke /api/webhooks/pakasir (header X-Secret)
 * 4. handleWebhook() — verifikasi secret, update status invoice + catat payment
 */
class PakasirService
{
    /** Metode yang didukung Pakasir. */
    public const METHODS = [
        'payment_link' => 'Payment Link',
        'qris' => 'QRIS',
        'bri_va' => 'BRI Virtual Account',
        'bni_va' => 'BNI Virtual Account',
        'cimb_niaga_va' => 'CIMB Niaga Virtual Account',
        'maybank_va' => 'Maybank Virtual Account',
        'permata_va' => 'Permata Virtual Account',
        'bnc_va' => 'Bank Neo Commerce VA',
        'artha_graha_va' => 'Artha Graha VA',
        'sampoerna_va' => 'Sampoerna VA',
    ];

    protected function base(): string
    {
        return rtrim(config('pakasir.api_base'), '/');
    }

    protected function headers(): array
    {
        return [
            'X-Api-Key' => config('pakasir.api_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Buat transaksi Pakasir untuk invoice.
     * Idempotent di sisi Pakasir (find-or-create per slug+order_id).
     *
     * @return array{txn_id:string,payment_link?:string,qr_string?:string,va_number?:string,expired_at?:string}
     */
    public function createTransaction(Invoice $invoice, string $method = 'payment_link'): array
    {
        if (! isset(self::METHODS[$method])) {
            throw new \InvalidArgumentException("Metode pembayaran tidak didukung: {$method}");
        }

        // Pakai ulang transaksi yang masih pending
        if ($invoice->pakasir_txn_id && in_array($invoice->pakasir_status, ['pending', null], true)) {
            $existing = $this->getTransaction($invoice->pakasir_txn_id);
            if ($existing && ($existing['status'] ?? '') !== 'completed') {
                return $existing;
            }
        }

        $orderId = 'INV-'.$invoice->number;

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post($this->base().'/api/v2/create-transaction/'.config('pakasir.slug').'/'.$orderId, [
                'method' => $method,
                'amount' => (int) $invoice->total,
            ]);

        if (! $response->successful()) {
            Log::error('Pakasir create transaction gagal', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Gagal membuat transaksi Pakasir: '.$response->body());
        }

        $data = $response->json();

        $invoice->update([
            'pakasir_txn_id' => $data['txn_id'] ?? null,
            'pakasir_status' => 'pending',
        ]);

        Log::info('Pakasir transaction dibuat', [
            'invoice' => $invoice->number,
            'txn_id' => $data['txn_id'] ?? null,
            'method' => $method,
        ]);

        return $data;
    }

    /** Ambil detail transaksi dari Pakasir. */
    public function getTransaction(string $txnId): ?array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(15)
            ->get($this->base().'/api/v2/transaction/'.config('pakasir.slug').'/'.$txnId);

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Handle webhook Pakasir.
     * Verifikasi via header X-Secret yang dikonfigurasi di dashboard proyek.
     */
    public function handleWebhook(array $payload, ?string $secret): bool
    {
        $expected = config('pakasir.webhook_secret');

        if (! $expected || ! hash_equals($expected, (string) $secret)) {
            Log::warning('Pakasir webhook: secret tidak valid');
            return false;
        }

        $txnId = $payload['txn_id'] ?? '';
        $orderId = $payload['order_id'] ?? '';
        $amount = (int) ($payload['amount'] ?? 0);
        $status = $payload['status'] ?? '';

        $invoice = Invoice::where('pakasir_txn_id', $txnId)->first();

        // Fallback: cocokkan via order_id = INV-{number}
        if (! $invoice && str_starts_with($orderId, 'INV-')) {
            $number = substr($orderId, 4);
            $invoice = Invoice::where('number', $number)->first();
        }

        if (! $invoice) {
            Log::warning('Pakasir webhook: invoice tidak ditemukan', [
                'txn_id' => $txnId, 'order_id' => $orderId,
            ]);
            return false;
        }

        // Idempotency: jangan proses dua kali
        if ($invoice->status === InvoiceStatus::Paid) {
            Log::info('Pakasir webhook: invoice sudah lunas, diabaikan', [
                'invoice' => $invoice->number,
            ]);
            return true;
        }

        // Validasi nominal
        if ($amount !== (int) $invoice->total) {
            Log::warning('Pakasir webhook: nominal tidak cocok', [
                'invoice' => $invoice->number,
                'expected' => (int) $invoice->total,
                'received' => $amount,
            ]);
            return false;
        }

        $invoice->update(['pakasir_status' => $status]);

        if ($status === 'completed') {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ]);

            Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => 'pakasir',
                'status' => 'confirmed',
                'paid_at' => now(),
                'note' => 'Pakasir '.$txnId.' / '.$orderId,
            ]);

            Log::info('Pakasir: invoice lunas', [
                'invoice' => $invoice->number,
                'txn_id' => $txnId,
                'amount' => $amount,
            ]);
        }

        return true;
    }
}
