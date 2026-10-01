#!/bin/bash
# Deploy CRM MCI Media Fase 1 — sg2, domain crm.mcimedia.net
# HISTORIS (deploy pertama 2026-10-01). Untuk redeploy baca docs/DEPLOY.md:
# project sekarang tinggal di private/crm (lolos open_basedir Hestia),
# BUKAN di public_html seperti asumsi awal script ini.
set -euo pipefail
export PATH="$PATH:/usr/local/hestia/bin"
APP_DIR=/home/mcimedia/web/crm.mcimedia.net/public_html
PHP=/usr/bin/php8.3
SRC=/tmp/crm-deploy

echo "== 1. Database =="
if v-list-database mcimedia crm >/dev/null 2>&1; then
  echo "DB mcimedia_crm sudah ada — berhenti agar tidak tertimpa."
  exit 1
fi
DBPASS=$(openssl rand -hex 18)
v-add-database mcimedia crm crm "$DBPASS" mysql
echo "DB mcimedia_crm dibuat."

echo "== 2. Salin source =="
mkdir -p "$APP_DIR"
# rsync tidak selalu tersedia; pakai cp
cp -a "$SRC"/. "$APP_DIR"/
rm -f "$APP_DIR/index.html" "$APP_DIR/robots.txt"
chown -R mcimedia:mcimedia "$APP_DIR"
echo "Source tersalin ke $APP_DIR"

echo "== 3. .env production =="
ADMINPASS=$(openssl rand -hex 10)
cat > "$APP_DIR/.env" <<EOF
APP_NAME="CRM MCI Media"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://crm.mcimedia.net
APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID
LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mcimedia_crm
DB_USERNAME=mcimedia_crm
DB_PASSWORD=$DBPASS
SESSION_DRIVER=database
SESSION_LIFETIME=120
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
ADMIN_EMAIL=admin@mcimedia.net
ADMIN_PASSWORD=$ADMINPASS
EOF
chown mcimedia:mcimedia "$APP_DIR/.env"
chmod 600 "$APP_DIR/.env"
printf 'Login: https://crm.mcimedia.net/login\nEmail: admin@mcimedia.net\nPassword: %s\nUbah kata sandi ini segera setelah login pertama (menu profil).\n' "$ADMINPASS" > /home/mcimedia/crm-initial-admin.txt
chown mcimedia:mcimedia /home/mcimedia/crm-initial-admin.txt
chmod 600 /home/mcimedia/crm-initial-admin.txt
echo ".env ditulis (mode 600). Kredensial admin awal: /home/mcimedia/crm-initial-admin.txt"

echo "== 4. Artisan setup sebagai mcimedia =="
runuser -u mcimedia -- bash -c "cd $APP_DIR && $PHP artisan key:generate --force"
runuser -u mcimedia -- bash -c "cd $APP_DIR && $PHP artisan migrate --force"
runuser -u mcimedia -- bash -c "cd $APP_DIR && $PHP artisan db:seed --force"
runuser -u mcimedia -- bash -c "cd $APP_DIR && $PHP artisan storage:link" || true
runuser -u mcimedia -- bash -c "cd $APP_DIR && $PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
echo "Artisan setup selesai."

echo "== 5. Cron scheduler =="
v-add-cron-job mcimedia '*' '*' '*' '*' '*' "cd $APP_DIR && $PHP artisan schedule:run >> /dev/null 2>&1" || echo "cron terlewati (sudah ada?)"
echo "Cron schedule:run terpasang."

echo "== 6. Force HTTPS =="
v-add-web-domain-ssl-force mcimedia crm.mcimedia.net || echo "ssl-force gagal — periksa manual"
echo "SSL_FORCE diminta."

echo "DEPLOY-SELESAI"
