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
 * Upgrade script for tpvconnect module v0.8.4.
 *
 * - New endpoint POST /module/tpvconnect/codes (the TPV's «Modo edición» writes the
 *   EAN and reference of a product or combination). A front controller needs no
 *   registration: nothing to change in the shop.
 */
function upgrade_module_0_8_4(Module $module): bool
{
    return true;
}
