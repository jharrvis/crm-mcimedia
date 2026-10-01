#!/bin/bash
# Restructure CRM ke private/crm (lolos open_basedir Hestia) + pindah docroot
set -euo pipefail
export PATH="$PATH:/usr/local/hestia/bin"
DOMAIN_DIR=/home/mcimedia/web/crm.mcimedia.net
APP_OLD=$DOMAIN_DIR/public_html
APP_NEW=$DOMAIN_DIR/private/crm
PHP=/usr/bin/php8.3

echo "== 1. Pindah project =="
mkdir -p "$APP_NEW"
cp -a "$APP_OLD"/. "$APP_NEW"/
# symlink storage dibuat absolut terhadap path lama -> buat ulang nanti
rm -f "$APP_NEW/public/storage"
chown -R mcimedia:mcimedia "$DOMAIN_DIR/private"
find "$APP_NEW" -type d -exec chmod 755 {} +
find "$APP_NEW" -type f -exec chmod 644 {} +
chmod -R ug+rwX "$APP_NEW/storage" "$APP_NEW/bootstrap/cache"
chmod 600 "$APP_NEW/.env"
chown mcimedia:www-data "$DOMAIN_DIR/private"
chmod 751 "$DOMAIN_DIR/private"
echo "Project di $APP_NEW"

echo "== 2. Cache artisan di lokasi baru =="
runuser -u mcimedia -- bash -c "cd $APP_NEW && $PHP artisan config:clear && $PHP artisan route:clear && $PHP artisan view:clear"
runuser -u mcimedia -- bash -c "cd $APP_NEW && $PHP artisan storage:link"
runuser -u mcimedia -- bash -c "cd $APP_NEW && $PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache"
echo "Cache diperbarui."

echo "== 3. Pindah docroot =="
v-change-web-domain-docroot mcimedia crm.mcimedia.net '' "$APP_NEW/public" yes
grep -m1 'root ' /home/mcimedia/conf/web/crm.mcimedia.net/nginx.conf || true

echo "== 4. Bersihkan public_html lama =="
rm -rf "${APP_OLD:?}"/*
ls -la "$APP_OLD" | head -5

echo "== 5. Perbaiki cron scheduler =="
JOB=$(v-list-cron-jobs mcimedia plain 2>/dev/null | grep -F 'schedule:run' | awk '{print $1}' | head -1 || true)
if [ -n "$JOB" ]; then
  v-delete-cron-job mcimedia "$JOB"
  echo "Cron lama #$JOB dihapus."
fi
v-add-cron-job mcimedia '*' '*' '*' '*' '*' "cd $APP_NEW && $PHP artisan schedule:run >> /dev/null 2>&1"
echo "Cron baru dipasang untuk $APP_NEW"

echo "RESTRUCTURE-SELESAI"
