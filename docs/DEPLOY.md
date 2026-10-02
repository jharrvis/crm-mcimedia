# Deploy CRM MCI Media ke sg2 (production)

Status: **live sejak 2026-10-01** di `https://crm.mcimedia.net`.

## Layout final di server (PENTING)

```
Hestia user      : mcimedia
Domain           : crm.mcimedia.net (SSL Let's Encrypt, SSL_FORCE=yes)
Backend          : PHP-8_3 (FPM socket per-domain)
Project root     : /home/mcimedia/web/crm.mcimedia.net/private/crm
Docroot (CUSTOM) : /home/mcimedia/web/crm.mcimedia.net/private/crm/public
Database         : mcimedia_crm (MySQL lokal) — kredensial hanya di .env server
Admin awal       : /home/mcimedia/crm-initial-admin.txt (mode 600)
Cron             : v-add-cron-job mcimedia — schedule:run setiap menit
```

**Kenapa project TIDAK di `public_html`:** Hestia memasang `open_basedir`
pada pool PHP-FPM yang hanya mengizinkan `public_html/public`, `private`,
`tmp`, dll. Laravel perlu membaca `vendor/` di luar docroot, jadi project
wajib tinggal di `private/` (sudah termasuk open_basedir) dan docroot
dipindah dengan:

```bash
v-change-web-domain-docroot mcimedia crm.mcimedia.net crm.mcimedia.net ../private/crm/public '' yes
```

Catatan perizinan lain: direktori `public_html` harus tetap
`mcimedia:www-data 751` (jangan `chown -R` mengganti grupnya —
Apache butuh grup www-data, kalau tidak muncul AH00529/403).

## Logo usaha (F2-8)

Logo tampil di kop PDF invoice dan header halaman bayar publik (`/pay/{token}`).

1. Taruh file logo di server: `public/images/business-logo.png` (PNG transparan
   disarankan; JPG juga didukung). Tinggi efektif di PDF maksimal ~56px.
2. Isi `.env` (di server, jangan di-commit): `CRM_BUSINESS_LOGO="images/business-logo.png"`
   — path relatif terhadap `public/`.
3. Muat ulang config: `php8.3 artisan config:cache` (dan `view:cache` bila ada).
4. Tidak ada langkah `storage:link` — file dibaca langsung dari `public/`.

Bila `CRM_BUSINESS_LOGO` kosong, atau filenya tidak ditemukan, kop tampil tanpa
logo (tidak error, tidak ada gambar rusak). Identitas teks lain tidak berubah.

## Rekening bank (F2-9)

Invoice menampilkan **semua** rekening yang dikonfigurasi — di PDF invoice,
halaman bayar publik (`/pay/{token}`), dan email invoice/pengingat. Nomor
rekening **tidak pernah** ditulis di kode; semuanya dari `.env` server.

1. Isi `.env` (di server, jangan di-commit) dengan JSON array satu baris,
   dibungkus tanda kutip tunggal (contoh memakai nomor dummy — ganti dengan
   nomor rekening asli):

   ```
   CRM_BANK_ACCOUNTS='[{"name":"BCA","account_number":"0000000000","account_holder":"Nama Pemilik"},{"name":"Bank Lain","account_number":"1111111111","account_holder":"Nama Pemilik"}]'
   ```

   Repo ini publik: **jangan pernah** commit nomor rekening nyata ke
   `.env.example`, docs, atau kode.

2. Jalankan `php8.3 artisan config:cache` (wajib — daftar rekening dibaca saat
   config di-cache).

3. Verifikasi: buka salah satu invoice di `/pay/{token}` — semua rekening
   tampil di blok "Instruksi transfer", dan PDF (`/pay/{token}/pdf`) memuat hal
   yang sama.

Catatan:
- Format lama satu rekening (`CRM_BANK_NAME`, `CRM_BANK_ACCOUNT_NUMBER`,
  `CRM_BANK_ACCOUNT_HOLDER`) masih didukung bila `CRM_BANK_ACCOUNTS` kosong.
