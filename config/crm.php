<?php

use App\Domains\Providers\Drivers\HestiaDomainProviderDriver;
use App\Domains\Providers\Drivers\HostingerDomainProviderDriver;
use App\Domains\Providers\Drivers\ManualDomainProviderDriver;
use App\Domains\Providers\Drivers\NameSiloDomainProviderDriver;

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
        'target' => env('FONNTE_TARGET', ''),
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

        // CC tetap saat laporan dikirim ke klien (F4-3). Kosong = tanpa CC.
        'report_cc_email' => env('CRM_SECURITY_REPORT_CC_EMAIL', 'info@mcimedia.net'),
        'uptime_default_client_id' => env('SECURITY_UPTIME_DEFAULT_CLIENT_ID', null),

        /*
        |----------------------------------------------------------------------
        | Allowlist monitor ID Uptime Kuma yang valid untuk webhook /api/security/uptime-events
        |----------------------------------------------------------------------
        |
        | Hanya monitor_id yang terdaftar di sini yang boleh membuat insiden P1 via webhook.
        | Format: array string monitor_id, mis. ['mon-1', 'mon-2', ...].
        | Bisa diisi via ENV: SECURITY_UPTIME_VALID_MONITOR_IDS='["mon-1","mon-2"]' (JSON array).
        | Jika kosong/NULL: validasi dilewati (backward compat, tidak direkomendasikan untuk production).
        |
        */
        'uptime_valid_monitor_ids' => json_decode(env('SECURITY_UPTIME_VALID_MONITOR_IDS', '[]'), true) ?: [],

        /*
        |----------------------------------------------------------------------
        | Toleransi (menit) perbedaan waktu antara komponen waktu di external_id
        | (format uptime-kuma:{monitor_id}:{YmdHi}) dan field occurred_at webhook.
        |----------------------------------------------------------------------
        */
        'uptime_external_id_time_tolerance_minutes' => env('SECURITY_UPTIME_EXTERNAL_ID_TOLERANCE_MINUTES', 2),

        /*
        |----------------------------------------------------------------------
        | Board kanban eksternal untuk insiden P1 (t_db983e91)
        |----------------------------------------------------------------------
        |
        | Path absolut ke SQLite board Hermes. Jangan pernah hardcode path
        | production di kode layanan agar test suite tidak menulis ke board
        | asli. Saat APP_ENV=testing path ini di-override ke sandbox
        | (lihat IncidentKanbanCardCreator) dan INSERT ke board production
        | dilewati sepenuhnya.
        |
        | - kanban_board_path: path board production (di luar repo).
        | - kanban_sandbox_path: path aman untuk testing; dibuat otomatis
        |   jika belum ada. Harus berada di luar board production.
        */
        'kanban_board_path' => env('KANBAN_BOARD_PATH', base_path('../../.hermes/kanban/boards/mci-team/kanban.db')),
        'kanban_sandbox_path' => env('KANBAN_SANDBOX_PATH', storage_path('app/test-kanban-sandbox.db')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registry penyedia domain/hosting (F4-5)
    |--------------------------------------------------------------------------
    |
    | Daftar kelas driver yang tersedia di dropdown "tambah provider". Menambah
    | penyedia baru = buat kelas yang mengimplementasikan
    | App\Domains\Providers\Contracts\DomainProviderDriver lalu tambahkan
    | kelasnya di sini. Tidak ada perubahan skema tabel `domain_providers`:
    | kredensial per driver disimpan pada kolom JSON terenkripsi.
    |
    | Kredensial provider (host/username/password dsb.) diisi lewat UI dan
    | tersimpan terenkripsi di database — JANGAN menulis kredensial asli di
    | config/repo ini.
    |
    */
    'domain_providers' => [
        'drivers' => [
            HestiaDomainProviderDriver::class,
            HostingerDomainProviderDriver::class,
            NameSiloDomainProviderDriver::class,
            ManualDomainProviderDriver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WPScan otomatis untuk situs WordPress (t_2e555b0b)
    |--------------------------------------------------------------------------
    |
    | Command `crm:wpscan` (jadwal harian 08:00, setelah hestia:sync 06:30)
    | menemukan akun Hestia aktif yang terpetakan ke klien, mem-probe domainnya
    | untuk memastikan WordPress, lalu menjalankan WPScan CLI dan mencatat
    | temuan sebagai insiden keamanan (source=wpscan, external_id idempoten
    | `wpscan:{site_id}:{fingerprint}`).
    |
    | Keamanan:
    |  - scan hanya flag read-only WPScan (tanpa enumerasi user/brute force);
    |  - api_token HANYA dari environment (WPSCAN_API_TOKEN) — tanpa token,
    |    database kerentanan WPScan tidak dipakai dan deteksi tetap berjalan
    |    terbatas; jangan pernah commit token ke repo;
    |  - binary di-run dari server CRM terhadap situs klien sendiri.
    |
    | binary    : path/eksekutabel wpscan (default `wpscan` di PATH).
    | api_token : token API wpscan.com (lihat https://wpscan.com/api) untuk
    |             database kerentanan terkini. Kosong = tanpa DB.
    | timeout   : batas detik per proses scan (default 300).
    | probe_timeout : batas detik probe deteksi WordPress (default 10).
    | verify_ssl: verifikasi SSL saat probe deteksi (default true; set false
    |             hanya bila banyak klien memakai sertifikat self-signed).
    */
    'wpscan' => [
        'binary' => env('WPSCAN_BINARY', 'wpscan'),
        'api_token' => env('WPSCAN_API_TOKEN', ''),
        'timeout' => env('WPSCAN_TIMEOUT', 300),
        'probe_timeout' => env('WPSCAN_PROBE_TIMEOUT', 10),
        'verify_ssl' => env('WPSCAN_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert kuota disk website (t_afef420a)
    |--------------------------------------------------------------------------
    |
    | Command `crm:disk-quota-alerts` (jadwal harian) memeriksa pemakaian disk
    | setiap akun Hestia terhadap kuotanya (`hestia_accounts.disk_used` /
    | `disk_quota`, MB) dan membuat insiden keamanan + notifikasi WA saat
    | ambang terlampaui:
    |
    |   - pemakaian >= warning_percent (default 80%)  -> severity medium
    |   - pemakaian >= critical_percent (default 90%) -> severity high
    |
    | Idempotensi: level alert terakhir tersimpan di
    | `hestia_accounts.disk_alert_level` (none|warning|critical), jadi insiden
    | & WA hanya dikirim saat level BERUBAH. Akun dengan kuota 0 (tanpa batas
    | menurut konvensi Hestia) atau kuota null (belum dilaporkan) dilewati.
    |
    | default_client_id: akun Hestia yang belum terpetakan ke klien (mapping
    | belum selesai) tetap dipantau; insidennya dicatat ke klien fallback ini
    | (konvensi sama dengan SECURITY_UPTIME_DEFAULT_CLIENT_ID). null = akun
    | tak terpetakan dilewati (tidak ada klien tujuan insiden).
    |
    */
    'disk_quota' => [
        'warning_percent' => env('CRM_DISK_QUOTA_WARNING_PERCENT', 80),
        'critical_percent' => env('CRM_DISK_QUOTA_CRITICAL_PERCENT', 90),
        'default_client_id' => env('CRM_DISK_QUOTA_DEFAULT_CLIENT_ID', null),
    ],

];
