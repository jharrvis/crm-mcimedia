#!/usr/bin/env bash
# =============================================================================
# install-sg2.sh — pemasang paket wp-file-integrity di server hosting (sg2).
#
# Mode:
#   (tanpa arg)   DRY-RUN — hanya menampilkan apa yang akan dilakukan.
#   --apply       pasang sungguhan.
#   --rollback    cabut instalasi (script + cron + logrotate). Konfigurasi di
#                 /etc/wp-file-integrity* dan state di STATE_DIR dibiarkan
#                 (hapus manual bila ingin bersih total).
#
# Yang dipasang (--apply):
#   /usr/local/bin/wp-file-integrity.sh          (755, dari paket)
#   /etc/cron.d/wp-file-integrity                (644, cron tiap 15 menit)
#   /etc/logrotate.d/wp-file-integrity           (644)
#   /etc/wp-file-integrity.env                   (600, hanya bila BELUM ada)
#   /etc/wp-file-integrity/sites.json            (600, hanya bila BELUM ada)
#   /var/lib/wp-file-integrity/                  (700, state)
#
# Prasyarat di server: wp-cli di PATH (WP_BIN bisa menunjuk path lain),
# jq, curl, coreutils. Cron Hestia/server root sudah berjalan.
# =============================================================================
set -euo pipefail

PKG_DIR="$(cd "$(dirname "$0")" && pwd)"
MODE="dry"
case "${1:-}" in
    --apply)    MODE="apply" ;;
    --rollback) MODE="rollback" ;;
    "") ;;
    *) echo "Pakaian: $0 [--apply|--rollback]" >&2; exit 64 ;;
esac

need_root() {
    if [[ "$(id -u)" != "0" ]]; then
        echo "ERROR: harus dijalankan sebagai root (operator via sg2-ssh museops)." >&2
        exit 1
    fi
}

# Eksekusi sungguhan kecuali mode dry. (MODE=rollback juga harus mengeksekusi —
# syarat lama "$MODE" == apply membuat --rollback hanya mencetak perintah tanpa
# menghapus apa pun; terverifikasi 2026-10-05 oleh devops, kanban t_a0ae5dd5.)
run() { echo "  RUN: $*"; [[ "$MODE" != "dry" ]] && eval "$@" || true; }

case "$MODE" in
dry)
    echo "[DRY-RUN] Tidak ada yang diubah. Jalankan dengan --apply untuk memasang."
    echo "Langkah yang akan dilakukan:"
    echo "  1. Cek dependensi: jq curl sha256sum wp-cli"
    echo "  2. install -m755 $PKG_DIR/wp-file-integrity.sh /usr/local/bin/"
    echo "  3. install -m644 cron + logrotate ke /etc"
    echo "  4. Buat /etc/wp-file-integrity.env (600) + sites.json (600) dari contoh — bila belum ada"
    echo "  5. mkdir /var/lib/wp-file-integrity (700)"
    echo
    echo "Setelah --apply: edit /etc/wp-file-integrity.env (token!) + sites.json,"
    echo "lalu uji: wp-file-integrity.sh --status && wp-file-integrity.sh --verify-site <label>"
    exit 0
    ;;
rollback)
    need_root
    echo "[ROLLBACK] Mencabut wp-file-integrity..."
    run "rm -f /usr/local/bin/wp-file-integrity.sh"
    run "rm -f /etc/cron.d/wp-file-integrity"
    run "rm -f /etc/logrotate.d/wp-file-integrity"
    echo "Konfigurasi /etc/wp-file-integrity.env, sites.json, dan state"
    echo "/var/lib/wp-file-integrity dibiarkan. Hapus manual bila perlu:"
    echo "  rm -rf /etc/wp-file-integrity.env /etc/wp-file-integrity /var/lib/wp-file-integrity /var/log/wp-file-integrity*.log"
    exit 0
    ;;
esac

need_root
echo "[APPLY] Memasang wp-file-integrity..."

echo "1) Cek dependensi..."
for bin in jq curl sha256sum; do
    command -v "$bin" >/dev/null 2>&1 || { echo "ERROR: dependensi kurang: $bin" >&2; exit 6; }
done
if command -v wp >/dev/null 2>&1; then
    echo "   wp-cli: $(wp --version 2>/dev/null || echo '?')"
else
    echo "   PERINGATAN: wp-cli tidak ada di PATH — set WP_BIN di /etc/wp-file-integrity.env"
fi

echo "2) Script utama..."
install -m 755 "$PKG_DIR/wp-file-integrity.sh" /usr/local/bin/wp-file-integrity.sh

echo "3) Cron + logrotate..."
install -m 644 "$PKG_DIR/wp-file-integrity.cron" /etc/cron.d/wp-file-integrity
install -m 644 "$PKG_DIR/wp-file-integrity.logrotate" /etc/logrotate.d/wp-file-integrity

echo "4) Konfigurasi (tidak menimpa yang sudah ada)..."
if [[ ! -f /etc/wp-file-integrity.env ]]; then
    install -m 600 "$PKG_DIR/wp-file-integrity.env.example" /etc/wp-file-integrity.env
    echo "   dibuat: /etc/wp-file-integrity.env (isi SECURITY_API_TOKEN dll.)"
else
    echo "   sudah ada: /etc/wp-file-integrity.env — dibiarkan"
fi
mkdir -p /etc/wp-file-integrity
if [[ ! -f /etc/wp-file-integrity/sites.json ]]; then
    install -m 600 "$PKG_DIR/sites.example.json" /etc/wp-file-integrity/sites.json
    echo "   dibuat: /etc/wp-file-integrity/sites.json (SESUAIKAN daftar situs!)"
else
    echo "   sudah ada: /etc/wp-file-integrity/sites.json — dibiarkan"
fi

echo "5) State dir..."
install -d -m 700 /var/lib/wp-file-integrity

echo
echo "SELESAI. Langkah selanjutnya:"
echo "  1. edit /etc/wp-file-integrity.env  (CRM_API_URL, SECURITY_API_TOKEN, CRM_CLIENT_ID)"
echo "  2. edit /etc/wp-file-integrity/sites.json  (daftar situs WP + label + client_id)"
echo "  3. wp-file-integrity.sh --status"
echo "  4. wp-file-integrity.sh --list-sites && wp-file-integrity.sh --verify-site <label>"
echo "  5. wp-file-integrity.sh --dry-run   (lihat event yang akan dikirim)"