<?php

/*
|--------------------------------------------------------------------------
| QRIS Dinamis (backup pembayaran)
|--------------------------------------------------------------------------
|
| QRIS statis merchant diubah menjadi dinamis per invoice. Konfirmasi
| pembayaran via webhook dari aplikasi Android (MacroDroid) yang membaca
| notifikasi GoPay Merchant.
|
| QRIS_STATIC_PAYLOAD: string hasil scan QRIS statis merchant (sekali set).
| QRIS_WEBHOOK_SECRET: shared secret untuk header X-Qris-Secret webhook.
|
*/

return [
    'enabled' => env('QRIS_ENABLED', false),

    'static_payload' => env('QRIS_STATIC_PAYLOAD', ''),

    'webhook_secret' => env('QRIS_WEBHOOK_SECRET', ''),
];
