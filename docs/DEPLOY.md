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

## Sinkronisasi HestiaCP (F3-1)

Menarik akun hosting/domain dari HestiaCP menjadi Service di CRM — **read-only**
(tidak pernah membuat/mengubah/menghapus akun Hestia). Kredensial hanya di `.env`
server; jangan commit nilai asli ke repo.

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
   `php8.3 artisan hestia:sync`. Perintah ini terjadwal harian pukul 06:30 via
   cron `schedule:run` (sudah terpasang).

4. Buka `/hestia` di CRM. Akun yang tidak cocok otomatis muncul di daftar
   **belum dipetakan** — pilih klien lalu "Petakan" (membuat Service
   berjenis hosting dan menghubungkannya), atau "Abaikan". Ada juga tombol
   **"Sinkronkan sekarang"**.

Catatan:
- Sync bersifat idempotent: sync ulang tidak menduplikasi Service. Akun yang
  hilang dari Hestia (atau di-suspend) ditandai nonaktif — baris tidak dihapus.
- Hasil tiap sync terlihat di tabel riwayat `/hestia` dan tersimpan di
  `hestia_sync_logs` (tanpa kredensial).
- `HESTIA_ENABLED=false` (default) → tombol/perintah tidak menghubungi API.
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

Jadwal harian yang aktif di `bootstrap/app.php`:

| Waktu | Command | Fungsi |
|-------|---------|--------|
| 07:00 | `crm:generate-recurring-invoices` | Terbitkan invoice paket recurring (F4-11) per periode yang jatuh tempo |
| 07:30 | `crm:generate-renewal-invoices` | Draf invoice perpanjangan layanan yang segera berakhir |
| 08:00 | `crm:services-expiring` | Daftar layanan jatuh tempo ≤ 30 hari |
| 08:30 | `crm:send-overdue-reminders` | Pengingat invoice lewat jatuh tempo (H+1/H+7/H+14) |

Urutannya penting: `crm:generate-recurring-invoices` (07:00) dijalankan
**sebelum** `crm:generate-renewal-invoices` (07:30) karena perintah kedua
sengaja melewati layanan yang sudah diurus paket recurring aktif — tanpa itu
satu layanan bisa menerima dua invoice (satu dari paket, satu dari perpanjangan).

Invoice recurring juga bisa diterbitkan manual tanpa menunggu jadwal:

```
php artisan crm:generate-recurring-invoices
php artisan crm:generate-recurring-invoices --cycle=monthly    # bulanan saja
php artisan crm:generate-recurring-invoices --send             # paksa terkirim
```

Perintah ini idempoten per periode: menjalankannya berkali-kali pada hari yang
sama tidak menggandakan invoice, karena invoice untuk periode yang sudah terbit
dilewati dan `next_invoice_date` sudah maju satu siklus. Satu paket yang gagal
tidak menghentikan paket lain — paket itu dilaporkan per baris dan sisanya tetap
ditagih.

## Invoice recurring (F4-11)

Migrasi F4-11 dijalankan bersama `php artisan migrate --force` seperti biasa
(satu migrasi aditif `2026_10_07_100001_create_recurring_plans_table` — membuat
`recurring_plans` + `recurring_plan_items` dan menambah kolom
`recurring_plan_id`, `recurring_cycle`, `period_start`, `period_end` pada
`invoices`; **tidak mengubah data lama**).

Tidak ada variabel environment baru — semua konfigurasi per paket (siklus, item,
jatuh tempo, auto-send) disimpan di UI `/recurring-plans`.

Perlu diketahui saat rollback:

- Menghapus paket recurring **tidak** menghapus invoice yang sudah terbit.
  `invoices.recurring_plan_id` di-set NULL, invoice tetap utuh sebagai dokumen
  keuangan. Ini berbeda dari menghapus **klien**: `invoices.client_id` memakai
  `cascadeOnDelete` (perilaku lama sejak F2-1, bukan tambahan F4-11), jadi
  menghapus klien ikut menghapus invoice dan pembayaran terkait — sama seperti
  modul invoice lama. Jangan hapus klien bila invoice-nya masih dibutuhkan.

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

## Provider domain/hosting (F4-5)

Registry penyedia domain/hosting tidak butuh konfigurasi `.env` tambahan:
kredensial diisi lewat UI **Setelan → Provider Domain** (`/domain-providers`)
dan disimpan **terenkripsi** di tabel `domain_providers` (cast
`encrypted:array`). Karena itu `APP_KEY` server **wajib ada dan dicadangkan** —
tanpa `APP_KEY` yang sama, kredensial provider tidak bisa didekripsi.

