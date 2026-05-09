#!/usr/bin/env bash
# Cron setup for VenPosTPV stock auto-sync
# Adds a cron job that syncs stock from PrestaShop every minute.

set -e

PROJECT_DIR="/mnt/480GB/Proyectos/Personales/20260503_VenPosTPV/20260503_VenPosTPV_git/VenPosTPV"
PHP_BIN="/opt/lampp/bin/php"
CONSOLE="${PROJECT_DIR}/bin/console"
LOG_FILE="/tmp/tpv-stock-sync.log"
CRON_LINE="* * * * * ${PHP_BIN} ${CONSOLE} tpv:catalog:sync --stock-only >> ${LOG_FILE} 2>&1"

# Check if the cron job already exists
if crontab -l 2>/dev/null | grep -F "${CONSOLE} tpv:catalog:sync --stock-only" >/dev/null; then
    echo "Cron job already exists. Skipping."
    exit 0
fi

# Add the cron job
(crontab -l 2>/dev/null || true; echo "${CRON_LINE}") | crontab -

echo "Cron job added. Stock will sync every minute."
echo "Logs: ${LOG_FILE}"
