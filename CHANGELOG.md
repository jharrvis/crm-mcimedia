# Changelog

Semua perubahan penting pada CRM MCI Media dicatat di berkas ini.
Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.1.0/).

## [Unreleased]

### Ditambahkan
- Modul Katalog Produk: CRUD produk di `/products` (pencarian nama/SKU, filter status, harga format `rupiah()`, aktif/nonaktif, hapus) + domain `app/Domains/Catalog` (model `Product` dengan scope `active()` dan urutan default `sort_order` lalu nama) + item nav "Produk" di sidebar.
- Picker produk pada form invoice (create/edit): tiap baris item punya dropdown produk aktif ("Nama — Rp harga") yang otomatis mengisi deskripsi & harga satuan; server tetap menghitung ulang total dari item yang dikirim (tidak ada `product_id` yang disimpan di `invoice_items`).
- Seeder `ProductSeeder`: 22 produk katalog awal (idempotent via `updateOrCreate` berdasarkan SKU). Dijalankan manual (`php artisan db:seed --class=ProductSeeder`) atau lewat `DatabaseSeeder` saat setup; tidak dijalankan otomatis di production.
- Halaman invoice publik via magic link (`public_token`): `GET /pay/{token}` (tanpa login, rate-limited `throttle:30,1`) menampilkan identitas usaha, nomor invoice, klien, item + total, status (lencana LUNAS bila lunas), jatuh tempo, instruksi transfer, dan unduh PDF (`GET /pay/{token}/pdf`, dokumen sama dengan route admin). Token salah/dicabut → 404; invoice dibatalkan → halaman info. Halaman tidak menampilkan data klien lain, email/WA klien, atau catatan internal.
- Konfirmasi transfer oleh klien di halaman publik (nama pengirim, nominal, tanggal, catatan opsional) → membuat baris `payments` berstatus `pending`/`bank_transfer` tanpa mengubah status invoice; validasi nominal > 0 dan ≤ total invoice, plus penolakan dobel-submit identik (nominal + tanggal sama dalam 5 menit).
- Manajemen tautan pembayaran di detail invoice admin: token 64 karakter (`Str::random(64)`) dibuat otomatis saat invoice ditandai terkirim, tombol "Salin tautan bayar"/"Buat tautan bayar", dan "Cabut tautan" (token dikosongkan).
- Verifikasi pembayaran pending di detail invoice admin: tombol "Konfirmasi" (`payments.status` → `confirmed` + `confirmed_by` + invoice `markPaid()`) dan "Tolak" (status → `rejected`, tidak mengubah status invoice, dicatat di activity log). Kolom `payments.status` adalah `varchar(16)` sehingga nilai `rejected` tidak butuh perubahan skema F2-1.
- Migrasi aditif `2026_10_02_100005_add_sender_name_to_payments_table` menambah kolom nullable `payments.sender_name` (nama pengirim dari form konfirmasi klien); migrasi F2-1 tidak diubah.

## [1.0.0] - 2026-10-01

Rilis pertama — live di https://crm.mcimedia.net (server sg2, Hestia,
PHP 8.3, project di `private/crm`, docroot `private/crm/public`).

### Ditambahkan
- Login admin (email + kata sandi, registrasi nonaktif) + halaman ubah kata sandi.
- Modul Klien: CRUD klien (nama usaha, kontak utama, email, WA, alamat, catatan, status aktif) + CRUD kontak tambahan per klien (nama, peran, email, WA).
- Modul Layanan: CRUD layanan per klien — jenis (domain/hosting/management server/maintenance/SEO/lainnya), nama, domain/server terkait, tanggal mulai & berakhir, harga (integer IDR), siklus (bulanan/tahunan/sekali), status aktif/nonaktif, pengingat on/off, filter klien/jenis/status + pencarian, sorotan baris overdue (merah) & ≤30 hari (kuning) dengan badge sisa hari.
- Modul Project: CRUD project per klien — judul, deskripsi, deadline, status (baru/berjalan/ditahan/selesai), nilai project (integer IDR).
- Modul Tugas: CRUD todo internal — kaitan opsional ke klien/project, prioritas (rendah/sedang/tinggi), due date, penanggung jawab, status; filter klien/project/prioritas/status/pencarian; tandai selesai/buka kembali.
- Dashboard admin: statistik klien aktif, layanan aktif, project berjalan, tugas terbuka; tabel layanan jatuh tempo ≤ 30 hari, layanan overdue, project berjalan, tugas mendesak (≤ 3 hari).
- Pengingat jatuh tempo: halaman /reminders + artisan command `crm:services-expiring` terjadwal harian 08:00 via scheduler (cron Hestia `schedule:run`).
- Audit internal: create/update/delete klien, kontak, layanan, project, tugas tercatat otomatis di `activity_logs` (siapa, apa, kapan, IP) + halaman Aktivitas.
- Struktur modular monolith: `app/Domains/{Clients,Services,Projects,Tasks,Dashboard,Core}`; uang disimpan integer IDR.
- Layout Blade + Tailwind gaya TailAdmin: sidebar, topbar, dark mode, badge pengingat di sidebar.
- CI GitHub Actions (composer install, build Vite, `php artisan test`), 37 feature test hijau.
- Seeder akun admin awal (`ADMIN_EMAIL`/`ADMIN_PASSWORD` dari environment).
