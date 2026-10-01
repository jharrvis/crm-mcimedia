<?php

/*
|--------------------------------------------------------------------------
| Identitas usaha CRM MCI Media
|--------------------------------------------------------------------------
|
| Data identitas ini tampil di PDF invoice (dan halaman invoice publik F2-3).
| Semua nilai bisa dioverride lewat environment variable CRM_*.
|
*/

return [

    'business' => [
        'name' => env('CRM_BUSINESS_NAME', 'MCI Media'),
        'address' => env('CRM_BUSINESS_ADDRESS', 'Jakarta, Indonesia'),
        'email' => env('CRM_BUSINESS_EMAIL', 'admin@mcimedia.web.id'),
        'phone' => env('CRM_BUSINESS_PHONE', '+62 812-3456-7890'),
        'whatsapp' => env('CRM_BUSINESS_WHATSAPP', '+62 812-3456-7890'),
    ],

    'bank' => [
        'name' => env('CRM_BANK_NAME', 'Bank Central Asia (BCA)'),
        'account_number' => env('CRM_BANK_ACCOUNT_NUMBER', '1234567890'),
        'account_holder' => env('CRM_BANK_ACCOUNT_HOLDER', 'MCI Media'),
    ],

    'invoice' => [
        // Catatan default di bawah total pada PDF invoice.
        'footer_note' => env(
            'CRM_INVOICE_FOOTER_NOTE',
            'Pembayaran dianggap sah setelah dana masuk ke rekening kami. Mohon sertakan nomor invoice pada berita transfer.'
        ),
    ],

];
