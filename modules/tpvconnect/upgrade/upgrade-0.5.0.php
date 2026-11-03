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
 * Upgrade script for tpvconnect module v0.5.0.
 *
 * Adds the order state "Devuelto en tienda – reembolso pendiente"
 * (TPV_OS_REFUND_PENDING) used when an online order is refunded at the shop.
 * createOrderStates() is idempotent: states that already exist (by configuration
 * or the fallback tracking table) are reused, so only the new one is created and
 * existing settings, secrets and states are preserved.
 */
function upgrade_module_0_5_0(Module $module): bool
{
    return (new TpvConnectInstaller($module))->createOrderStates();
}
