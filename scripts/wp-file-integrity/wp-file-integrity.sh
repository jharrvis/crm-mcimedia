#!/usr/bin/env bash
# =============================================================================
# wp-file-integrity.sh — File Integrity Monitoring (FIM) WordPress via wp-cli
#                        untuk CRM Security Monitoring (sisi server).
#
# Versi      : 1.0.0
# Target     : server hosting (HestiaCP, mis. sg2). Via cron tiap 15 menit.
# Sifat      : READ-ONLY terhadap WordPress — hanya MEMBACA file situs (lewat
#              `wp core/plugin verify-checksums` + hash sha256 watchlist),
#              menulis state dir + self log + outbox retry, dan mengirim POST
#              JSON ke CRM. Tidak pernah mengubah file WordPress/layanan, dan
#              tidak menulis credential ke mana pun.
#
# Deteksi (per situs yang terdaftar di SITES_FILE):
#   1. Core    : `wp core verify-checksums`  → file berubah / hilang / ekstra
#                vs checksum resmi wordpress.org (cache checksum lokal wp-cli).
#   2. Plugin  : `wp plugin verify-checksums --all --format=json` → per plugin
#                yang ada di direktori wordpress.org. (wp-cli tidak punya
#                `wp theme verify-checksums`; tema ditangkap via watchlist
#                bila perlu — tambah glob wp-content/themes/*.)
#   3. Watchlist: sha256 file sensitif (default: wp-config.php, mu-plugins,
#                db.php, object-cache.php) dibandingkan baseline lokal.
#                Baseline TIDAK auto-update saat ada perubahan — operator
#                menyetujui ulang dengan `--rebuild-baseline <label>` setelah
#                perubahan sah (update plugin, edit config, dsb).
#
# Pelaporan: maksimal 1 event per situs per run (agregat), ke endpoint ingest
# insiden CRM (modul F3-3, kontrak diverifikasi dari kode SecurityEventIngest):
#   POST {CRM_API_URL}/api/security/events   {"events":[{...}]}
#   GET  {CRM_API_URL}/api/security/status   (health check, --status)
#   Auth : header "Authorization: ***" (alternatif X-Api-Token).
# Field event (hanya ini yang dibaca CRM; field lain diabaikan):
#   external_id, client_id, occurred_at, severity, source, title, description
# Kontrak idempotensi:
#   - external_id deterministik: "fim-<label>-<sha256-fingerprint>" — set
#     temuan sama = id sama → CRM menolak duplikat (unique client+external_id).
#   - dedup lokal juga: fingerprint yang sama dengan last-sent tidak di-POST
#     ulang; fingerprint baru (temuan berubah/bertambah) → event baru.
#   - temuan hilang (file dipulihkan) → run bersih, tanpa event.
# Severity agregat = maksimum temuan (critical>high>medium>low>info):
#   watchlist → $SEV_WATCH (default critical), core → $SEV_CORE (high),
#   plugin → $SEV_PLUGIN (medium), error wp-cli/situs → high.
#
# KONFIGURASI (environment; file opsional /etc/wp-file-integrity.env):
#   CRM_API_URL           base URL CRM, mis. https://crm.mcimedia.net  (wajib)
#   SECURITY_API_TOKEN    token API (wajib; JANGAN hardcode di script)
#   CRM_CLIENT_ID         client_id default di CRM (wajib jika tanpa map/per-site)
#   CRM_CLIENT_MAP_PATH   opsional JSON {"label": client_id, "@server": id}
#   SITES_FILE            JSON array definisi situs (wajib), contoh:
#                         [
#                           {"label":"example.com",
#                            "path":"/home/example/web/example.com/public_html",
#                            "user":"example",
#                            "client_id":3,
#                            "watch":["wp-config.php","wp-content/mu-plugins/*.php"]}
#                         ]
#                         - label   : nama unik situs (dipakai di judul/dedup)
#                         - path    : direktori instalasi WordPress (wp-load)
#                         - user    : (opsional) owner file untuk --user wp-cli
#                         - client_id: (opsional) override peta/default
#                         - watch   : (opsional) glob relatif watchlist per situs
#   WP_FILE_INTEGRITY_CONFIG  path file config (default /etc/wp-file-integrity.env)
#   WP_BIN                path binary wp-cli (default: wp di PATH)
#   STATE_DIR             default /var/lib/wp-file-integrity
#   SELF_LOG              default /var/log/wp-file-integrity.log
#   SEV_WATCH             severity watchlist      (default critical)
#   SEV_CORE              severity core checksum  (default high)
#   SEV_PLUGIN            severity plugin checksum(default medium)
#   MAX_REPORT_FILES      maks path dicantumkan di deskripsi (default 30)
#   MAX_EVENTS            maks event per POST (default 100)
#   OUTBOX_MAX_LINES      cap retry queue (default 5000)
#   HTTP_TIMEOUT          default 30
#   WATCH_GLOBS           glob watchlist default bila situs tidak override
#                         (default: wp-config.php wp-content/mu-plugins/*.php
#                          wp-content/db.php wp-content/object-cache.php)
#
# MODE:
#   (tanpa arg)         deteksi + kirim
#   --dry-run           deteksi + log, tanpa POST & tanpa tulis state
#   --status            GET health check CRM
#   --list-sites        tampilkan situs terkonfigurasi + client_id efektif
#   --verify-site L     deteksi satu situs saja, tampilkan temuan, tanpa POST
#   --rebuild-baseline L setujui ulang baseline watchlist situs L (tulis state)
#   --version / --help
# Exit : 0 sukses | 1 sebagian pengiriman gagal (outbox, retry run berikutnya)
#        | 2 CRM_API_URL kosong | 3 token kosong | 4 client_id kosong
#        | 5 SITES_FILE tidak ada/tidak valid | 6 dependensi kurang
#        | 64 argumen salah | 78 tidak bisa tulis state/log.
# =============================================================================
set -uo pipefail
export LC_ALL=C

