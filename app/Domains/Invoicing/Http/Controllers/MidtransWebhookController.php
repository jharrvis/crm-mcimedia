<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Invoicing\Models\Payment;
use App\Domains\Invoicing\Services\MidtransSnap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook notifikasi transaksi Midtrans. Dipanggil server Midtrans tanpa
 * sesi/cookie — makanya ditaruh di routes/api.php (tanpa CSRF/session) dengan
 * throttle. Keamanan berasal dari verifikasi `signature_key` (HMAC SHA512),
 * bukan token sesi. Idempoten: notifikasi berulang untuk order_id yang sama
 * tidak menggandakan pembayaran.
 */
class MidtransWebhookController
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();

        if (! MidtransSnap::isEnabled()) {
            Log::warning('Webhook Midtrans diterima saat fitur nonaktif.', ['payload' => $payload]);

            return response(['message' => 'Midtrans nonaktif.'], 503);
        }

        if (! MidtransSnap::verifySignature($payload)) {
            Log::warning('Webhook Midtrans dengan signature_key tidak valid.', [
                'order_id' => $payload['order_id'] ?? null,
            ]);

            return response(['message' => 'Signature key tidak valid.'], 403);
        }

        $orderId = (string) ($payload['order_id'] ?? '');

        $payment = Payment::where('upstream_order_id', $orderId)
            ->where('method', MidtransSnap::METHOD)
            ->first();

        if ($payment === null) {
            // order_id tidak kita kenal — bisa terjadi saat migrasi antar env.
            // Tetap balas 200 agar Midtrans tidak mengirim ulang berulang.
            Log::warning('Webhook Midtrans untuk order_id yang tidak dikenal.', ['order_id' => $orderId]);

            return response(['message' => 'Order tidak ditemukan.'], 200);
        }

        $result = MidtransSnap::applyNotification($payment, $payload);

        Log::info('Webhook Midtrans diproses.', [
            'order_id' => $orderId,
            'transaction_status' => $payload['transaction_status'] ?? null,
            'payment_status' => $result['payment'],
            'invoice_status' => $result['invoice'],
        ]);

        return response(['message' => 'OK'], 200);
    }
}
