-- =============================================================================
-- Migration 00004: Add stock_sync_interval_seconds to terminal
-- =============================================================================
-- Run: mysql -u root venpostpv < migrations/00004_stock_sync_interval.sql
-- =============================================================================

ALTER TABLE terminal
    ADD COLUMN stock_sync_interval_seconds INT UNSIGNED NOT NULL DEFAULT 60
    AFTER printer_type;
