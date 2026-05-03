<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/**
 * Install/Uninstall lifecycle tests (requires PrestaShop runtime).
 *
 * These are integration-level tests that validate the module's
 * install/uninstall behavior. Run within a PrestaShop environment.
 */
class InstallLifecycleTest extends TestCase
{
    /**
     * @test
     * @group integration
     */
    public function installer_sets_default_configuration(): void
    {
        \Configuration::$values = [];

        $installer = new \TpvConnectInstaller(new \Module());
        $installer->setConfigurationDefaults();

        $this->assertNotEmpty(\Configuration::get('TPVCONNECT_SSO_SECRET'));
        $this->assertSame('', \Configuration::get('TPVCONNECT_URL'));
        $this->assertSame('0', \Configuration::get('TPVCONNECT_WS_KEY_ID'));
    }

    /**
     * @test
     * @group integration
     */
    public function installer_generates_32_char_sso_secret(): void
    {
        \Configuration::$values = [];

        $installer = new \TpvConnectInstaller(new \Module());
        $installer->setConfigurationDefaults();

        $secret = \Configuration::get('TPVCONNECT_SSO_SECRET');
        $this->assertGreaterThanOrEqual(32, strlen($secret));
    }

    /**
     * @test
     * @group integration
     */
    public function uninstaller_removes_configuration_keys(): void
    {
        // Pre-populate configuration
        \Configuration::updateValue('TPVCONNECT_URL', 'https://example.com');
        \Configuration::updateValue('TPVCONNECT_SSO_SECRET', 'secret');
        \Configuration::updateValue('TPV_OS_PAID', '1');
        \Configuration::updateValue('TPVCONNECT_WS_KEY_ID', '5');

        $installer = new \TpvConnectInstaller(new \Module());
        $installer->uninstallConfiguration();

        $this->assertFalse(\Configuration::get('TPVCONNECT_URL'));
        $this->assertFalse(\Configuration::get('TPVCONNECT_SSO_SECRET'));
        $this->assertFalse(\Configuration::get('TPV_OS_PAID'));
        $this->assertFalse(\Configuration::get('TPVCONNECT_WS_KEY_ID'));
    }
}
