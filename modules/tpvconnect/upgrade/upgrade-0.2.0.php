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
 * Upgrade script for tpvconnect module v0.2.0.
 *
 * The coupon feature is additive and requires no schema or configuration
 * migration. Existing settings, secrets, and order states are preserved.
 */
function upgrade_module_0_2_0(Module $module): bool
{
    return true;
}
