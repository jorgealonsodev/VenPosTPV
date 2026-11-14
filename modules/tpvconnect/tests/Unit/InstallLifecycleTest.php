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
     */
    public function install_creates_the_fiscal_journal_table_with_its_unique_keys(): void
    {
        \Db::$executed = [];

        self::assertTrue((new \TpvConnectInstaller(new \Module()))->installSql());

        $created = array_values(array_filter(\Db::$executed, static fn (string $sql): bool => str_contains($sql, 'CREATE TABLE IF NOT EXISTS `ps_tpvconnect_journal`')));
        self::assertCount(1, $created);
        foreach ([
            '`id_tpvconnect_journal`', '`journal_id` CHAR(36) NOT NULL', '`seq` BIGINT UNSIGNED NOT NULL', '`series` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
            '`number` VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', '`invoice_type` CHAR(2) NOT NULL', '`hash` CHAR(64) NOT NULL', '`line_hash` CHAR(64) NOT NULL',
            '`line` MEDIUMTEXT NOT NULL', '`date_add` DATETIME NOT NULL', 'UNIQUE KEY `uniq_journal_seq` (`journal_id`, `seq`)', 'UNIQUE KEY `uniq_journal_hash` (`journal_id`, `hash`)',
        ] as $column) {
            self::assertStringContainsString($column, $created[0]);
        }
    }

    /**
     * @test
     */
    public function uninstall_and_reinstall_keep_the_fiscal_journal_table_and_its_lines(): void
    {
        \Db::$executed = [];
        $installer = new \TpvConnectInstaller(new \Module());

        // A module «Reset»: uninstall, then install.
        $installer->uninstallConfiguration();
        self::assertTrue($installer->installSql());

        $touchingJournal = array_values(array_filter(\Db::$executed, static fn (string $sql): bool => str_contains($sql, 'tpvconnect_journal')));
        self::assertCount(1, $touchingJournal);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `ps_tpvconnect_journal`', $touchingJournal[0]);
        self::assertSame([], array_filter(\Db::$executed, static fn (string $sql): bool => preg_match('/\\b(DROP|TRUNCATE)\\b/i', $sql) === 1));
        self::assertFalse(method_exists($installer, 'uninstallSql'));
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/tpvconnect.php');
        preg_match('/function uninstall\(\): bool\s*\{.*?\n    \}/s', $module, $uninstall);
        self::assertStringNotContainsString('uninstallSql', $uninstall[0] ?? 'missing');
        self::assertStringNotContainsString('tpvconnect_journal', $uninstall[0] ?? 'missing');
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