- `CRM_BANK_ACCOUNTS=[]`/kosong = bagian instruksi pembayaran tampil tanpa
  rekening beserta pesan "hubungi admin" (tidak error).
- Setiap entri boleh hanya punya `name` atau `account_number`; entri yang
  kosong total otomatis dilewati.

## Sinkronisasi HestiaCP — multi-server (F3-1 + F4-12)

Menarik akun hosting/domain dari HestiaCP menjadi Service di CRM — **read-only**
(tidak pernah membuat/mengubah/menghapus akun Hestia). Kredensial tidak pernah
ditulis ke repo maupun ke log.

### Cara A — multi-server lewat UI (F4-12, disarankan)

Setiap panel HestiaCP punya host & kredensial sendiri (mis. **sg2**, **YIARI**,
**PA Salatiga**) dan dikelola dari UI, tidak lagi dari `.env`.

1. Migrasi + cache konfigurasi:

   ```bash
   php8.3 artisan migrate --force
   php8.3 artisan config:cache
   ```

2. Aktifkan sinkronisasi global di `.env` server (kill switch — WAJIB true):

   ```
   HESTIA_ENABLED=true
   ```

   Host/kredensial per-server **tidak** perlu diisi di `.env` lagi.

3. Buat entri server awal (opsional — bisa juga ditambah lewat UI):

   ```bash
   php8.3 artisan db:seed --class=HestiaServerSeeder
   ```

   Seeder membuat 3 baris **nonaktif tanpa kredensial** (`sg2`, `YIARI`,
   `PA Salatiga`) — tidak mengarang host/kredensial. Aman dijalankan berulang
   (`firstOrCreate`): server yang sudah dikonfigurasi admin tidak tersentuh.

4. Buka `/hestia/servers` (menu **Server Hestia**) → **Ubah** tiap server: isi
   *Host*, *Port*, lalu kredensial (**Access key** + **Secret key** disarankan,
   alternatif *User* + *Password*), centang **Aktif (ikut sync terjadwal)**.
   Gunakan tombol **Uji** untuk memastikan koneksi sebelum menyimpan.

   Kredensial disimpan **terenkripsi** di database dan tidak pernah ditampilkan
   kembali di form — mengosongkan field rahasia saat edit berarti *pertahankan
   nilai lama*.

5. Jalankan sinkronisasi:

   ```bash
   php8.3 artisan hestia:sync                    # semua server aktif
   php8.3 artisan hestia:sync --server=sg2       # satu server (kode atau nama)
   php8.3 artisan hestia:sync --list             # daftar server + status
   ```

   Terjadwal harian pukul 06:30 lewat cron `schedule:run` (sudah terpasang).
   Kegagalan satu server **tidak** menghentikan server lain; hasil tiap server
   dilaporkan terpisah.

6. Pantau di `/hestia` (filter per server, kolom Server, riwayat sync per
   sumber) atau `/hestia/servers/{server}`.

### Cara B — satu server lewat `.env` (F3-1, tetap berfungsi)

Dipakai otomatis bila **belum ada** server aktif di `hestia_servers`.

1. Siapkan autentikasi API Hestia. Cara disarankan: buat access key
   (`v-add-access-key <user> '*' crm json`) lalu isi `.env`:

   ```
   HESTIA_ENABLED=true
   HESTIA_HOST=<hostname-panel-sg2>      # tanpa skema, mis. panel.example.com
   HESTIA_PORT=8083
   HESTIA_SCHEME=https
   HESTIA_VERIFY_SSL=false               # true bila sertifikat panel valid
   HESTIA_ACCESS_KEY=<access-key>
   HESTIA_SECRET_KEY=<secret-key>
   ```

   Alternatif (legacy): kosongkan `HESTIA_ACCESS_KEY`/`HESTIA_SECRET_KEY` dan
   isi `HESTIA_USER` + `HESTIA_PASSWORD` (akun admin Hestia).

2. Pastikan IP server CRM diizinkan di *API allowed IPs* Hestia (v1.4+), dan
   API diaktifkan pada panel. Kredensial dikirim sebagai body POST sehingga
   tidak tampil di URL/log.

