#!/usr/bin/env bash
set -euo pipefail

# Instalace aplikace Odměny na Ubuntu s Apachem: sudo bash odmeny/deploy/install.sh
# Aplikace běží na adrese /odmeny/ vedle Trading Desku, ale nemá s ním nic společného:
# vlastní adresář, vlastní data i vlastní přihlašování.

if [[ "${EUID}" -ne 0 ]]; then
  echo "Spusť instalaci přes sudo: sudo bash odmeny/deploy/install.sh"
  exit 1
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="/var/www/odmeny"
DATA_DIR="/var/lib/odmeny"
APACHE_CONF="/etc/apache2/conf-available/odmeny.conf"

apt-get update
apt-get install -y apache2 php php-cli php-sqlite3 php-mbstring rsync

install -d -m 0750 -o root -g www-data "${TARGET_DIR}"
install -d -m 0700 -o www-data -g www-data "${DATA_DIR}"

rsync -a --delete \
  --exclude '/data/' \
  --exclude '/tests/' \
  --exclude '/deploy/' \
  --exclude '/dev-router.php' \
  --exclude '/README.md' \
  --exclude '.*' \
  "${SOURCE_DIR}/" "${TARGET_DIR}/"

chown -R root:www-data "${TARGET_DIR}"
find "${TARGET_DIR}" -type d -exec chmod 0750 {} +
find "${TARGET_DIR}" -type f -exec chmod 0640 {} +
chown -R www-data:www-data "${DATA_DIR}"
chmod 0700 "${DATA_DIR}"

install -m 0644 "${SOURCE_DIR}/deploy/apache-odmeny.conf" "${APACHE_CONF}"
a2enconf odmeny >/dev/null

apache2ctl configtest

if command -v systemctl >/dev/null 2>&1 && systemctl is-system-running >/dev/null 2>&1; then
  systemctl enable apache2 >/dev/null 2>&1 || true
  systemctl reload apache2 || systemctl restart apache2
else
  service apache2 reload || service apache2 start
fi

# Dokud neexistuje žádná kartička, vypíše se jednorázový kód pro první spuštění.
SETUP_CODE="$(runuser -u www-data -- env ODMENY_DATA_DIR="${DATA_DIR}" php "${TARGET_DIR}/bin/setup-token.php" 2>/dev/null || true)"

echo
echo "Odměny jsou nainstalované: https://<adresa-serveru>/odmeny/"
echo "Šifrovaná data: ${DATA_DIR}"
if [[ -n "${SETUP_CODE}" && "${SETUP_CODE}" != *"nastavená"* ]]; then
  echo
  echo "První spuštění: otevři aplikaci přes HTTPS, zadej tento kód a vytvoř přístupovou kartičku:"
  echo "  ${SETUP_CODE}"
  echo
  echo "Kód znovu vypíše:"
  echo "  sudo runuser -u www-data -- env ODMENY_DATA_DIR=${DATA_DIR} php ${TARGET_DIR}/bin/setup-token.php"
else
  echo "Aplikace už je nastavená, přihlas se kartičkou."
fi
