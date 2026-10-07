<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Invoicing\Services\PakasirService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook Pakasir — menerima notifikasi pembayaran completed.
 * Tanpa CSRF/session (server-to-server). Keamanan via header X-Secret.
 */
class PakasirWebhookController extends Controller
{
    /** POST /api/webhooks/pakasir */
    public function __invoke(Request $request, PakasirService $pakasir): JsonResponse
    {
        $ok = $pakasir->handleWebhook(
            $request->all(),
            $request->header('X-Secret')
        );

        return response()->json(['ok' => $ok], $ok ? 200 : 400);
    }
}
