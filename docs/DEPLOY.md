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

## Rollback

- Kode: `git checkout <tag-sebelumnya>` lalu ulangi langkah Redeploy
  (tanpa migrate bila skema tidak berubah).
- Domain: `v-delete-web-domain mcimedia crm.mcimedia.net` (destruktif —
  hanya atas perintah eksplisit).
