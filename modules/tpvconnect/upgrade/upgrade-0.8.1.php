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
 * Upgrade script for tpvconnect module v0.8.1.
 *
 * - Registers actionEmailSendBefore: till orders no longer send PrestaShop's own
 *   e-mails (TpvOrderMail). registerHook() is idempotent.
 */
function upgrade_module_0_8_1(Module $module): bool
{
    return (new TpvConnectInstaller($module))->registerHooks();
}
