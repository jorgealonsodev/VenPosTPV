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
 * as a fallback / audit mechanism. Order states themselves are
 * created via ObjectModel in install/install.php per NFR-6.
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

return $sql;
