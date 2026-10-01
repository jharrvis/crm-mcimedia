# CRM MCI Media

Dashboard CRM internal MCI Media: kelola klien, layanan & masa berlaku,
project, dan todo dalam satu tempat. Dibangun dengan Laravel 12
(modular monolith), Blade + Tailwind.

## Kebutuhan

- PHP 8.3+ dengan ekstensi: mbstring, xml, sqlite3/pdo_mysql, curl, zip, gd, intl, bcmath
- Composer 2
- Node.js 20+ & npm (untuk build aset; cukup sekali saat deploy)
- Database: MySQL/MariaDB (production), SQLite (dev/testing)

## Instalasi lokal

```bash
composer install
cp .env.example .env
php artisan key:generate
# .env default memakai SQLite — buat file database-nya:
touch database/database.sqlite
php artisan migrate --seed   # membuat akun admin (dev: admin@mcimedia.net / password)
npm install && npm run build
php artisan serve
```

Ubah kredensial admin dev via environment `ADMIN_EMAIL` / `ADMIN_PASSWORD`
sebelum `db:seed` bila diperlukan.

## Struktur

```
app/Domains/
  Clients/    → Client, ClientContact (+ controllers, requests, views)
  Services/   → Service, ReminderController, ServicesExpiringCommand
  Projects/   → Project
  Tasks/      → Task
  Dashboard/  → DashboardController
  Core/       → ActivityLog, LogsActivity trait, ActivityLogController, Auth
app/Support/helpers.php → rupiah(), tgl_id(), event_id()
```

Uang (harga layanan, nilai project) disimpan sebagai **integer IDR**
(unsignedBigInteger), bukan decimal.

## Scheduler (production)

Satu cron setiap menit sebagai user pemilik aplikasi:

```
* * * * * cd /path/to/crm && php artisan schedule:run >> /dev/null 2>&1
```

Ini menjalankan `crm:services-expiring` setiap hari pukul 08:00 —
daftar layanan yang jatuh tempo ≤ 30 hari untuk admin.
(Kanal notifikasi WA/email menyusul di fase 2.)

## Testing

```bash
php artisan test
```

Testing memakai SQLite in-memory (lihat `phpunit.xml`).

## Deploy production (sg2)

Lihat `docs/DEPLOY.md`.
