#!/usr/bin/env bash
# Spouštěč Trading Desku pro macOS. Stačí na soubor dvakrát kliknout.
set -uo pipefail
cd "$(dirname "$0")"

PORT=8420

find_php() {
  for candidate in /opt/homebrew/bin/php /usr/local/bin/php "$(command -v php 2>/dev/null || true)"; do
    if [[ -n "${candidate}" && -x "${candidate}" ]]; then
      echo "${candidate}"
      return 0
    fi
  done
  return 1
}

PHP_BIN="$(find_php || true)"
if [[ -z "${PHP_BIN}" ]]; then
  echo
  echo "  ============================================================"
  echo "   PHP nebylo nalezeno."
  echo "  ============================================================"
  echo
  echo "   Otevři soubor INSTALL.md a projdi sekci \"macOS\"."
  echo "   Ve zkratce: nainstaluj Homebrew a pak spusť  brew install php"
  echo
  read -r -p "   Zavři stisknutím Enter." _
  exit 1
fi

if ! "${PHP_BIN}" -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("fileinfo") ? 0 : 1);' 2>/dev/null; then
  echo
  echo "  V PHP chybí rozšíření pdo_sqlite nebo fileinfo."
  echo "  Zkus přeinstalovat PHP:  brew reinstall php"
  echo
  read -r -p "  Zavři stisknutím Enter." _
  exit 1
fi

echo
echo "  ============================================================"
echo "   Trading Desk běží na  http://localhost:${PORT}/"
echo "  ============================================================"
echo
echo "   Tohle okno nechej otevřené, dokud aplikaci používáš."
echo "   Ukončíš ho klávesami Ctrl+C nebo zavřením okna."
echo
echo "   Tvoje data jsou v podadresáři  data/"
echo "   Zálohu stáhneš přímo v aplikaci v sekci \"Záloha\"."
echo

CODE="$("${PHP_BIN}" bin/setup-token.php 2>/dev/null || true)"
if [[ -n "${CODE}" && "${CODE}" != *"existuje"* ]]; then
  echo "   První spuštění: správce založíš kódem  ${CODE}"
  echo
fi

( sleep 2; open "http://localhost:${PORT}/" ) &

exec "${PHP_BIN}" -d upload_max_filesize=20M -d post_max_size=22M -S "localhost:${PORT}" router.php
