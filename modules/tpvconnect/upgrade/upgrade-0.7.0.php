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
 * Upgrade script for tpvconnect module v0.7.0.
 *
 * - Registers actionPaymentModuleProductVarTplAfter (a free item's concept in the
 *   order e-mail). registerHook() is idempotent.
 * - Gives the TPV's existing webservice key the permissions it lacks (POST
 *   customers, GET/POST/HEAD cart_rules), keeping the key itself and its rows.
 *
 * The generic free item products are not created here: the TPV creates them
 * through POST /module/tpvconnect/freeitem with the VAT rates it offers, and a
 * shop without the needed tax rules group must not make the upgrade fail.
 */
function upgrade_module_0_7_0(Module $module): bool
{
    $installer = new TpvConnectInstaller($module);

    return $installer->registerHooks()
        && $installer->grantWebservicePermissions((int) Configuration::get('TPVCONNECT_WS_KEY_ID'));
}
