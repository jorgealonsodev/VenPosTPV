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

        self::assertSame('0.3.0', $this->declaredPhpVersion($php));
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
}