3. `php8.3 artisan config:cache` lalu jalankan sekali:
   `php8.3 artisan hestia:sync`.

4. Buka `/hestia` di CRM. Akun yang tidak cocok otomatis muncul di daftar
   **belum dipetakan** — pilih klien lalu "Petakan" (membuat Service
   berjenis hosting dan menghubungkannya), atau "Abaikan".

### Catatan penting

- **Prioritas sumber**: begitu ada ≥1 server aktif di `/hestia/servers`, proses
  sync memakai server UI dan **mengabaikan** host/kredensial `.env` — ini
  mencegah akun ganda dari sumber yang sama. Banner biru di `/hestia` menandai
  kondisi tersebut.
- **Data lama tetap aman**: migrasi F4-12 bersifat aditif (kolom nullable, tanpa
  rewrite). Akun hasil sinkronisasi F3-1 tetap punya `hestia_server_id = NULL`
  dan kunci `dom:<user>:<domain>` seperti sebelumnya; akun dari server baru
  memakai kunci `srv:<kode>:dom:<user>:<domain>` sehingga tidak bentrok.
- **Sync terisolasi per server**: akun pada server A tidak pernah dinonaktifkan
  oleh sync server B (keduanya sudah tercakup tes regresi).
- **Menghapus server** di UI tidak menghapus akun/layanan — akun tetap tersimpan
  dan hanya kehilangan sumbernya (`nullOnDelete`).
- Sync bersifat idempotent: sync ulang tidak menduplikasi Service. Akun yang
  hilang dari Hestia (atau di-suspend) ditandai nonaktif — baris tidak dihapus.
- Hasil tiap sync terlihat di tabel riwayat `/hestia` dan tersimpan di
  `hestia_sync_logs` (tanpa kredensial).
- `HESTIA_ENABLED=false` (default) → kill switch global: tombol/perintah tidak
  menghubungi API **server mana pun** (environment maupun server UI).

## Monitoring keamanan (F3-3)

Modul keamanan dipakai untuk mencatat insiden, jurnal tindakan, dan arsip
laporan PDF keamanan per klien, plus menerima temuan otomatis dari script
monitoring di server klien.

1. Isi `.env` server (jangan di-commit):

   ```
   SECURITY_API_ENABLED=true
   SECURITY_API_TOKEN=<token acak panjang, mis. hasil `openssl rand -hex 32`>
   SECURITY_DEDUP_WINDOW_MINUTES=1440
   SECURITY_REPORT_DISK=local
   SECURITY_REPORT_MAX_KB=10240
   ```

   `SECURITY_API_TOKEN` kosong → seluruh endpoint `/api/security/*` menolak
   semua request (503). Tidak ada jalur tanpa autentikasi.

2. `php8.3 artisan config:cache`.

3. Script monitoring di server klien memanggil:

   ```
   POST /api/security/events
   Authorization: Bearer <SECURITY_API_TOKEN>
   Content-Type: application/json

   {"events":[{"external_id":"sg2-yiari-20261001-01","client_id":7,
     "occurred_at":"2026-10-01T02:15:00+07:00","severity":"high",
     "source":"firewall","title":"Brute force SSH","description":"..."}]}
   ```

   - `external_id` opsional; bila diisi, kirim ulang batch yang sama tidak
     menggandakan insiden (idempotent). Tanpa `external_id`, temuan dengan
     klien+sumber+judul sama dalam `SECURITY_DEDUP_WINDOW_MINUTES` dianggap
     duplikat.
   - `severity`: critical|high|medium|low|info. `source`:
     firewall|wpscan|file-integrity|monitor|manual.
   - Health check: `GET /api/security/status` (juga butuh token).
   - **JANGAN** mengirim kredensial/rahasia server ke API ini — hanya metadata
     temuan yang dibaca; field lain diabaikan.

4. Tautan laporan publik per klien: buka `/security` (login), klik "Buat
   tautan" pada baris klien, salin URL `…/security/report/{token}` ke klien.
   Hanya laporan berstatus "Terkirim" yang tampil; "Cabut tautan" langsung
   menonaktifkan URL (404). Tanda tangani/whitelist domain CRM sebelum dibagikan.

