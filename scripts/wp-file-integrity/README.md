# wp-file-integrity

File Integrity Monitoring (FIM) untuk situs WordPress di server hosting,
dikirim sebagai insiden ke modul Security CRM (F3-3, `source=file-integrity`).

Deteksi memakai **wp-cli** (bukan agent daemon, tanpa tulis ke file situs):

1. **Core** — `wp core verify-checksums`: file core berubah / hilang / ekstra
   vs checksum resmi wordpress.org.
2. **Plugin** — `wp plugin verify-checksums --all --format=json` per plugin
   yang terdaftar di wordpress.org.
   (wp-cli tidak punya `wp theme verify-checksums`; tema/custom file ditangkap
   lewat watchlist.)
3. **Watchlist sha256** — file sensitif vs baseline lokal:
   default `wp-config.php`, `wp-content/mu-plugins/*.php`, `wp-content/db.php`,
   `wp-content/object-cache.php`. Baseline tidak auto-update; operator
   menyetujui perubahan sah dengan `--rebuild-baseline <label>`.

Pelaporan: **maksimal 1 event per situs per run** (agregat), severity =
maksimum temuan (watchlist→critical, core→high, plugin→medium, error→high,
semua configurable via env).

## Isi paket

| File | Fungsi |
|---|---|
| `wp-file-integrity.sh` | script utama (bash, read-only thd WordPress) |
| `install-sg2.sh` | installer server (dry-run default, `--apply`, `--rollback`) |
| `wp-file-integrity.env.example` | template config `/etc/wp-file-integrity.env` |
| `sites.example.json` | template daftar situs |
| `wp-file-integrity.cron` | cron `/etc/cron.d/` tiap 15 menit |
| `wp-file-integrity.logrotate` | logrotate `/etc/logrotate.d/` |
| `tests/test-rig.sh` | rig 71 assertion (stub wp-cli `fake-wp` + mock CRM) |
| `tests/e2e-real.sh` | e2e nyata: WordPress + wp-cli + MariaDB sungguhan |
| `tests/mock-crm-server.py` | mock CRM (status/events, mode ok/500/422/badjson) |
| `evidence/` | log rig + log e2e + payload mock CRM |

## Mode

```
wp-file-integrity.sh                 # deteksi + kirim
wp-file-integrity.sh --dry-run       # deteksi + log, tanpa POST/tulis state
wp-file-integrity.sh --status        # health check CRM
wp-file-integrity.sh --list-sites    # situs + client_id efektif
wp-file-integrity.sh --verify-site L # deteksi 1 situs, tampil, tanpa POST
wp-file-integrity.sh --rebuild-baseline L   # setujui ulang watchlist situs L
```

Exit: `0` sukses · `1` pengiriman gagal (outbox, retry run berikutnya) ·
`2` CRM_API_URL kosong · `3` token kosong · `4` client_id kosong ·
`5` SITES_FILE invalid · `6` dependensi kurang · `64` argumen salah ·
`78` tidak bisa tulis state/log.

## Kontrak CRM

```
POST {CRM_API_URL}/api/security/events   {"events":[{...}]}
Auth: Authorization: Bearer SECURITY_API_TOKEN
```

Field event (kontrak SecurityEventIngest): `external_id`, `client_id`,
`occurred_at`, `severity`, `source`, `title`, `description`.

Idempotensi dua lapis:

- `external_id` deterministik `fim-<label>-<sha256(fingerprint temuan)>` →
  CRM tolak duplikat (unique client+external_id).
- state lokal `last-fingerprint.json` → temuan identik tidak di-POST ulang;
  temuan berubah → event baru; temuan pulih → run bersih (`fp: "clean"`).

Pengiriman gagal (timeout/5xx/401/non-JSON) masuk outbox
`$STATE_DIR/outbox-events.ndjson` dan dikirim lagi pada run berikutnya.
HTTP 422 (payload ditolak permanen) tidak di-retry.

## Instalasi (server, langkah operator)

```
sudo bash install-sg2.sh            # dry-run: lihat rencana
sudo bash install-sg2.sh --apply    # pasang script + cron + logrotate
$EDITOR /etc/wp-file-integrity.env  # CRM_API_URL, SECURITY_API_TOKEN, CRM_CLIENT_ID
$EDITOR /etc/wp-file-integrity/sites.json
wp-file-integrity.sh --status
wp-file-integrity.sh --list-sites
wp-file-integrity.sh --dry-run
```

Prasyarat: wp-cli, jq, curl, coreutils. Token CRM hanya dari env/config file
(root:root 600), tidak pernah hardcoded.

## Verifikasi

```
bash tests/test-rig.sh     # 71 assertion, stub wp-cli + mock CRM
bash tests/e2e-real.sh     # butuh WP + MariaDB sungguhan + mock CRM di :19911
```

Hasil terakhir: rig **71/71 PASS**; e2e nyata **12/12 langkah PASS**
(baseline tanpa false positive, tamper core→high, tamper wp-config→critical,
dedup, rebuild-baseline, file ekstra/backdoor, heal→clean, dry-run,
outbox retry 500→flush). Log: `evidence/`.

## Keamanan

- Read-only thd WordPress: hanya baca file + POST ke CRM. Tidak pernah
  mengubah file/layanan situs.
- Tidak ada secret di repo: token hanya dari environment atau file config 600.
- Log mandiri `SELF_LOG` tidak memuat token (header auth tidak pernah dicetak).
- flock cegah dua cron run menimpa state/outbox.