readonly VERSION="1.0.1"
readonly SCRIPT_NAME="$(basename "$0")"

MODE="run"            # run | dry-run | status | list-sites | verify-site | rebuild-baseline
MODE_ARG=""
while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)          MODE="dry-run" ;;
        --status)           MODE="status" ;;
        --list-sites)       MODE="list-sites" ;;
        --verify-site)      MODE="verify-site"; MODE_ARG="${2:-}"; [ -n "$MODE_ARG" ] && shift ;;
        --rebuild-baseline) MODE="rebuild-baseline"; MODE_ARG="${2:-}"; [ -n "$MODE_ARG" ] && shift ;;
        --version)          echo "$SCRIPT_NAME $VERSION"; exit 0 ;;
        --help|-h)          awk 'NR>1 && /^#/{sub(/^# ?/,""); print; next} NR>1 && !/^#/{exit}' "$0"; exit 0 ;;
        *) echo "ARG tidak dikenal: $1 (lihat --help)" >&2; exit 64 ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
# File konfigurasi opsional (root:root 600). Nilai environment menang.
# ---------------------------------------------------------------------------
CONFIG_FILE="${WP_FILE_INTEGRITY_CONFIG:-/etc/wp-file-integrity.env}"
if [[ -f "$CONFIG_FILE" && -r "$CONFIG_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    if ! . "$CONFIG_FILE"; then
        echo "ERROR: file config tidak valid: $CONFIG_FILE" >&2
        exit 5
    fi
    set +a
fi

STATE_DIR="${STATE_DIR:-/var/lib/wp-file-integrity}"
SELF_LOG="${SELF_LOG:-/var/log/wp-file-integrity.log}"
WP_BIN="${WP_BIN:-wp}"
SITES_FILE="${SITES_FILE:-/etc/wp-file-integrity/sites.json}"
SEV_WATCH="${SEV_WATCH:-critical}"
SEV_CORE="${SEV_CORE:-high}"
SEV_PLUGIN="${SEV_PLUGIN:-medium}"
MAX_REPORT_FILES="${MAX_REPORT_FILES:-30}"
MAX_EVENTS="${MAX_EVENTS:-100}"
OUTBOX_MAX_LINES="${OUTBOX_MAX_LINES:-5000}"
HTTP_TIMEOUT="${HTTP_TIMEOUT:-30}"
WATCH_GLOBS="${WATCH_GLOBS:-wp-config.php wp-content/mu-plugins/*.php wp-content/db.php wp-content/object-cache.php}"

# Self log: /var/log biasanya hanya root; fallback ke state dir saat diuji
# sebagai user biasa. Keduanya gagal → berhenti dengan jelas.
if ! mkdir -p "$STATE_DIR" 2>/dev/null || ! touch "$SELF_LOG" 2>/dev/null; then
    FALLBACK_LOG="$STATE_DIR/wp-file-integrity.log"
    if mkdir -p "$STATE_DIR" 2>/dev/null && touch "$FALLBACK_LOG" 2>/dev/null; then
        SELF_LOG="$FALLBACK_LOG"
    else
        echo "ERROR: tidak bisa menulis SELF_LOG ($SELF_LOG) maupun STATE_DIR ($STATE_DIR). Periksa izin/direktori." >&2
        exit 78
    fi
fi

log()  { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$SELF_LOG"; }

# ---------------------------------------------------------------------------
# Dependensi
# ---------------------------------------------------------------------------
check_deps() {
    local missing=0
    for bin in jq curl sha256sum sort find; do
        if ! command -v "$bin" >/dev/null 2>&1; then
            log "ERROR: dependensi kurang: $bin"; missing=1
        fi
    done
    if ! command -v "$WP_BIN" >/dev/null 2>&1; then
        log "ERROR: wp-cli tidak ditemukan (WP_BIN=$WP_BIN)"; missing=1
    fi
    [[ "$missing" == "0" ]] || exit 6
}

# ---------------------------------------------------------------------------
# --status mode (tidak butuh SITES_FILE)
# ---------------------------------------------------------------------------
if [[ "$MODE" == "status" ]]; then
    check_deps
    if [[ -z "${CRM_API_URL:-}" ]]; then log "ERROR: CRM_API_URL kosong. Batal."; exit 2; fi
    if [[ -z "${SECURITY_API_TOKEN:-}" ]]; then log "ERROR: SECURITY_API_TOKEN kosong. Batal."; exit 3; fi
    if ! body="$(curl -sS --max-time "$HTTP_TIMEOUT" \
        -H "Authorization: Bearer $SECURITY_API_TOKEN" \
        -H "Accept: application/json" \
        -w '\n%{http_code}' \
        "$CRM_API_URL/api/security/status")"; then
        log "STATUS: tidak bisa menghubungi $CRM_API_URL"
        echo "GAGAL: CRM tidak terjangkau"
        exit 1
    fi
    code="$(printf '%s' "$body" | tail -n1)"
    body="$(printf '%s' "$body" | sed '$d')"
    if [[ "$code" == "200" ]] && printf '%s' "$body" | jq -e 'type == "object" and .status == "ok"' >/dev/null 2>&1; then
        log "STATUS OK: $body"
        echo "OK: $body"
        exit 0
    fi
    log "STATUS GAGAL: HTTP $code — $body"
    echo "GAGAL: HTTP $code — $body"
    exit 1
fi

# ---------------------------------------------------------------------------
# Validasi konfigurasi inti (run / dry-run / verify-site / rebuild-baseline)
# ---------------------------------------------------------------------------
check_deps

if [[ "$MODE" == "run" || "$MODE" == "dry-run" ]]; then
    if [[ -z "${CRM_API_URL:-}" ]]; then
        log "ERROR: CRM_API_URL kosong — set di $CONFIG_FILE atau environment. Batal."
        exit 2
    fi
    if [[ -z "${SECURITY_API_TOKEN:-}" ]]; then
        log "ERROR: SECURITY_API_TOKEN kosong — token hanya dari environment/file config, tidak pernah hardcoded. Batal."
        exit 3
    fi
fi

if [[ ! -f "$SITES_FILE" ]]; then
    log "ERROR: SITES_FILE tidak ditemukan: $SITES_FILE"
    exit 5
fi
if ! jq -e 'type == "array"' "$SITES_FILE" >/dev/null 2>&1; then
    log "ERROR: SITES_FILE bukan JSON array: $SITES_FILE"
    exit 5
fi
SITE_COUNT="$(jq 'length' "$SITES_FILE")"
if [[ "$SITE_COUNT" -eq 0 ]]; then
    log "ERROR: SITES_FILE kosong (tidak ada situs)."
    exit 5
fi
# Validasi bentuk tiap entri situs + label unik.
if ! jq -e '
    all(.[]; (type == "object")
        and (.label  | type == "string" and length > 0)
        and (.path   | type == "string" and length > 0))
    and (([.[].label] | length) == ([.[].label] | unique | length))
' "$SITES_FILE" >/dev/null 2>&1; then
    log "ERROR: SITES_FILE tidak valid — tiap entri butuh label+path string, dan label harus unik."
    exit 5
fi

WP_EXTRA=""
if [[ "$(id -u)" == "0" ]]; then WP_EXTRA="--allow-root"; fi

# Lock: cegah dua cron run saling menimpa state/outbox (flock bila tersedia).
if command -v flock >/dev/null 2>&1; then
    exec 9>"$STATE_DIR/.lock"
    if ! flock -n 9; then
        log "run lain masih berjalan — keluar (skip)"
        exit 0
    fi
fi

NOW="$(date +%s)"
iso() { date '+%Y-%m-%dT%H:%M:%S%z'; }

state_write_atomic() { # <file> <content> — tidak menulis saat --dry-run/verify
    if [[ "$MODE" == "dry-run" || "$MODE" == "verify-site" ]]; then return 0; fi
    local tmp="$1.tmp.$$"
    printf '%s' "$2" > "$tmp" && mv -f "$tmp" "$1"
}

sev_rank() { # critical>high>medium>low>info
    case "$1" in
        critical) echo 5 ;; high) echo 4 ;; medium) echo 3 ;;
        low) echo 2 ;; info) echo 1 ;; *) echo 0 ;;
    esac
}

