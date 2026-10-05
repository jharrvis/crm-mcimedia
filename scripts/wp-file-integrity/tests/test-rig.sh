#!/usr/bin/env bash
# =============================================================================
# test-rig.sh — verifikasi wp-file-integrity.sh terhadap wp-cli STUB (fake-wp)
# + mock CRM (mock-crm-server.py). Tidak butuh WordPress sungguhan.
# Pemakaian: bash test-rig.sh [path-ke-wp-file-integrity.sh]
# =============================================================================
set -uo pipefail

W="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="${1:-$W/../wp-file-integrity.sh}"
T="$(mktemp -d "${TMPDIR:-/tmp}/fim-rig.XXXXXX")"
KEEP="${KEEP_TEST_ROOT:-1}"

pass=0; fail=0
ok(){ pass=$((pass+1)); echo "  [PASS] $1"; }
bad(){ fail=$((fail+1)); echo "  [FAIL] $1"; }
section(){ echo; echo "== $* =="; }
assert_eq(){ [[ "$1" == "$2" ]] && ok "$3" || bad "$3 (got='$1' want='$2')"; }
assert_rc(){ [[ "$1" -eq "$2" ]] && ok "$3 (rc=$1)" || bad "$3 (rc=$1, want $2)"; }
assert_grep(){ grep -qF -- "$2" "$3" 2>/dev/null && ok "$1" || bad "$1"; }

cleanup(){
    [[ -n "${MOCK_PID:-}" ]] && kill "$MOCK_PID" 2>/dev/null
    wait 2>/dev/null
    if [[ "$KEEP" != "1" ]]; then rm -rf "$T"; else echo; echo "TEST_ROOT disimpan: $T"; fi
}
trap cleanup EXIT

# --- mock CRM ----------------------------------------------------------------
PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')"
MOCK_LOG="$T/mock-crm.log"
export MOCK_LOG
: > "$MOCK_LOG"
start_mock(){ # <mode>
    [[ -n "${MOCK_PID:-}" ]] && kill "$MOCK_PID" 2>/dev/null && sleep 0.3
    MOCK_LOG="$MOCK_LOG" MOCK_MODE="$1" python3 "$W/mock-crm-server.py" "$PORT" >/dev/null 2>&1 &
    MOCK_PID=$!
    for _ in $(seq 1 40); do
        curl -s -o /dev/null "http://127.0.0.1:$PORT/api/security/status" && return 0
        sleep 0.15
    done
    echo "mock CRM tidak siap"; exit 1
}
start_mock ok
post_count(){ wc -l < "$MOCK_LOG" | tr -d ' '; }
last_event(){ tail -n1 "$MOCK_LOG" | jq -c '.body.events[0]'; }

# --- lingkungan uji -----------------------------------------------------------
mkdir -p "$T/bin" "$T/wp1" "$T/state"
cp "$W/fake-wp" "$T/bin/wp"; chmod +x "$T/bin/wp"
echo "<?php // wp-config asli" > "$T/wp1/wp-config.php"

SITES="$T/sites.json"
jq -n --arg p "$T/wp1" '[{label:"site1", path:$p}]' > "$SITES"

run_fim() { # [...arg] — jalankan script dengan env uji
    env WP_FILE_INTEGRITY_CONFIG="$T/noconfig.env" \
        CRM_API_URL="http://127.0.0.1:$PORT" \
        SECURITY_API_TOKEN="rig-token" \
        CRM_CLIENT_ID="7" \
        SITES_FILE="$SITES" \
        STATE_DIR="$T/state" \
        SELF_LOG="$T/self.log" \
        WP_BIN="$T/bin/wp" \
        "$@"
}
FIM_ENV_BASE=(env WP_FILE_INTEGRITY_CONFIG="$T/noconfig.env"
    CRM_API_URL="http://127.0.0.1:$PORT"
    SECURITY_API_TOKEN="rig-token"
    CRM_CLIENT_ID="7"
    SITES_FILE="$SITES"
    STATE_DIR="$T/state"
    SELF_LOG="$T/self.log"
    WP_BIN="$T/bin/wp")

