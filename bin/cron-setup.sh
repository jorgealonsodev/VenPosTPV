#!/usr/bin/env bash
# Cron setup for VenPosTPV
# Adds the single scheduled task: tpv:cron every minute. It launches what is due
# (sales upload and stock every minute; catalog, customers and backup every night).

set -e

PROJECT_DIR="/mnt/480GB/Proyectos/Personales/20260503_VenPosTPV/20260503_VenPosTPV_git/VenPosTPV"
PHP_BIN="/opt/lampp/bin/php"
CONSOLE="${PROJECT_DIR}/bin/console"
LOG_FILE="/tmp/tpv-cron.log"
CRON_LINE="* * * * * ${PHP_BIN} ${CONSOLE} tpv:cron --env=prod >> ${LOG_FILE} 2>&1"

# Check if the cron job already exists
if crontab -l 2>/dev/null | grep -F "${CONSOLE} tpv:cron" >/dev/null; then
    echo "Cron job already exists. Skipping."
    exit 0
fi

# Add the cron job
(crontab -l 2>/dev/null || true; echo "${CRON_LINE}") | crontab -

echo "Cron job added. tpv:cron runs every minute."
echo "Logs: ${LOG_FILE}"
