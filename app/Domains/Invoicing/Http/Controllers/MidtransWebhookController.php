<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Invoicing\Services\MidtransService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook Midtrans — menerima notifikasi status pembayaran.
 * Tanpa CSRF/session (dipanggil server-to-server oleh Midtrans).
 * Keamanan via verifikasi signature_key SHA512.
 */
class MidtransWebhookController extends Controller
{
    /** POST /api/midtrans/notification */
    public function __invoke(Request $request, MidtransService $midtrans): JsonResponse
    {
        $ok = $midtrans->handleNotification($request->all());

        return response()->json(['ok' => $ok], $ok ? 200 : 400);
    }
}
