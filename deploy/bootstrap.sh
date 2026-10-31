#!/usr/bin/env bash
# ============================================================================
# deploy/bootstrap.sh — One-shot LAMP + Tesseract setup for Ubuntu 24.04 LTS
# Target: AWS EC2 t3.small (ap-southeast-1), Ubuntu 24.04
#
# Idempotent: safe to re-run. Does NOT import SQL and does NOT create .env —
# those are separate, deliberate steps in deploy/README.md.
#
# Usage (from the project root on the server):
#   sudo APP_DIR=/var/www/tubtubon_ehealth \
#        DB_NAME=tubtubon_ehealth DB_USER=tubtubon DB_PASS='your-strong-pass' \
#        SERVER_NAME=13.212.44.10.nip.io \
#        bash deploy/bootstrap.sh
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/tubtubon_ehealth}"
DB_NAME="${DB_NAME:-tubtubon_ehealth}"
DB_USER="${DB_USER:-tubtubon}"
DB_PASS="${DB_PASS:?ERROR: set DB_PASS before running}"
SERVER_NAME="${SERVER_NAME:?ERROR: set SERVER_NAME (IP or hostname) for the vhost}"
PHP_VER="8.3"

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo." >&2; exit 1; }

# ---------------------------------------------------------------------------
log "1/9  System update + packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y --no-install-recommends \
  apache2 \
  "php${PHP_VER}" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" \
  "php${PHP_VER}-mbstring" "php${PHP_VER}-zip" "php${PHP_VER}-curl" \
  "php${PHP_VER}-xml" "php${PHP_VER}-gd" \
  mysql-server \
  tesseract-ocr tesseract-ocr-eng \
  git unzip certbot python3-certbot-apache

# ---------------------------------------------------------------------------
log "2/9  Enabling services (so the site survives stop -> start)"
# THIS is the step people forget. Without 'enable', a restarted EC2 comes up
# with Apache/MySQL dead and every page 500s.
systemctl enable --now apache2
systemctl enable --now mysql

# ---------------------------------------------------------------------------
log "3/9  Apache modules"
a2enmod rewrite headers >/dev/null

# ---------------------------------------------------------------------------
log "4/9  PHP tuning (OCR is CPU + memory heavy)"
cat > "/etc/php/${PHP_VER}/apache2/conf.d/99-tubtubon.ini" <<'INI'
; Tubtubon eHealth — upload + OCR headroom
upload_max_filesize = 8M
post_max_size = 10M
memory_limit = 256M
; Tesseract on a 1240x1500 form takes a few seconds; 30s is too tight
max_execution_time = 120
max_input_time = 120
; Never expose errors on a public host
display_errors = Off
log_errors = On
INI

# ---------------------------------------------------------------------------
log "5/9  Database + least-privilege app user"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "    database '${DB_NAME}' + user '${DB_USER}' ready"

# ---------------------------------------------------------------------------
log "6/9  Apache vhost (DirectoryIndex + .env deny + AllowOverride)"
sed "s/SERVER_NAME_HERE/${SERVER_NAME}/" "${APP_DIR}/deploy/apache-vhost.conf" \
  > /etc/apache2/sites-available/tubtubon.conf
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite tubtubon >/dev/null
apache2ctl configtest
systemctl reload apache2

# ---------------------------------------------------------------------------
log "7/9  Storage permissions (uploads, backups, ZIP archives)"
mkdir -p "${APP_DIR}/storage/uploads" "${APP_DIR}/storage/backups"
chown -R www-data:www-data "${APP_DIR}/storage"
chmod -R 775 "${APP_DIR}/storage"

# ---------------------------------------------------------------------------
log "8/9  Tesseract check"
if ! tesseract --version 2>&1 | head -1; then
  echo "!! Tesseract not callable — OCR will fall back to manual encoding." >&2
fi
LANGS="$(tesseract --list-langs 2>/dev/null | tail -n +2 | tr '\n' ' ')"
echo "    languages available: ${LANGS:-NONE}"
case " ${LANGS} " in
  *" eng "*) echo "    eng OK" ;;
  *) echo "!! 'eng' language data missing -> sudo apt-get install -y tesseract-ocr-eng" >&2 ;;
esac
php -r 'echo "    php ".PHP_VERSION." | shell_exec available: ".(function_exists("shell_exec")?"yes":"NO")."\n";'

# ---------------------------------------------------------------------------
log "9/9  Done. Remaining manual steps:"
cat <<'NEXT'

  a) Create the .env (placeholders must be replaced):
       cd /var/www/tubtubon_ehealth
       sudo -u www-data cp deploy/env.production.example .env
       sudo sed -i "s/CHANGE_ME_STRONG_PASSWORD/<same password you passed here>/" .env
       sudo chown www-data:www-data .env && sudo chmod 640 .env

  b) Import the schema (ORDER MATTERS: 001 -> 002 -> 003):
       sudo mysql tubtubon_ehealth < migrations/001_schema.sql
       sudo mysql tubtubon_ehealth < migrations/002_seed_synthetic.sql
       sudo mysql tubtubon_ehealth < migrations/003_users_patient_fk.sql

  c) Run the preflight:
       sudo -u www-data php deploy/preflight.php

  d) TLS (only after DNS resolves to this IP):
       sudo certbot --apache -d <hostname>

NEXT
