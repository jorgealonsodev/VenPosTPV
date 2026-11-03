<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade script for tpvconnect module v0.3.0.
 *
 * The refund rework is code-only: refund replay results are stored as
 * configuration values on demand, so no schema or setting changes are needed.
 * Existing settings, secrets, and order states are preserved.
 */
function upgrade_module_0_3_0(Module $module): bool
{
    return true;
}
