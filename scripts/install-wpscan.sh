#!/usr/bin/env bash
# Instalasi binary WPScan CLI untuk server CRM (t_2e555b0b).
#
# Dipakai oleh pesan error WpScanRunner bila `crm.wpscan.binary` tidak ada.
# Jalankan dari root repositori CRM:
#
#   sudo bash scripts/install-wpscan.sh
#
# Strategi (berhenti pada yang berhasil):
#   1. bila `wpscan` sudah ada di PATH -> selesai
#   2. gem install wpscan (butuh ruby >= 2.7; di Ubuntu: apt install ruby-full)
#   3. fallback docker: image official wpscanteam/wpscan dengan symlink
#      wrapper ke /usr/local/bin/wpscan (config crm.wpscan.binary tetap
#      `wpscan`; wrapper meneruskan argumen apa adanya ke container)
set -euo pipefail

WPSCAN_LINK=/usr/local/bin/wpscan

if command -v wpscan >/dev/null 2>&1; then
    echo "WPScan sudah terpasang: $(command -v wpscan)"
    wpscan --version || true
    exit 0
fi

if ! command -v gem >/dev/null 2>&1; then
    echo "gem tidak ditemukan — memasang ruby via apt (butuh sudo)..."
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ruby-full build-essential
fi

echo "Memasang wpscan via gem..."
if gem install wpscan --no-document; then
    echo "Terpasang: $(command -v wpscan || echo 'wpscan')"
    wpscan --version || true
    exit 0
fi

echo "gem install gagal — mencoba fallback docker..."
if ! command -v docker >/dev/null 2>&1; then
    echo "ERROR: neither gem nor docker available. Install manually: https://wpscan.com/download" >&2
    exit 1
fi

cat > "$WPSCAN_LINK" <<'WRAPPER'
#!/usr/bin/env bash
# Wrapper resmi image wpscanteam/wpscan — argumen diteruskan apa adanya.
exec docker run --rm -i wpscanteam/wpscan "$@"
WRAPPER
chmod 0755 "$WPSCAN_LINK"

wpscan --version || true
echo "WPScan (docker wrapper) terpasang di $WPSCAN_LINK"
