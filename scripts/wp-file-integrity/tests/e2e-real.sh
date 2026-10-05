#!/usr/bin/env bash
# E2E nyata wp-file-integrity.sh: WordPress sungguhan (wp-cli real) + mock CRM.
# Pemakaian: WP_DIR=/path/wordpress WP_TARBALL=/path/wordpress-X.tar.gz \
#            [CRM_PORT=19911] [E2E_SCRATCH=dir] bash e2e-real.sh
# WP_DIR harus instalasi WP valid (wp-cli + MariaDB jalan). Tarball dipakai
# untuk memulihkan file core yang di-tamper. Mock CRM dijalankan caller
# (lihat tests/mock-crm-server.py) di port CRM_PORT.
set -uo pipefail
E="${E2E_SCRATCH:-$(dirname "$0")/.e2e-scratch}"
WP_DIR="${WP_DIR:?set WP_DIR=/path/wordpress (instalasi WP valid)}"
PKG="$(cd "$(dirname "$0")/.." && pwd)"
FIM="$PKG/wp-file-integrity.sh"
PORT="${CRM_PORT:-19911}"
WP_TARBALL="${WP_TARBALL:?set WP_TARBALL=/path/wordpress-X.Y.Z.tar.gz (arsip core wordpress.org)}"
LOG="$E/mock-$PORT.log"
STEP=0

fim() {
    env WP_FILE_INTEGRITY_CONFIG="$E/noconfig.env" \
        CRM_API_URL="http://127.0.0.1:$PORT" SECURITY_API_TOKEN="e2e-real-token" \
        CRM_CLIENT_ID=77 SITES_FILE="$E/sites.json" STATE_DIR="$E/state-$PORT" \
        SELF_LOG="$E/logs/fim-$PORT.log" WP_BIN=wp bash "$FIM" "$@"
}
posts() { [[ -f "$LOG" ]] || { echo 0; return; }; wc -l < "$LOG" 2>/dev/null | tr -d ' '; }
ev()    { tail -n1 "$LOG" 2>/dev/null | jq -c '.body.events[0]'; }
step()  { STEP=$((STEP+1)); echo; echo "=== [$STEP] $* ==="; }
restore_core_file() { # <path-relatif> — pulihkan 1 file core dari arsip resmi
    tar xzf "$WP_TARBALL" -C "$WP_DIR" --strip-components=1 "wordpress/$1"
}

mkdir -p "$E" "$E/logs"
jq -n --arg p "$WP_DIR" '[{label:"e2e-real", path:$p}]' > "$E/sites.json"

step "A. Baseline run (WordPress bersih)"
out="$(fim 2>&1)"; echo "rc=$? out=[$out] posts=$(posts)"
jq -c . "$E/state-$PORT/sites/e2e-real/watch-baseline.json"
[[ "$(posts)" == "0" ]] && echo "OK: run bersih tidak POST (baseline dibangun)" || { echo "FAIL: ada POST saat bersih"; exit 1; }

step "B. wp core verify-checksums baseline (bukti nyata)"
(cd "$WP_DIR" && wp core verify-checksums --allow-root 2>&1 | tail -1)

step "C. Tamper file CORE nyata (wp-includes/version.php)"
echo '<?php // attacker' >> "$WP_DIR/wp-includes/version.php"
fim >/dev/null 2>&1; echo "rc=$? posts=$(posts)"
ev | jq -c '{external_id,client_id,severity,source,title}'
ev | jq -r '.description' | sed -n '2p'
[[ "$(posts)" == "1" ]] || { echo "FAIL: core tamper tidak terkirim"; exit 1; }
[[ "$(ev | jq -r '.severity')" == "high" ]] || { echo "FAIL: severity core bukan high"; exit 1; }

step "D. Idempotensi: run ulang temuan identik → TIDAK POST"
before="$(posts)"; fim >/dev/null 2>&1; echo "rc=$? posts=$(posts) (sebelumnya $before)"
[[ "$(posts)" == "$before" ]] || { echo "FAIL: POST ulang untuk temuan sama"; exit 1; }

step "E. Tamper watchlist wp-config.php (PHP valid: sisipkan define sebelum marker)"
sed -i "/stop editing/i define( 'FIM_TEST_MARK', 1 );" "$WP_DIR/wp-config.php"
grep -n "FIM_TEST_MARK" "$WP_DIR/wp-config.php"
php -l "$WP_DIR/wp-config.php"
fim >/dev/null 2>&1; echo "rc=$? posts=$(posts)"
ev | jq -c '{severity,external_id}'
ev | jq -r '.description' | grep -F 'wp-config.php' | head -1
[[ "$(ev | jq -r '.severity')" == "critical" ]] || { echo "FAIL: severity watchlist bukan critical"; exit 1; }

step "F. --rebuild-baseline menyetujui perubahan watchlist"
fim --rebuild-baseline e2e-real; echo "rc=$?"
cur="$(sha256sum "$WP_DIR/wp-config.php" | awk '{print $1}')"
stored="$(jq -r '."wp-config.php"' "$E/state-$PORT/sites/e2e-real/watch-baseline.json")"
[[ "$cur" == "$stored" ]] && echo "OK: baseline = hash terkini" || { echo "FAIL: baseline tidak sinkron ($cur vs $stored)"; exit 1; }

