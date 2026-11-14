<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/../install/install.php';

/**
 * Upgrade script for tpvconnect module v0.9.0.
 *
 * - Creates the table PREFIX_tpvconnect_journal, the shop copy of the TPV's fiscal
 *   journal written by the new endpoint POST /module/tpvconnect/journal.
 *   installSql() runs the install statements, all CREATE TABLE IF NOT EXISTS: the
 *   existing order state tracking table and its rows are kept.
 */
function upgrade_module_0_9_0(Module $module): bool
{
    return (new TpvConnectInstaller($module))->installSql();
}
