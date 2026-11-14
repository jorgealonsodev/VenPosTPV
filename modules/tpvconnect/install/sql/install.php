<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

/**
 * SQL installation script for tpvconnect module.
 *
 * Creates a custom module table for tracking order state mappings
 * as a fallback / audit mechanism (order states themselves are
 * created via ObjectModel in install/install.php per NFR-6) and the
 * fiscal-journal copy. Every statement is idempotent: upgrades run it again.
 */

$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'order_state_tpvconnect` (
    `id_tpvconnect_state` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_order_state` INT UNSIGNED NOT NULL,
    `state_key` VARCHAR(32) NOT NULL COMMENT "e.g. TPV_OS_PAID",
    `module_name` VARCHAR(64) NOT NULL DEFAULT "tpvconnect",
    `date_add` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_state_key` (`state_key`),
    KEY `idx_order_state` (`id_order_state`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

// Shop copy of the TPV's fiscal journal (fiscal-journal D6, JournalStore): one row
// per journal line, kept apart per journal_id. The unique keys make a resent
// line a duplicate and, inside one journal, two lines for one position or one
// document a conflict. series and number compare case-sensitively (utf8mb4_bin):
// "a26" is another series than "A26". Never dropped on uninstall (a module «Reset» would erase
// the copy of fiscal documents it exists to protect).
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'tpvconnect_journal` (
    `id_tpvconnect_journal` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `journal_id` CHAR(36) NOT NULL,
    `seq` BIGINT UNSIGNED NOT NULL,
    `series` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `number` VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `invoice_type` CHAR(2) NOT NULL,
    `hash` CHAR(64) NOT NULL,
    `line_hash` CHAR(64) NOT NULL,
    `line` MEDIUMTEXT NOT NULL,
    `date_add` DATETIME NOT NULL,
    UNIQUE KEY `uniq_journal_seq` (`journal_id`, `seq`),
    UNIQUE KEY `uniq_journal_hash` (`journal_id`, `hash`),
    KEY `idx_journal_series` (`journal_id`, `series`, `seq`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

return $sql;