client_id_for() { # <label> [site_client_id] -> id numerik atau ""
    local label="$1" site_cid="${2:-}" cid=""
    if [[ -n "$site_cid" && "$site_cid" =~ ^[0-9]+$ ]]; then echo "$site_cid"; return; fi
    if [[ -n "${CRM_CLIENT_MAP_PATH:-}" && -f "$CRM_CLIENT_MAP_PATH" ]]; then
        cid="$(jq -r --arg d "$label" 'if has($d) then (.[$d] | tostring) else empty end' \
            "$CRM_CLIENT_MAP_PATH" 2>/dev/null || true)"
        if [[ "$cid" =~ ^[0-9]+$ ]]; then echo "$cid"; return; fi
    fi
    if [[ -n "${CRM_CLIENT_ID:-}" ]]; then echo "${CRM_CLIENT_ID}"; fi
}

# ---------------------------------------------------------------------------
# --list-sites
# ---------------------------------------------------------------------------
if [[ "$MODE" == "list-sites" ]]; then
    echo "label	client_id	path"
    while IFS=$'\t' read -r label path site_cid; do
        [[ -z "$label" ]] && continue
        cid="$(client_id_for "$label" "$site_cid")"
        printf '%s\t%s\t%s\n' "$label" "${cid:-<belum ada>}" "$path"
    done < <(jq -r '.[] | [.label, .path, (.client_id // "" | tostring)] | @tsv' "$SITES_FILE")
    exit 0
