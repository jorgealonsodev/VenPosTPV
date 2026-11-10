<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** tpvconnect 0.8.2: the upgrade gives an existing TPV key read access to the addresses. */
final class AddressesUpgradeTest extends TestCase
{
    public function test_the_0_8_2_upgrade_grants_the_missing_permissions_to_the_existing_key(): void
    {
        $upgrade = (string) file_get_contents(__DIR__ . '/../../upgrade/upgrade-0.8.2.php');
        self::assertStringContainsString('function upgrade_module_0_8_2(Module $module): bool', $upgrade);
        self::assertStringContainsString("grantWebservicePermissions((int) Configuration::get('TPVCONNECT_WS_KEY_ID'))", $upgrade);
    }
}
