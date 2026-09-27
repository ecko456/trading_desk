#!/usr/bin/env bash
# Spustí Trading Desk v GitHub Codespace. Volá se sám při každém startu Codespace.
set -uo pipefail
cd "$(dirname "$0")/.."

PORT=8420
LOG=/tmp/trading-desk.log
HEALTH="http://127.0.0.1:${PORT}/api.php?action=health"

if curl -fsS "${HEALTH}" >/dev/null 2>&1; then
  echo "Trading Desk už běží na portu ${PORT}."
  exit 0
fi

# Server musí přežít konec tohoto skriptu, proto vlastní session a nohup.
setsid nohup php -d upload_max_filesize=20M -d post_max_size=22M \
  -S "127.0.0.1:${PORT}" router.php >"${LOG}" 2>&1 </dev/null &

for _ in $(seq 1 30); do
  if curl -fsS "${HEALTH}" >/dev/null 2>&1; then
    echo "Trading Desk běží. Otevři kartu PORTS a port ${PORT} (Trading Desk)."
    echo "Data: $(pwd)/data"
    exit 0
  fi
  sleep 0.5
done

echo "Trading Desk se nepodařilo spustit. Log: ${LOG}"
cat "${LOG}"
exit 1
