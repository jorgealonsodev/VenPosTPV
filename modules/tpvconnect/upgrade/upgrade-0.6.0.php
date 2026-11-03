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
 * Upgrade script for tpvconnect module v0.6.0.
 *
 * Registers actionGenerateDocumentReference, which gives orders the TPV creates
 * its own ticket code (e.g. A26000123) as reference. registerHook() is
 * idempotent, so the hooks already registered are kept; settings, secrets and
 * order states are not touched.
 */
function upgrade_module_0_6_0(Module $module): bool
{
    return (new TpvConnectInstaller($module))->registerHooks();
}
