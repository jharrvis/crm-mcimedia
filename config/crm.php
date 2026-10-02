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

        // Logo usaha: path file relatif terhadap public/, mis.
        // "images/business-logo.png". Kosong (default) = kop tanpa logo —
        // PDF & halaman publik tetap tampil normal tanpa error.
        'logo' => env('CRM_BUSINESS_LOGO', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rekening bank (F2-9)
    |--------------------------------------------------------------------------
    |
    | Daftar rekening yang ditampilkan di PDF invoice, halaman bayar publik
    | (/pay/{token}), dan email invoice. Boleh berisi lebih dari satu rekening.
    |
    | Sumber environment (prioritas):
    |   1. CRM_BANK_ACCOUNTS — JSON array objek, mis.
    |      CRM_BANK_ACCOUNTS='[{"name":"BCA","account_number":"0000000000","account_holder":"Nama Pemilik"},{"name":"Bank Lain","account_number":"1111111111","account_holder":"Nama Pemilik"}]'
    |   2. Variabel tunggal lama CRM_BANK_NAME / CRM_BANK_ACCOUNT_NUMBER /
    |      CRM_BANK_ACCOUNT_HOLDER.
    |
    | JANGAN hardcode nomor rekening di kode. Daftar kosong = bagian instruksi
    | pembayaran tampil rapi tanpa rekening (tanpa error).
    |
    */
    'bank' => [
        'accounts' => crm_env_bank_accounts(),
    ],

    'invoice' => [
        // Catatan default di bawah total pada PDF invoice.
        'footer_note' => env(
            'CRM_INVOICE_FOOTER_NOTE',
            'Pembayaran dianggap sah setelah dana masuk ke rekening kami. Mohon sertakan nomor invoice pada berita transfer.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pengiriman WhatsApp via Fonnte (F2-5)
    |--------------------------------------------------------------------------
    |
    | Pengiriman invoice via WhatsApp memakai API Fonnte. Bila `enabled` false
    | atau `token` kosong, job pengiriman WhatsApp akan dilewati (skip) dan
    | hanya menulis peringatan ke log — tanpa exception. Jangan pernah menulis
    | token asli ke repo; isi lewat environment variable FONNTE_TOKEN.
    |
    */
    'fonnte' => [
        'enabled' => env('FONNTE_ENABLED', false),
        'token' => env('FONNTE_TOKEN', ''),
        'endpoint' => env('FONNTE_ENDPOINT', 'https://api.fonnte.com/send'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sinkronisasi HestiaCP (F3-1)
    |--------------------------------------------------------------------------
    |
    | Menarik akun hosting/domain dari API HestiaCP ke CRM (read-only). Semua
    | kredensial 100% dari environment — jangan pernah menulis nilai asli di
    | kode/repo; isi lewat .env server.
    |
    | Autentikasi (prioritas): access/secret key (HESTIA_ACCESS_KEY +
    | HESTIA_SECRET_KEY, disarankan Hestia >= 1.6) bila keduanya diisi; jika
    | tidak, jatuh ke user/password admin (HESTIA_USER + HESTIA_PASSWORD).
    |
    | verify_ssl default false karena API Hestia (port 8083) umumnya memakai
    | sertifikat self-signed. Aktifkan bila server Hestia memakai sertifikat
    | yang valid.
    |
    | enabled=false → sync & tombol "Sinkronkan sekarang" tidak menghubungi API.
    |
    | F4-12 (multi-server): nilai `enabled` kini menjadi KILL SWITCH GLOBAL —
    | `false` mematikan sinkronisasi untuk environment ini maupun seluruh server
    | yang dikelola lewat UI `/hestia/servers`. Server per-panel (sg2, YIARI, PA
    | Salatiga) menyimpan host & kredensialnya sendiri di tabel `hestia_servers`
    | (kredensial terenkripsi) dan tidak lagi memakai blok ini. Bila minimal satu
    | server UI berstatus aktif, proses sync memakai server UI dan mengabaikan
    | host/kredensial di bawah; blok ini tetap berfungsi sebagai fallback untuk
    | instalasi lama yang belum punya server terdaftar.
    |
    */
    'hestia' => [
        'enabled' => env('HESTIA_ENABLED', false),
        'host' => env('HESTIA_HOST', ''),
        'port' => env('HESTIA_PORT', 8083),
        'scheme' => env('HESTIA_SCHEME', 'https'),
        'verify_ssl' => env('HESTIA_VERIFY_SSL', false),
        'user' => env('HESTIA_USER', ''),
        'password' => env('HESTIA_PASSWORD', ''),
        'access_key' => env('HESTIA_ACCESS_KEY', ''),
        'secret_key' => env('HESTIA_SECRET_KEY', ''),
        'timeout' => env('HESTIA_TIMEOUT', 30),
    ],
    /*
    |--------------------------------------------------------------------------
    | Modul monitoring keamanan (F3-3)
    |--------------------------------------------------------------------------
    |
    | API ingest temuan dari script monitoring di server klien. Token asli
    | HANYA di environment (SECURITY_API_TOKEN) — jangan commit ke repo.
    |
    | - api_token kosong = endpoint /api/security/* menolak semua request
    |   (503) sehingga tidak ada jalur tanpa autentikasi.
    | - dedup_window_minutes dipakai untuk temuan tanpa external_id: temuan
    |   dengan klien+sumber+judul sama dalam jendela ini dianggap duplikat.
    | - report_disk: disk Laravel tempat PDF laporan disimpan (default privat).
    |
    */
    'security' => [
        'api_enabled' => env('SECURITY_API_ENABLED', true),
        'api_token' => env('SECURITY_API_TOKEN', ''),
        'dedup_window_minutes' => env('SECURITY_DEDUP_WINDOW_MINUTES', 1440),
        'report_disk' => env('SECURITY_REPORT_DISK', 'local'),
        'report_max_kb' => env('SECURITY_REPORT_MAX_KB', 10240),
    ],

];
