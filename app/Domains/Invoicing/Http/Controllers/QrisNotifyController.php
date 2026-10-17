<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Invoicing\Services\QrisDynamicService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook QRIS dinamis — menerima notifikasi pembayaran dari aplikasi
 * Android (MacroDroid) yang membaca notifikasi GoPay Merchant.
 * Tanpa CSRF/session (server-to-server). Keamanan via header X-Qris-Secret.
 */
class QrisNotifyController extends Controller
{
    /** POST /api/webhooks/qris-notify */
    public function __invoke(Request $request, QrisDynamicService $qris): JsonResponse
    {
        $ok = $qris->handleNotify(
            $request->all(),
            $request->header('X-Qris-Secret')
        );

        return response()->json(['ok' => $ok], $ok ? 200 : 400);
    }
}