fi

# ---------------------------------------------------------------------------
# Watchlist: hitung sha256 file yang cocok dengan glob situs.
# Keluaran: TSV "path<TAB>sha256" terurut.
# ---------------------------------------------------------------------------
watch_current() { # <site_path> <glob1 glob2 ...>
    local spath="$1"; shift
    local g f h
    for g in "$@"; do
        # Sengaja tanpa kutip: glob harus diekspansi relatif ke situs.
        # shellcheck disable=SC2086
        for f in "$spath"/$g; do
            [[ -f "$f" && -r "$f" ]] || continue
            h="$(sha256sum "$f" 2>/dev/null | awk '{print $1}')"
            [[ -n "$h" ]] && printf '%s\t%s\n' "${f#"$spath"/}" "$h"
        done
    done | sort -u
}

# ---------------------------------------------------------------------------
# Deteksi satu situs.
# Keluaran: file temuan TSV "kind<TAB>sev<TAB>path<TAB>message" di $2
# Return  : 0 selalu (kesalahan wp-cli dicatat sebagai temuan kind=error)
# ---------------------------------------------------------------------------
detect_site() { # <label> <site_path> <site_user> <findings_file> <watch_globs_quoted...>
    local label="$1" spath="$2" suser="$3" ffile="$4"
    shift 4
    local globs=("$@")
    : > "$ffile"
    local user_arg=""
    [[ -n "$suser" ]] && user_arg="--user=$suser"

    if [[ ! -d "$spath" ]]; then
        printf 'error\thigh\t%s\tDirektori situs tidak ditemukan\n' "$spath" >> "$ffile"
        return 0
    fi

    # --- 1. Core checksums -------------------------------------------------
    local core_raw core_rc=0
    core_raw="$("$WP_BIN" core verify-checksums --path="$spath" $user_arg $WP_EXTRA 2>&1)" || core_rc=$?
    local line p
    while IFS= read -r line; do
        case "$line" in
            "Warning: File doesn't verify against checksum: "*)
                p="${line#"Warning: File doesn't verify against checksum: "}"
                printf 'core\t%s\t%s\tChecksum tidak cocok\n' "$SEV_CORE" "$p" >> "$ffile" ;;
            "Warning: File doesn't exist: "*)
                p="${line#"Warning: File doesn't exist: "}"
                printf 'core\t%s\t%s\tFile hilang\n' "$SEV_CORE" "$p" >> "$ffile" ;;
            "Warning: File should not exist: "*)
                p="${line#"Warning: File should not exist: "}"
                printf 'core\t%s\t%s\tFile tidak seharusnya ada\n' "$SEV_CORE" "$p" >> "$ffile" ;;
        esac
    done <<< "$core_raw"
    if [[ "$core_rc" -ne 0 ]] && ! grep -q $'^core\t' "$ffile" 2>/dev/null; then
        # Gagal tanpa pola Warning dikenal (mis. bukan instalasi WP / error PHP).
        printf 'error\thigh\tcore\twp core verify-checksums gagal (rc=%s): %s\n' \
            "$core_rc" "$(printf '%s' "$core_raw" | head -c 200 | tr '\n' ' ')" >> "$ffile"
    fi

    # --- 2. Plugin checksums -----------------------------------------------
    local plug_json plug_rc=0 plug_err
    plug_err="$(mktemp "${TMPDIR:-/tmp}/fim.XXXXXX")"
    plug_json="$("$WP_BIN" plugin verify-checksums --all --path="$spath" $user_arg $WP_EXTRA --format=json 2>"$plug_err")" || plug_rc=$?
    if [[ -n "$plug_json" ]] && printf '%s' "$plug_json" | jq -e 'type == "array"' >/dev/null 2>&1; then
        printf '%s' "$plug_json" | jq -r '.[] | [.plugin_name // "?", .file // "?", .message // "?"] | @tsv' 2>/dev/null | \
        while IFS=$'\t' read -r pname pfile pmsg; do
            printf 'plugin\t%s\twp-content/plugins/%s/%s\t%s\n' "$SEV_PLUGIN" "$pname" "$pfile" "$pmsg" >> "$ffile"
        done
    elif [[ "$plug_rc" -ne 0 ]]; then
        # JSON tidak terbaca tapi rc gagal — catat sebagai error situs.
        printf 'error\thigh\tplugins\twp plugin verify-checksums gagal (rc=%s): %s\n' \
            "$plug_rc" "$(head -c 200 "$plug_err" | tr '\n' ' ')" >> "$ffile"
    fi
    rm -f "$plug_err"

    # --- 3. Watchlist vs baseline -------------------------------------------
    local bdir="$STATE_DIR/sites/$label"
    local bfile="$bdir/watch-baseline.json"
    local cur_tsv cur_json
    cur_tsv="$(mktemp "${TMPDIR:-/tmp}/fim.XXXXXX")"
    watch_current "$spath" "${globs[@]}" > "$cur_tsv"
    cur_json="$(jq -Rn '[inputs | gsub("\r$"; "") | split("\t") | {key: .[0], value: .[1]}] | from_entries' < "$cur_tsv" 2>/dev/null || echo '{}')"

    if [[ ! -f "$bfile" ]]; then
        # Run pertama: bangun baseline, tanpa alert (hindari false positive awal).
        mkdir -p "$bdir"
        state_write_atomic "$bfile" "$cur_json"
        log "$label: baseline watchlist dibangun ($(printf '%s' "$cur_json" | jq 'length') file)"
    else
        local base_json
        base_json="$(cat "$bfile" 2>/dev/null || echo '{}')"
        printf '%s' "$base_json" | jq -e 'type == "object"' >/dev/null 2>&1 || base_json='{}'
        # modified / missing
        while IFS=$'\t' read -r wp wh; do
            [[ -z "$wp" ]] && continue
            if ! printf '%s' "$cur_json" | jq -e --arg k "$wp" 'has($k)' >/dev/null 2>&1; then
                printf 'watch\t%s\t%s\tFile hilang dari watchlist\n' "$SEV_WATCH" "$wp" >> "$ffile"
            else
                local ch
                ch="$(printf '%s' "$cur_json" | jq -r --arg k "$wp" '.[$k]')"
                if [[ "$ch" != "$wh" ]]; then
                    printf 'watch\t%s\t%s\tIsi berubah (sha256 berbeda)\n' "$SEV_WATCH" "$wp" >> "$ffile"
                fi
            fi
        done < <(printf '%s' "$base_json" | jq -r 'to_entries[] | [.key, .value] | @tsv')
        # added (ada di disk, tidak di baseline)
        while IFS=$'\t' read -r wp wh; do
            [[ -z "$wp" ]] && continue
            if ! printf '%s' "$base_json" | jq -e --arg k "$wp" 'has($k)' >/dev/null 2>&1; then
                printf 'watch\t%s\t%s\tFile baru di watchlist\n' "$SEV_WATCH" "$wp" >> "$ffile"
            fi
        done < "$cur_tsv"
    fi
    rm -f "$cur_tsv"
    return 0
}