5. Verifikasi: unggah PDF contoh di `/security/reports`, tandai "Terkirim",
   buka tautan publik klien — PDF harus bisa diunduh. Uji API dengan
   `curl -H "Authorization: Bearer $SECURITY_API_TOKEN" …/api/security/status`.

## Redeploy (update versi)

1. Lokal: WAJIB `npm run build` tepat sebelum packaging (jangan pakai
   `public/build` lama — build basi pernah terkirim 2026-10-01 dan UI
   tampil tanpa styling). Lalu `git archive HEAD | tar -x -C /tmp/crm-stage`, salin
   `public/build` ke staging, lalu di staging:
   `composer install --no-dev --optimize-autoloader`.
2. Upload: `tar -czf - -C /tmp/crm-stage . | sg2-ssh museops 'rm -rf /tmp/crm-deploy && mkdir /tmp/crm-deploy && tar -xzf - -C /tmp/crm-deploy'`
3. Server (root via museops): `cp -a /tmp/crm-deploy/. /home/mcimedia/web/crm.mcimedia.net/private/crm/`,
   JANGAN timpa `.env`. Lalu `chown -R mcimedia:mcimedia private/crm`
   + `find ... chmod 755/644` + `chmod -R ug+rwX storage bootstrap/cache`.
4. Sebagai mcimedia: `php8.3 artisan migrate --force`,
   `config:cache && route:cache && view:cache`.
5. Smoke test: `curl -s -o /dev/null -w '%{http_code}' https://crm.mcimedia.net/login` → 200.

## Deploy pertama (yang sudah dijalankan 2026-10-01)

Urutan persis seperti di atas, ditambah sekali jalan:
`v-add-database mcimedia crm crm <random> mysql`, tulis `.env`
(APP_ENV=production, DB_* mcimedia_crm, ADMIN_EMAIL/PASSWORD random —
password hanya disimpan di `/home/mcimedia/crm-initial-admin.txt`),
`key:generate`, `migrate --force`, `db:seed --force`, `storage:link`,
`v-add-web-domain-ssl-force`, cron `schedule:run`.

Script historis: `docs/deploy-sg2.sh` (deploy pertama) dan
`docs/fix-docroot-sg2.sh` (pindah ke private/crm). Untuk redeploy
ikuti langkah "Redeploy" di atas, bukan script lama.

## Scheduler

Sudah dipasang via Hestia cron (user mcimedia, setiap menit):

```
* * * * * cd /home/mcimedia/web/crm.mcimedia.net/private/crm && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Menjalankan `crm:services-expiring` harian pukul 08:00 (daftar layanan
jatuh tempo ≤ 30 hari; kanal WA/email menyusul fase 2).

## Laporan pencapaian project (F3-4)

Migrasi F3-4 dijalankan bersama `php artisan migrate --force` seperti biasa
(tiga migrasi aditif: `project_journals`, `achievement_reports`, dan
pelebaran kolom `tasks.status` — tidak mengubah data lama).

Generate laporan bisa dilakukan dari UI (`/projects/{project}/reports`)
atau lewat command:

```
php artisan crm:generate-achievement-reports --type=month
php artisan crm:generate-achievement-reports --type=week --date=2026-10-07
php artisan crm:generate-achievement-reports --type=month --project=12
```

Command hanya membuat laporan untuk project yang punya aktivitas pada
periode tersebut (task selesai atau entri jurnal) dan idempotent. Command
**belum dijadwalkan**; bila ingin otomatis, tambahkan baris `schedule->command(...)`
di `bootstrap/app.php` (mis. `->monthlyOn(1, '07:00')`) lalu pastikan cron
`schedule:run` di server tetap aktif.

## Rollback

- Kode: `git checkout <tag-sebelumnya>` lalu ulangi langkah Redeploy
  (tanpa migrate bila skema tidak berubah).
- Domain: `v-delete-web-domain mcimedia crm.mcimedia.net` (destruktif —
  hanya atas perintah eksplisit).
