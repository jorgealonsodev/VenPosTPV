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
 * Upgrade script for tpvconnect module v0.8.3.
 *
 * - New endpoint POST /module/tpvconnect/price (the TPV's «Modo edición» changes a
 *   product's or combination's price and the product's VAT rate). A front
 *   controller needs no registration: nothing to change in the shop.
 */
function upgrade_module_0_8_3(Module $module): bool
{
    return true;
}