# ---------------------------------------------------------------------------
# Bangun event CRM dari temuan satu situs.
# Keluaran: satu baris JSON event di $6 (bila ada temuan); fingerprint di stdout
# ---------------------------------------------------------------------------
build_event() { # <label> <findings_file> <client_id> <event_out_file>
    local label="$1" ffile="$2" cid="$3" outfile="$4"
    local fp n core_n plugin_n watch_n err_n sev_max="info" rank r
    if [[ ! -s "$ffile" ]]; then
        echo "clean"
        return 0
    fi
    fp="$(sort -u "$ffile" | sha256sum | awk '{print $1}')"
    n="$(sort -u "$ffile" | wc -l)"
    core_n="$(awk -F'\t' '$1 == "core"' "$ffile" | sort -u | wc -l)"
    plugin_n="$(awk -F'\t' '$1 == "plugin"' "$ffile" | sort -u | wc -l)"
    watch_n="$(awk -F'\t' '$1 == "watch"' "$ffile" | sort -u | wc -l)"
    err_n="$(awk -F'\t' '$1 == "error"' "$ffile" | sort -u | wc -l)"
    while IFS= read -r s; do
        r="$(sev_rank "$s")"
        if [[ "$r" -gt "$(sev_rank "$sev_max")" ]]; then sev_max="$s"; fi
    done < <(awk -F'\t' '{print $2}' "$ffile" | sort -u)

    local sample_count=0 desc_paths=""
    while IFS=$'\t' read -r kind ksev kpath kmsg; do
        [[ -z "$kpath" ]] && continue
        if [[ "$sample_count" -ge "$MAX_REPORT_FILES" ]]; then
            desc_paths+="  …(masih ada $(( n - sample_count )) temuan lain)"$'\n'
            break
        fi
        desc_paths+="  [$kind/$ksev] $kpath — $kmsg"$'\n'
        sample_count=$(( sample_count + 1 ))
    done < <(sort -u "$ffile")

    local external_id="fim-${label}-${fp:0:32}"
    local title="Integritas file WordPress: $n temuan di $label"
    title="$(printf '%s' "$title" | head -c 250)"
    local description
    description="File integrity monitoring (wp-cli verify-checksums + watchlist sha256) menemukan $n temuan di situs \"$label\" — core: $core_n, plugin: $plugin_n, watchlist: $watch_n, error sistem: $err_n.
$desc_paths
Tindak lanjut: periksa file tersebut di server; bila perubahan sah (update/edit resmi), jalankan 'wp-file-integrity.sh --rebuild-baseline $label'."
    description="$(printf '%s' "$description" | head -c 4990)"

    jq -c -n \
        --arg external_id "$external_id" \
        --argjson client_id "$cid" \
        --arg occurred_at "$(iso)" \
        --arg severity "$sev_max" \
        --arg source "file-integrity" \
        --arg title "$title" \
        --arg description "$description" \
        '{external_id: $external_id, client_id: $client_id, occurred_at: $occurred_at, severity: $severity, source: $source, title: $title, description: $description}' \
        > "$outfile" || { log "WARN $label: gagal membangun event JSON"; echo "$fp"; return 0; }
    echo "$fp"
}

