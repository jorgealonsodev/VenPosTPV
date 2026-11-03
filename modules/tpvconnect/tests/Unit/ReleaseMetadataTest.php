<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

class ReleaseMetadataTest extends TestCase
{
    public function test_public_module_versions_are_consistent_and_newer_than_0_1_0(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $php = (string) file_get_contents($moduleRoot . '/tpvconnect.php');
        $xml = (string) file_get_contents($moduleRoot . '/config.xml');

        self::assertSame('0.6.1', $this->declaredPhpVersion($php));
        self::assertSame($this->declaredPhpVersion($php), $this->declaredXmlVersion($xml));
        self::assertTrue(version_compare($this->declaredPhpVersion($php), '0.1.0', '>'));
    }

    private function declaredPhpVersion(string $php): string
    {
        preg_match('/[$]this->version\s*=\s*\'([^\']+)\';/', $php, $matches);

        return $matches[1] ?? '';
    }

    private function declaredXmlVersion(string $xml): string
    {
        preg_match('/<version><!\[CDATA\[([^]]+)\]\]><\/version>/', $xml, $matches);

        return $matches[1] ?? '';
    }

    public function test_0_2_0_upgrade_is_a_configuration_preserving_no_op(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        \Configuration::$values = [
            'TPVCONNECT_URL' => 'https://tpv.example.test',
            'TPVCONNECT_SSO_SECRET' => 'existing-secret',
            'TPV_OS_PAID' => '42',
        ];
        $before = \Configuration::$values;

        require_once $moduleRoot . '/upgrade/upgrade-0.2.0.php';

        self::assertTrue(function_exists('upgrade_module_0_2_0'));
        self::assertTrue(upgrade_module_0_2_0(new \Module()));
        self::assertSame($before, \Configuration::$values);
    }

    public function test_0_3_0_upgrade_is_a_configuration_preserving_no_op(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        \Configuration::$values = ['TPVCONNECT_SSO_SECRET' => 'existing-secret', 'TPV_OS_REFUND' => '43'];
        $before = \Configuration::$values;

        require_once $moduleRoot . '/upgrade/upgrade-0.3.0.php';

        self::assertTrue(upgrade_module_0_3_0(new \Module()));
        self::assertSame($before, \Configuration::$values);
    }

    public function test_0_4_0_upgrade_is_a_configuration_preserving_no_op(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        \Configuration::$values = ['TPVCONNECT_SSO_SECRET' => 'existing-secret', 'TPV_OS_REFUND' => '43'];
        $before = \Configuration::$values;

        require_once $moduleRoot . '/upgrade/upgrade-0.4.0.php';

        self::assertTrue(upgrade_module_0_4_0(new \Module()));
        self::assertSame($before, \Configuration::$values);
    }

    public function test_0_5_0_upgrade_creates_the_pending_web_refund_state_once_and_keeps_the_rest(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        \OrderState::$states = [43 => ['name' => [1 => 'Devolución TPV']], 44 => [], 45 => []];
        \Configuration::$values = ['TPVCONNECT_SSO_SECRET' => 'existing-secret', 'TPV_OS_PAID' => 44, 'TPV_OS_REFUND' => 43, 'TPV_OS_PENDING_SYNC' => 45];

        require_once $moduleRoot . '/upgrade/upgrade-0.5.0.php';

        self::assertTrue(upgrade_module_0_5_0(new \Module()));
        $pendingId = (int) \Configuration::$values['TPV_OS_REFUND_PENDING'];
        self::assertGreaterThan(45, $pendingId);
        self::assertSame('Devuelto en tienda – reembolso pendiente', \OrderState::$states[$pendingId]['name'][1]);
        self::assertSame(['TPVCONNECT_SSO_SECRET' => 'existing-secret', 'TPV_OS_PAID' => 44, 'TPV_OS_REFUND' => 43, 'TPV_OS_PENDING_SYNC' => 45], array_diff_key(\Configuration::$values, ['TPV_OS_REFUND_PENDING' => true]));

        // Running it again (or reinstalling) reuses the state instead of adding another.
        $count = \count(\OrderState::$states);
        self::assertTrue(upgrade_module_0_5_0(new \Module()));
        self::assertSame($count, \count(\OrderState::$states));
        self::assertSame($pendingId, (int) \Configuration::$values['TPV_OS_REFUND_PENDING']);
    }

    public function test_0_6_0_upgrade_registers_the_reference_hook_and_keeps_the_configuration(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        \Configuration::$values = ['TPVCONNECT_SSO_SECRET' => 'existing-secret', 'TPV_OS_PAID' => 44];
        $before = \Configuration::$values;
        $module = new \Module();

        require_once $moduleRoot . '/upgrade/upgrade-0.6.0.php';

        self::assertTrue(upgrade_module_0_6_0($module));
        self::assertContains('actionGenerateDocumentReference', $module->registeredHooks);
        self::assertSame($before, \Configuration::$values);
    }

    public function test_changelog_documents_the_current_version(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');

        self::assertStringContainsString("## 0.6.1\n", $changelog);
        self::assertStringContainsString("## 0.6.0\n", $changelog);
    }
}
