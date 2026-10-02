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
*/

Route::middleware('security.api')->prefix('security')->name('api.security.')->group(function () {
    // Ingest batch temuan (idempotent via external_id atau dedup window).
    Route::post('events', [SecurityEventController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('events');

    // Health check untuk script monitoring.
    Route::get('status', [SecurityEventController::class, 'status'])
        ->middleware('throttle:120,1')
        ->name('status');
});