# ---------------------------------------------------------------------------
# POST events ke CRM + outbox retry (kontrak sama dengan feed F3-3).
# ---------------------------------------------------------------------------
DELIVERY_FAILED=0
post_events() { # <ndjson_file>
    local file="$1"
    local outbox="$STATE_DIR/outbox-events.ndjson"
    local merged="$STATE_DIR/.merged.$$"
    : > "$merged"
    if [[ -s "$outbox" ]]; then
        cat "$outbox" >> "$merged"
        log "events: outbox berisi $(wc -l < "$outbox") baris — ikut dikirim"
    fi
    [[ -s "$file" ]] && cat "$file" >> "$merged"
    local total
    total="$(wc -l < "$merged")"
    if [[ "$total" -eq 0 ]]; then
        rm -f "$merged"
        log "events: tidak ada payload, lewati"
        return 0
    fi
    if [[ "$MODE" == "dry-run" ]]; then
        log "DRY_RUN events: $total record siap kirim (POST dilewati)"
        cat "$merged" >> "$SELF_LOG"
        rm -f "$merged"
        return 0
    fi
    local resp code body sent=0 failed=0
    # Jumlah situs kecil (1 event/situs) — cukup satu POST; chunk bila perlu.
    local partdir="$STATE_DIR/.parts.$$"
    mkdir -p "$partdir"
    split -l "$MAX_EVENTS" -a 3 --numeric-suffixes "$merged" "$partdir/part."
    local nfile wrapper
    for nfile in "$partdir"/part.*; do
        [[ -e "$nfile" ]] || continue
        wrapper="$(jq -cs '{events: .}' "$nfile")"
        if ! resp="$(curl -sS --max-time "$HTTP_TIMEOUT" --retry 2 --retry-delay 2 --retry-connrefused \
            -X POST \
            -H "Authorization: Bearer $SECURITY_API_TOKEN" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -H "User-Agent: wp-file-integrity/$VERSION ($(hostname 2>/dev/null || echo host))" \
            -w '\n%{http_code}' \
            --data-binary "$wrapper" \
            "$CRM_API_URL/api/security/events" 2>>"$SELF_LOG")"; then
            resp=$'\n000'
        fi
        code="$(printf '%s' "$resp" | tail -n1)"
        body="$(printf '%s' "$resp" | sed '$d')"
        case "$code" in
            200|201)
                if ! printf '%s' "$body" | jq -e 'type == "object" and .status == "ok"' >/dev/null 2>&1; then
                    log "ERROR events: HTTP $code tetapi respons bukan JSON kontrak ({status:ok}) — outbox. Body: $(printf '%s' "$body" | head -c 160)"
                    cat "$nfile" >> "$STATE_DIR/.fail.$$"; failed=$(( failed + 1 )); continue
                fi
                local created dup errs
                created="$(printf '%s' "$body" | jq -r '.created // 0')"
                dup="$(printf '%s' "$body" | jq -r '.duplicates // 0')"
                errs="$(printf '%s' "$body" | jq -r '.errors // [] | length')"
                log "OK events: $(wc -l < "$nfile") record → HTTP $code (created=$created dup=$dup errors=$errs)"
                if [[ "$errs" != "0" ]]; then
                    log "WARN events: $(printf '%s' "$body" | jq -c '.errors[0:3]' 2>/dev/null || echo '(body tidak terbaca)')"
                fi
                sent=$(( sent + 1 ));;
            422)
                log "ERROR events: HTTP 422 payload ditolak CRM (tidak di-retry) — $(printf '%s' "$body" | head -c 300)";;
            401|403)
                log "ERROR events: HTTP $code token/akses ditolak — record disimpan di outbox"
                cat "$nfile" >> "$STATE_DIR/.fail.$$"; failed=$(( failed + 1 ));;
            *)
                log "ERROR events: HTTP ${code:-000} — record disimpan di outbox utk retry"
                cat "$nfile" >> "$STATE_DIR/.fail.$$"; failed=$(( failed + 1 ));;
        esac
    done
    rm -rf "$partdir" "$merged"
    local outbox_new="$STATE_DIR/outbox-events.ndjson"
    if [[ -s "$STATE_DIR/.fail.$$" ]]; then
        tail -n "$OUTBOX_MAX_LINES" "$STATE_DIR/.fail.$$" > "$outbox_new.tmp.$$" && mv -f "$outbox_new.tmp.$$" "$outbox_new"
        rm -f "$STATE_DIR/.fail.$$"
        log "events: $failed chunk gagal → outbox ($(wc -l < "$outbox_new") baris menunggu retry)"
        DELIVERY_FAILED=1
    else
        rm -f "$STATE_DIR/.fail.$$"
        : > "$outbox_new.tmp.$$" && mv -f "$outbox_new.tmp.$$" "$outbox_new"
    fi
    log "events: $sent chunk terkirim, $failed chunk gagal (total $total record)"
}