export FAKE_CORE_MODE=clean FAKE_PLUGIN_MODE=clean
SCRIPT_ABS="$(cd "$(dirname "$SCRIPT")" && pwd)/$(basename "$SCRIPT")"

# =============================================================================
section "1. Dasar: versi, argumen, validasi config"
out="$("$SCRIPT_ABS" --version 2>&1)"; rc=$?
assert_rc $rc 0 "--version exit 0"
pkg_ver="$(grep -oP 'readonly VERSION="\K[^"]+' "$SCRIPT_ABS")"
assert_grep "versi tercetak" "wp-file-integrity.sh $pkg_ver" <(echo "$out")

"$SCRIPT_ABS" --arg-aneh >/dev/null 2>&1; rc=$?
assert_rc $rc 64 "argumen tidak dikenal → 64"

"${FIM_ENV_BASE[@]}" SECURITY_API_TOKEN="" "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 3 "token kosong → 3"

echo '[{"label":"a","path":"/x"},{"label":"a","path":"/y"}]' > "$T/dup.json"
"${FIM_ENV_BASE[@]}" SITES_FILE="$T/dup.json" "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 5 "label duplikat → 5"

"${FIM_ENV_BASE[@]}" SITES_FILE="$T/tidak-ada.json" "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 5 "SITES_FILE hilang → 5"

echo 'bukan json' > "$T/badjson.json"
"${FIM_ENV_BASE[@]}" SITES_FILE="$T/badjson.json" "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 5 "SITES_FILE bukan JSON → 5"

# =============================================================================
section "2. --status health check"
out="$(run_fim "$SCRIPT_ABS" --status 2>&1)"; rc=$?
assert_rc $rc 0 "--status exit 0"
assert_grep "status OK" "OK:" <(echo "$out")

start_mock http500
out="$(run_fim "$SCRIPT_ABS" --status 2>&1)"; rc=$?
[[ $rc -ne 0 ]] && ok "--status saat CRM down → rc=$rc" || bad "--status saat CRM down harus gagal"
start_mock ok

# =============================================================================
section "3. Run pertama: situs bersih → baseline dibangun, tanpa POST"
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "run bersih exit 0"
assert_eq "$(post_count)" "$pc0" "tidak ada POST saat bersih"
[[ -f "$T/state/sites/site1/watch-baseline.json" ]] && ok "baseline watchlist dibangun" || bad "baseline tidak dibangun"
assert_eq "$(jq 'has("wp-config.php")' "$T/state/sites/site1/watch-baseline.json")" "true" "baseline memuat wp-config.php"

out="$(run_fim "$SCRIPT_ABS" --verify-site site1 2>&1)"; rc=$?
assert_rc $rc 0 "--verify-site exit 0"
assert_grep "verify-site bersih" "Bersih" <(echo "$out")

# =============================================================================
section "4. Core tamper → 1 event file-integrity (severity high)"
export FAKE_CORE_MODE=tamper
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "run tamper exit 0"
assert_eq "$(post_count)" "$(( pc0 + 1 ))" "1 POST terkirim"
ev="$(last_event)"
assert_eq "$(jq -r '.source' <<<"$ev")" "file-integrity" "source=file-integrity"
assert_eq "$(jq -r '.severity' <<<"$ev")" "high" "severity=high (core)"
assert_eq "$(jq -r '.client_id' <<<"$ev")" "7" "client_id dari config"
assert_grep "external_id deterministik" "fim-site1-" <(jq -r '.external_id' <<<"$ev")
assert_grep "judul menyebut situs" "site1" <(jq -r '.title' <<<"$ev")
assert_grep "deskripsi menyebut file" "wp-includes/version.php" <(jq -r '.description' <<<"$ev")
assert_grep "auth Bearer" "Bearer rig-token" <(tail -n1 "$MOCK_LOG" | jq -r '.auth')
assert_eq "$(jq '.body.events | length' <<<"$(tail -n1 "$MOCK_LOG")")" "1" "payload wrapper events[]"
[[ -f "$T/state/sites/site1/last-fingerprint.json" ]] && ok "fingerprint tersimpan" || bad "fingerprint tidak tersimpan"
assert_grep "path endpoint benar" "/api/security/events" <(tail -n1 "$MOCK_LOG" | jq -r '.path')

# =============================================================================
section "5. Idempotensi lokal: run ulang temuan sama → tidak POST ulang"
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "run ulang exit 0"
assert_eq "$(post_count)" "$pc0" "tidak ada POST ulang (dedup fingerprint)"

# =============================================================================
section "6. --dry-run: deteksi tanpa POST & tanpa tulis state"
export FAKE_PLUGIN_MODE=tamper
pc0="$(post_count)"
fp_before="$(cat "$T/state/sites/site1/last-fingerprint.json")"
run_fim "$SCRIPT_ABS" --dry-run >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "dry-run exit 0"
assert_eq "$(post_count)" "$pc0" "dry-run tidak POST"
assert_eq "$(cat "$T/state/sites/site1/last-fingerprint.json")" "$fp_before" "dry-run tidak mengubah state"

# =============================================================================
section "7. Watchlist tamper → severity critical (maks), fingerprint baru"
export FAKE_PLUGIN_MODE=clean
echo "<?php // wp-config DIRUBAH penyerang" > "$T/wp1/wp-config.php"
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "run watch-tamper exit 0"
assert_eq "$(post_count)" "$(( pc0 + 1 ))" "POST baru (fingerprint berubah)"
ev="$(last_event)"
assert_eq "$(jq -r '.severity' <<<"$ev")" "critical" "severity=critical (watchlist > core)"
assert_grep "deskripsi menyebut wp-config" "wp-config.php" <(jq -r '.description' <<<"$ev")

# =============================================================================
section "8. --rebuild-baseline: setujui ulang watchlist"
run_fim "$SCRIPT_ABS" --rebuild-baseline site1 >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "rebuild-baseline exit 0"
h1="$(jq -r '."wp-config.php"' "$T/state/sites/site1/watch-baseline.json")"
h2="$(sha256sum "$T/wp1/wp-config.php" | awk '{print $1}')"
assert_eq "$h1" "$h2" "baseline watchlist = hash terkini"
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1
ev="$(last_event)"
assert_eq "$(post_count)" "$(( pc0 + 1 ))" "temuan core masih → event baru (tanpa watch)"
assert_eq "$(jq -r '.severity' <<<"$ev")" "high" "severity turun ke high (watch hilang)"
if jq -r '.description' <<<"$ev" | grep -qF "wp-config.php"; then bad "wp-config.php masih disebut"; else ok "wp-config.php tak lagi disebut"; fi

# =============================================================================
section "9. Pulih → bersih; tamper lagi → alert lagi"
export FAKE_CORE_MODE=clean
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "run pulih exit 0"
assert_eq "$(post_count)" "$pc0" "tidak ada POST saat pulih"
assert_eq "$(jq -r '.fp' "$T/state/sites/site1/last-fingerprint.json")" "clean" "fingerprint direset ke clean"
export FAKE_CORE_MODE=missing
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1
assert_eq "$(post_count)" "$(( pc0 + 1 ))" "tamper baru → alert baru lagi"
assert_grep "file hilang disebut" "wp-login.php" <(jq -r '.description' <<<"$(last_event)")

# =============================================================================
section "10. Outbox retry: CRM 500 → outbox + exit 1; pulih → terkirim"
export FAKE_CORE_MODE=extra   # fingerprint baru
start_mock http500
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 1 "CRM 500 → exit 1"
[[ -s "$T/state/outbox-events.ndjson" ]] && ok "outbox berisi record gagal" || bad "outbox kosong"
start_mock ok
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "retry run exit 0"
[[ "$(post_count)" -gt "$pc0" ]] && ok "outbox + event terkirim ulang" || bad "tidak ada pengiriman ulang"
assert_eq "$(wc -l < "$T/state/outbox-events.ndjson" | tr -d ' ')" "0" "outbox kosong setelah sukses"

# =============================================================================
section "11. Respons 200 bukan JSON kontrak → outbox"
export FAKE_CORE_MODE=tamper   # fingerprint baru lagi
start_mock badjson
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 1 "200-nonJSON → exit 1"
[[ -s "$T/state/outbox-events.ndjson" ]] && ok "payload masuk outbox" || bad "outbox kosong"
start_mock ok
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "flush outbox setelah mock pulih"

# =============================================================================
section "12. HTTP 422 → tidak di-retry (tidak masuk outbox)"
export FAKE_CORE_MODE=missing  # fingerprint baru
start_mock reject422
run_fim "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "422 → exit 0 (payload ditolak permanen)"
assert_eq "$(wc -l < "$T/state/outbox-events.ndjson" | tr -d ' ')" "0" "422 tidak masuk outbox"
start_mock ok

# =============================================================================
section "13. plugin verify gagal tanpa JSON → temuan error (high)"
export FAKE_CORE_MODE=clean FAKE_PLUGIN_MODE=failjson
pc0="$(post_count)"
run_fim "$SCRIPT_ABS" >/dev/null 2>&1
assert_eq "$(post_count)" "$(( pc0 + 1 ))" "error wp-cli → event terkirim"
ev="$(last_event)"
assert_eq "$(jq -r '.severity' <<<"$ev")" "high" "severity error = high"
assert_grep "deskripsi memuat pesan gagal" "verify-checksums gagal" <(jq -r '.description' <<<"$ev")

# =============================================================================
section "14. Direktori situs hilang → temuan error"
jq -n --arg p "$T/tidak-ada" '[{label:"ghost", path:$p}]' > "$T/ghost.json"
"${FIM_ENV_BASE[@]}" SITES_FILE="$T/ghost.json" "$SCRIPT_ABS" >/dev/null 2>&1; rc=$?
assert_rc $rc 0 "run situs hilang exit 0"
ev="$(tail -n1 "$MOCK_LOG" | jq -c '.body.events[0]')"
assert_grep "error direktori" "tidak ditemukan" <(jq -r '.description' <<<"$ev")

# =============================================================================
section "15. Tanpa client_id → temuan tidak terkirim (warn)"
export FAKE_CORE_MODE=tamper
jq -n --arg p "$T/wp2" '[{label:"site2", path:$p}]' > "$T/noclient.json"
mkdir -p "$T/wp2"; echo x > "$T/wp2/wp-config.php"
pc0="$(post_count)"
out="$("${FIM_ENV_BASE[@]}" CRM_CLIENT_ID="" SITES_FILE="$T/noclient.json" "$SCRIPT_ABS" 2>&1)"; rc=$?
assert_rc $rc 0 "tanpa client_id exit 0"
assert_eq "$(post_count)" "$pc0" "tidak ada POST tanpa client_id"
assert_grep "warn tercatat di log" "client_id tidak ditemukan" "$T/self.log"

# =============================================================================
section "16. --list-sites"
out="$(run_fim "$SCRIPT_ABS" --list-sites 2>&1)"; rc=$?
assert_rc $rc 0 "--list-sites exit 0"
assert_grep "label tampil" "site1" <(echo "$out")
assert_grep "client_id tampil" "7" <(echo "$out")

# =============================================================================
section "17. --verify-site menampilkan temuan tanpa POST"
pc0="$(post_count)"
out="$(run_fim "$SCRIPT_ABS" --verify-site site1 2>&1)"; rc=$?
assert_rc $rc 0 "--verify-site exit 0"
assert_grep "temuan ditampilkan" "wp-includes/version.php" <(echo "$out")
assert_eq "$(post_count)" "$pc0" "verify-site tidak POST"

# =============================================================================
echo
echo "==============================================="
echo "HASIL: $pass PASS, $fail FAIL (total $((pass+fail)))"
[[ $fail -eq 0 ]] && echo "SEMUA LULUS" || echo "ADA KEGAGALAN"
exit $(( fail > 0 ? 1 : 0 ))