Langkah deploy biasa: jalankan `php artisan migrate --force` (satu migrasi
aditif `2026_10_06_100001_create_domain_providers_table` — tidak mengubah tabel
lain). Setelah itu admin dapat menambah provider, memilih driver, mengisi
kredensial, dan menekan **Lihat domain** untuk menarik daftar domain dari
penyedia.

Driver bawaan:

- `HestiaCP (hosting)` — memakai ulang klien Hestia yang **read-only** (hanya
  perintah `v-list*`), memakai kredensial yang diisi di UI (host, port,
  user/password atau access/secret key, serta akun Hestia yang domainnya
  ditarik). Ini **terpisah** dari sinkronisasi F3-1 yang membaca `HESTIA_*`
  dari `.env`.
- `Hostinger` (F4-6) — autentikasi **Bearer API Token hPanel** (dibuat di
  hPanel → Akun → API Token, cukup akses baca domain) dan menarik daftar domain
  beserta tanggal kedaluwarsa (renewal) dari `GET /api/domains/v1/portfolio`.
  Token disimpan terenkripsi per provider; tidak ada `HOSTINGER_*` di `.env`.
- `Manual (tanpa API)` — daftar domain + tanggal kedaluwarsa dalam JSON, untuk
  registrar yang belum punya API.

Menambah penyedia baru cukup dengan membuat satu kelas yang
mengimplementasikan `App\Domains\Providers\Contracts\DomainProviderDriver`
(biasanya lewat `AbstractDomainProviderDriver`), lalu menambahkan kelasnya di
`config/crm.php` → `crm.domain_providers.drivers`. **Tidak ada perubahan skema
database**: bentuk kredensial ditentukan `credentialFields()` driver dan
disimpan apa adanya pada kolom JSON terenkripsi; form serta validasi ikut
menyesuaikan otomatis. Setelah menambah driver, jalankan
`php artisan config:clear` (atau `config:cache` bila memakai cache config).

## Grouping subdomain di bawah domain induk (F4-9)

Deploy biasa: `php artisan migrate --force` (satu migrasi aditif
`2026_10_07_100001_add_parent_id_to_services_table` — hanya menambah kolom
`services.parent_id`, tidak mengubah tabel/migrasi lain). Tidak ada variabel
`.env` baru dan tidak ada secret baru.

Cara pakai: di **Layanan → Tambah layanan**, isi jenis `Domain`, lalu pilih
**"Domain induk (subdomain)"**. Kolomnya hanya muncul untuk jenis Domain dan
hanya berisi domain milik klien yang dipilih. Kalau nama layanan
`www.mulkani.co.id` dan domain induk `mulkani.co.id` sudah ada, pilihan yang
paling masuk akal sudah terpilih otomatis — admin tetap bebas mengubahnya.

Aturan yang ditegakkan server (pesan error ramah, bukan error 500):

- hanya layanan jenis `domain` boleh punya domain induk;
- induk harus domain **milik klien yang sama** (mencegah domain klien A
  dijadikan induk subdomain klien B);
- layanan tidak bisa jadi induk dirinya sendiri, dan siklus `a → b → a`
  ditolak;
- mengosongkan kolom = subdomain dilepas menjadi layanan mandiri.

Catatan operasional:

- **Menghapus domain induk tidak menghapus subdomainnya.** `parent_id`-nya
  jadi `NULL` dan subdomain tetap tersimpan sebagai layanan mandiri (flash
  message menyebutkan jumlahnya). Ini disengaja agar satu klik "Hapus" tidak
  menghilangkan puluhan subdomain.
- Memindahkan domain induk ke klien lain **ikut memindahkan** subdomainnya.
- Subdomain tetap punya tanggal & harga sendiri. Bila tanggal berakhirnya
  diisi sama dengan domain induk, `crm:generate-renewal-invoices` saat ini
  membuat draf invoice terpisah untuk tiap subdomain (domain induk + N
  subdomain = N+1 draf). Kosongkan `end_date` subdomain bila tidak ingin
  ditagih terpisah, atau hapus draf yang tidak diperlukan.
- Urutan tampilan memakai `ORDER BY COALESCE(parent_id, id)` pada tabel
  `services` lewat scope `Service::groupedByParent()` (SQLite & MySQL sama-sama
  jalan; tidak butuh self-join maupun recursive CTE).

Rollback skema: `php artisan migrate:rollback --step=1` menghapus kolom
`parent_id` (data subdomain tidak hilang — subdomain kembali jadi layanan
mandiri).

## Rollback

- Kode: `git checkout <tag-sebelumnya>` lalu ulangi langkah Redeploy
  (tanpa migrate bila skema tidak berubah).
- Domain: `v-delete-web-domain mcimedia crm.mcimedia.net` (destruktif —
  hanya atas perintah eksplisit).
