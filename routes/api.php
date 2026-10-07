<?php

use App\Domains\Security\Http\Controllers\SecurityEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API monitoring keamanan (F3-3)
|--------------------------------------------------------------------------
|
| Dipanggil oleh script monitoring di server klien. Semua endpoint dilindungi
| token (middleware security.api) + rate limit. Payload HANYA metadata temuan —
| jangan pernah mengirim kredensial/rahasia server ke API ini.
|
| Endpoint tambahan untuk aggregator script (devops task companion):
| - POST /api/security/monitoring/events — terima batch data WAF, brute force, rate limit
| - POST /api/security/monitoring/ssl — terima data sertifikat SSL
| - POST /api/security/monitoring/traffic — terima data traffic/requests per site
| - GET  /api/security/monitoring/status — health check untuk script aggregator
*/

Route::middleware('security.api')->prefix('security')->name('api.security.')->group(function () {
    // Ingest batch temuan (idempotent via external_id atau dedup window).
    Route::post('events', [SecurityEventController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('events');

    // Webhook Uptime Kuma: DOWN/UP -> insiden CRM.
    Route::post('uptime-events', [SecurityEventController::class, 'uptimeEvents'])
        ->middleware('throttle:60,1')
        ->name('uptime-events');

    // Health check untuk script monitoring.
    Route::get('status', [SecurityEventController::class, 'status'])
        ->middleware('throttle:120,1')
        ->name('status');

    // Aggregator endpoints untuk Security Monitoring Dashboard
    Route::prefix('monitoring')->name('monitoring.')->group(function () {
        Route::post('events', [SecurityEventController::class, 'storeMonitoringEvents'])
            ->middleware('throttle:60,1')
            ->name('events');
        Route::post('ssl', [SecurityEventController::class, 'storeSslData'])
            ->middleware('throttle:60,1')
            ->name('ssl');
        Route::post('traffic', [SecurityEventController::class, 'storeTrafficData'])
            ->middleware('throttle:60,1')
            ->name('traffic');
        Route::get('status', [SecurityEventController::class, 'monitoringStatus'])
            ->middleware('throttle:120,1')
            ->name('status');
    });
});

// Midtrans webhook (tanpa CSRF, verifikasi signature)
Route::post('midtrans/notification', \App\Domains\Invoicing\Http\Controllers\MidtransWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.midtrans.notification');

// Pakasir webhook (tanpa CSRF, verifikasi X-Secret)
Route::post('webhooks/pakasir', \App\Domains\Invoicing\Http\Controllers\PakasirWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('api.pakasir.webhook');