# ---------------------------------------------------------------------------
# Main loop per situs
# ---------------------------------------------------------------------------
log "=== run start (v$VERSION pid $$ mode=$MODE sites=$SITE_COUNT) ==="

TMP_MAIN="$(mktemp -d "${TMPDIR:-/tmp}/fim.XXXXXX")"
trap 'rm -rf "$TMP_MAIN"' EXIT
EVENTS_NDJSON="$TMP_MAIN/events.ndjson"
: > "$EVENTS_NDJSON"

TOTAL_FINDINGS=0; TOTAL_EVENTS=0

# Catatan: pemisah 0x1f (unit separator), BUKAN tab. Tab adalah whitespace di
# IFS, sehingga field kosong (mis. .user tidak diset) terbuang dan kolom
# berikutnya tergeser — client_id terbaca "null" lalu event gagal terkirim.
while IFS=$'\x1f' read -r label spath suser site_cid watch_json; do
    [[ -z "$label" ]] && continue

    # glob watchlist situs: override per situs (array JSON) atau default env.
    globs=()
    if [[ -n "$watch_json" && "$watch_json" != "null" ]]; then
        while IFS= read -r g; do globs+=("$g"); done < <(printf '%s' "$watch_json" | jq -r '.[]' 2>/dev/null)
    fi
    if [[ ${#globs[@]} -eq 0 ]]; then
        read -r -a globs <<< "$WATCH_GLOBS"
    fi

    ffile="$TMP_MAIN/findings.$label.tsv"

    if [[ "$MODE" == "rebuild-baseline" ]]; then
        if [[ "$label" != "$MODE_ARG" ]]; then continue; fi
        cur="$(watch_current "$spath" "${globs[@]}")"
        cur_json="$(printf '%s' "$cur" | jq -Rn '[inputs | gsub("\r$"; "") | split("\t") | {key: .[0], value: .[1]}] | from_entries' 2>/dev/null || echo '{}')"
        mkdir -p "$STATE_DIR/sites/$label"
        printf '%s' "$cur_json" > "$STATE_DIR/sites/$label/watch-baseline.json.tmp.$$" \
            && mv -f "$STATE_DIR/sites/$label/watch-baseline.json.tmp.$$" "$STATE_DIR/sites/$label/watch-baseline.json"
        # Catatan: last-fingerprint TIDAK ditimpa. Temuan non-watch yang tersisa
        # (mis. core/plugin) tetap harus terkirim sekali dengan fingerprint baru.
        log "$label: baseline watchlist disetujui ulang ($(printf '%s' "$cur_json" | jq 'length') file)"
        echo "OK: baseline $label dibangun ulang ($(printf '%s' "$cur_json" | jq 'length') file)"
        continue
    fi

    detect_site "$label" "$spath" "$suser" "$ffile" "${globs[@]}"
    n="$(sort -u "$ffile" | wc -l)"
    TOTAL_FINDINGS=$(( TOTAL_FINDINGS + n ))

    cid="$(client_id_for "$label" "$site_cid")"
    lastfp_file="$STATE_DIR/sites/$label/last-fingerprint.json"
    last_fp=""
    [[ -f "$lastfp_file" ]] && last_fp="$(jq -r '.fp // ""' "$lastfp_file" 2>/dev/null || true)"

    if [[ "$MODE" == "verify-site" ]]; then
        if [[ "$label" != "$MODE_ARG" ]]; then continue; fi
        echo "Situs: $label ($spath)"
        if [[ "$n" -eq 0 ]]; then
            echo "  Bersih — tidak ada temuan."
        else
            sort -u "$ffile" | awk -F'\t' '{printf "  [%s/%s] %s — %s\n", $1, $2, $3, $4}'
        fi
        continue
    fi

    if [[ "$n" -eq 0 ]]; then
        # Bersih: reset last-fp agar temuan baru di masa depan tetap terkirim.
        if [[ -n "$last_fp" && "$last_fp" != "clean" ]]; then
            state_write_atomic "$lastfp_file" "$(jq -cn --argjson t "$NOW" '{fp: "clean", sent_at: $t}')"
            log "$label: bersih (temuan sebelumnya sudah pulih)"
        else
            log "$label: bersih"
        fi
        continue
    fi

    event_file="$TMP_MAIN/event.$label.json"
    if [[ -z "$cid" ]]; then
        log "WARN $label: client_id tidak ditemukan (map/default kosong) — $n temuan TIDAK terkirim"
        continue
    fi
    fp="$(build_event "$label" "$ffile" "$cid" "$event_file")"

    if [[ -n "$last_fp" && "$last_fp" == "$fp" ]]; then
        log "$label: $n temuan, fingerprint sama dengan last-sent (${fp:0:12}) — dedup lokal, tidak POST ulang"
        continue
    fi

    cat "$event_file" >> "$EVENTS_NDJSON"
    TOTAL_EVENTS=$(( TOTAL_EVENTS + 1 ))
    log "ALERT $label: $n temuan (fp=${fp:0:12}, severity=$(jq -r '.severity' "$event_file"))"

    # Tandai fingerprint terkirim hanya jika POST benar-benar sukses;
    # penandaan dilakukan setelah post_events (cek DELIVERY_FAILED per situs
    # tidak praktis, jadi: tandai setelah seluruh POST bila tidak gagal).
    printf '%s\t%s\n' "$label" "$fp" >> "$TMP_MAIN/pending-fp.tsv"
done < <(jq -r '.[] | [.label, .path, (.user // ""), (.client_id // "" | tostring), (.watch // null | tojson)] | join("\u001f")' "$SITES_FILE")

if [[ "$MODE" == "verify-site" ]]; then
    exit 0
fi
if [[ "$MODE" == "rebuild-baseline" ]]; then
    exit 0
fi

if [[ -s "$EVENTS_NDJSON" ]]; then
    post_events "$EVENTS_NDJSON"
    if [[ "$DELIVERY_FAILED" == "0" && "$MODE" != "dry-run" && -s "$TMP_MAIN/pending-fp.tsv" ]]; then
        while IFS=$'\t' read -r label fp; do
            mkdir -p "$STATE_DIR/sites/$label"
            state_write_atomic "$STATE_DIR/sites/$label/last-fingerprint.json" \
                "$(jq -cn --arg fp "$fp" --argjson t "$NOW" '{fp: $fp, sent_at: $t}')"
        done < "$TMP_MAIN/pending-fp.tsv"
    fi
fi

log "=== run done: findings=$TOTAL_FINDINGS events=$TOTAL_EVENTS mode=$MODE delivery_failed=$DELIVERY_FAILED ==="
[[ "$DELIVERY_FAILED" == "1" ]] && exit 1
exit 0