step "G. Temuan tersisa (core) → event BARU severity high"
before="$(posts)"; fim >/dev/null 2>&1; echo "rc=$? posts=$(posts) (sebelumnya $before)"
ev | jq -c '{severity,external_id}'
[[ "$(ev | jq -r '.severity')" == "high" ]] || { echo "FAIL: severity seharusnya high"; exit 1; }
ev | jq -r '.description' | grep -cF 'wp-config.php' | sed 's/^/sebutan wp-config di deskripsi: /'

step "H. File EXTRA di core (backdoor) terdeteksi"
printf '<?php\n// backdoor\n' > "$WP_DIR/wp-content/../wp-includes/class-wp-backdoor.php"
before="$(posts)"; fim >/dev/null 2>&1; echo "rc=$? posts=$(posts) (sebelumnya $before)"
ev | jq -r '.description' | grep -F 'class-wp-backdoor.php' | head -1

step "I. Heil core + hapus backdoor → bersih, tidak POST"
restore_core_file wp-includes/version.php
rm -f "$WP_DIR/wp-includes/class-wp-backdoor.php"
(cd "$WP_DIR" && wp core verify-checksums --allow-root 2>&1 | tail -1)
before="$(posts)"; fim >/dev/null 2>&1; echo "rc=$? posts=$(posts) (sebelumnya $before)"
echo "last-fingerprint: $(jq -r '.fp' "$E/state-$PORT/sites/e2e-real/last-fingerprint.json")"
[[ "$(posts)" == "$before" ]] || { echo "FAIL: ada POST saat pulih"; exit 1; }

step "J. --verify-site / --status / --list-sites"
fim --verify-site e2e-real; echo "rc=$?"
fim --status | head -2; echo "rc=$?"
fim --list-sites; echo "rc=$?"

step "K. --dry-run: deteksi tanpa POST & tanpa tulis state"
printf '<?php\n' >> "$WP_DIR/wp-includes/version.php"
before_posts="$(posts)"; before_fp="$(cat "$E/state-$PORT/sites/e2e-real/last-fingerprint.json")"
fim --dry-run | head -3; echo "rc=$?"
[[ "$(posts)" == "$before_posts" ]] && echo "OK: dry-run tidak POST" || { echo "FAIL: dry-run POST"; exit 1; }
[[ "$(cat "$E/state-$PORT/sites/e2e-real/last-fingerprint.json")" == "$before_fp" ]] \
  && echo "OK: dry-run tidak ubah state" || { echo "FAIL: dry-run ubah state"; exit 1; }

step "L. Outbox retry: CRM 500 → exit 1, outbox terisi; pulih → terkirim"
printf '<?php // tamper utk outbox\n' >> "$WP_DIR/wp-includes/version.php"
# Mock utama (port $PORT) dibiarkan hidup oleh caller; pakai portside kedua
# untuk mensimulasikan CRM 500 tanpa menyentuh proses mock utama.
DOWN_PORT=$(( PORT + 1 ))
LOG500="$E/mock-$DOWN_PORT.log"
: > "$LOG500"
MOCK_LOG="$LOG500" MOCK_MODE=http500 \
    setsid python3 "$PKG/tests/mock-crm-server.py" "$DOWN_PORT" >/dev/null 2>&1 < /dev/null &
DOWN_PID=$!
for _ in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$DOWN_PORT/api/security/status" && break; sleep 0.15; done

env WP_FILE_INTEGRITY_CONFIG="$E/noconfig.env" \
    CRM_API_URL="http://127.0.0.1:$DOWN_PORT" SECURITY_API_TOKEN="e2e-real-token" \
    CRM_CLIENT_ID=77 SITES_FILE="$E/sites.json" STATE_DIR="$E/state-$PORT" \
    SELF_LOG="$E/logs/fim-$PORT.log" WP_BIN=wp bash "$FIM" >/dev/null 2>&1
rc=$?; echo "rc=$rc (harus 1) outbox=$(wc -l < "$E/state-$PORT/outbox-events.ndjson" 2>/dev/null | tr -d ' ') baris"
[[ "$rc" == "1" ]] || { echo "FAIL: CRM 500 harus exit 1"; kill "$DOWN_PID" 2>/dev/null; exit 1; }

kill "$DOWN_PID" 2>/dev/null; sleep 0.3
before="$(posts)"; fim >/dev/null 2>&1; rc=$?
echo "rc=$rc (harus 0) posts=$(posts) (sebelumnya $before)"
echo "outbox setelah sukses: $(wc -l < "$E/state-$PORT/outbox-events.ndjson" | tr -d ' ') baris"
[[ "$rc" == "0" && "$(posts)" -gt "$before" ]] || { echo "FAIL: outbox tidak ter-flush"; exit 1; }
[[ "$(wc -l < "$E/state-$PORT/outbox-events.ndjson" | tr -d ' ')" == "0" ]] \
  || { echo "FAIL: outbox tidak kosong setelah sukses"; exit 1; }

echo
echo "E2E SELESAI. Total POST diterima mock CRM: $(posts)"
exit 0