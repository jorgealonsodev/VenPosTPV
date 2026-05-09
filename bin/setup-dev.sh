#!/usr/bin/env bash
# =============================================================================
# VenPosTPV — Dev Environment Setup
# =============================================================================
# Idempotent: safe to run multiple times.
# Creates tables (if not exists) and inserts seed data (INSERT IGNORE).
#
# Usage: bin/setup-dev.sh
# =============================================================================

set -euo pipefail

MYSQL="/opt/lampp/bin/mysql"
DB="venpostpv"
MYSQL_OPTS="-u root"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "=== VenPosTPV Dev Setup ==="
echo ""

# --- 1. Ensure database exists ---
echo "→ Ensuring database '${DB}' exists..."
"${MYSQL}" ${MYSQL_OPTS} -e "CREATE DATABASE IF NOT EXISTS \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
  && echo "  ✓ Database ready" \
  || { echo "  ✗ Failed to create database"; exit 1; }

# --- 2. Run migrations ---
echo "→ Running migrations/install.sql..."
"${MYSQL}" ${MYSQL_OPTS} "${DB}" < "${ROOT_DIR}/migrations/install.sql" \
  && echo "  ✓ Migrations applied" \
  || { echo "  ✗ Migration failed"; exit 1; }

# --- 3. Run seed data ---
echo "→ Inserting dev seed data (bin/seed-dev.sql)..."
"${MYSQL}" ${MYSQL_OPTS} "${DB}" < "${SCRIPT_DIR}/seed-dev.sql" \
  && echo "  ✓ Seed data inserted" \
  || { echo "  ✗ Seed failed"; exit 1; }

echo ""
echo "=== Setup complete! ==="
echo ""
echo "  DB    : ${DB}"
echo "  User  : María García  (id=1)"
echo "  PIN   : 1234"
echo "  Role  : admin (id=1)"
echo "  Terminal: Terminal 1 (id=1, ps_shop_id=1)"
