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
 * Upgrade script for tpvconnect module v0.8.2.
 *
 * - Gives the TPV's existing webservice key read access to the addresses (GET,
 *   HEAD), where the customers' phones are: «Elegir cliente» for WhatsApp offers
 *   them. Rows the key already has are kept.
 */
function upgrade_module_0_8_2(Module $module): bool
{
    return (new TpvConnectInstaller($module))->grantWebservicePermissions((int) Configuration::get('TPVCONNECT_WS_KEY_ID'));
}
