#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Spusť instalaci přes sudo: sudo bash deploy/install.sh"
  exit 1
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="/var/www/trading-journal"
DATA_DIR="/var/lib/trading-journal"
APACHE_CONF="/etc/apache2/conf-available/trading-journal.conf"

IS_WSL=0
if grep -qi microsoft /proc/version 2>/dev/null; then
  IS_WSL=1
fi

apt-get update
apt-get install -y apache2 php php-sqlite3 php-zip rsync python3-reportlab python3-pil fonts-dejavu-core

install -d -m 0750 -o root -g www-data "${TARGET_DIR}"
install -d -m 0770 -o www-data -g www-data "${DATA_DIR}" "${DATA_DIR}/uploads"

rsync -a --delete \
  --exclude '/data/' \
  --exclude '/tests/' \
  --exclude '/deploy/' \
  --exclude '__pycache__/' \
  --exclude '/router.php' \
  --exclude '/start-windows.bat' \
  --exclude '/start-macos.command' \
  --exclude '/INSTALL.md' \
  "${SOURCE_DIR}/" "${TARGET_DIR}/"

chown -R root:www-data "${TARGET_DIR}"
find "${TARGET_DIR}" -type d -exec chmod 0750 {} +
find "${TARGET_DIR}" -type f -exec chmod 0640 {} +

install -m 0644 "${SOURCE_DIR}/deploy/apache-trading.conf" "${APACHE_CONF}"
a2enconf trading-journal

apache2ctl configtest

# Ve WSL nemusí běžet systemd, proto se restart dělá tím, co je k dispozici.
if command -v systemctl >/dev/null 2>&1 && systemctl is-system-running >/dev/null 2>&1; then
  systemctl enable apache2 >/dev/null 2>&1 || true
  systemctl reload apache2 || systemctl restart apache2
else
  service apache2 reload || service apache2 start
fi

# Ve WSL se Apache po startu nespouští sám, tohle to zařídí.
if [[ "${IS_WSL}" -eq 1 ]]; then
  if [[ ! -f /etc/wsl.conf ]]; then
    printf '[boot]\ncommand = service apache2 start\n' > /etc/wsl.conf
    echo "Nastaveno automatické spouštění Apache po startu Ubuntu (/etc/wsl.conf)."
  elif ! grep -q 'apache2 start' /etc/wsl.conf; then
    echo
    echo "POZOR: /etc/wsl.conf už existuje, takže jsem ho nepřepsal."
    echo "Aby se Apache spouštěl sám, přidej do něj tyto dva řádky:"
    echo
    echo "  [boot]"
    echo "  command = service apache2 start"
    echo
  fi
fi

if /usr/bin/python3 -c 'import reportlab; from PIL import Image' >/dev/null 2>&1; then
  PDF_STATUS="připravený"
else
  PDF_STATUS="NEDOSTUPNÝ (chybí reportlab nebo pillow, zbytek aplikace funguje)"
fi

echo
echo "Trading Desk je připravený na: http://localhost/trading/"
echo "Data: ${DATA_DIR}"
echo "PDF export: ${PDF_STATUS}"
echo "Kontrola: curl -fsS 'http://localhost/trading/api.php?action=health'"
if [[ "${IS_WSL}" -eq 1 ]]; then
  echo
  echo "Běžíš ve WSL. Pokud jsi teď měnil /etc/wsl.conf, zavři Ubuntu a ve Windows"
  echo "spusť v PowerShellu 'wsl --shutdown', aby se nastavení projevilo."
fi
