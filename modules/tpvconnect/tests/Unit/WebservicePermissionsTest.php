<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/**
 * The TPV's webservice key: coupons created at the till need cart_rules
 * (GET ?schema=blank, POST) and customers created at the till need POST customers.
 */
final class WebservicePermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        \Db::$inserted = [];
        \Db::$permissionRows = [];
    }

    /** @return list<string> "resource METHOD" of the inserted rows for that key */
    private function granted(int $key): array
    {
        $rows = [];
        foreach (\Db::$inserted as $insert) {
            if ($insert['table'] === 'webservice_permission' && $insert['data']['id_webservice_account'] === $key) {
                $rows[] = $insert['data']['resource'] . ' ' . $insert['data']['method'];
            }
        }

        return $rows;
    }

    public function test_the_key_gets_cart_rules_and_customer_creation(): void
    {
        self::assertSame(['GET', 'POST', 'HEAD'], \TpvConnectInstaller::WEBSERVICE_PERMISSIONS['cart_rules']);
        self::assertSame(['GET', 'POST', 'HEAD'], \TpvConnectInstaller::WEBSERVICE_PERMISSIONS['customers']);

        self::assertTrue((new \TpvConnectInstaller(new \Module()))->grantWebservicePermissions(7));

        $granted = $this->granted(7);
        self::assertContains('cart_rules GET', $granted);
        self::assertContains('cart_rules POST', $granted);
        self::assertContains('cart_rules HEAD', $granted);
        self::assertContains('customers POST', $granted);
        self::assertContains('stock_availables PUT', $granted);
        self::assertCount(array_sum(array_map('count', \TpvConnectInstaller::WEBSERVICE_PERMISSIONS)), $granted);
    }

    public function test_an_existing_key_only_gets_the_permissions_it_lacks(): void
    {
        foreach (['products' => ['GET', 'HEAD'], 'customers' => ['GET', 'HEAD'], 'stock_availables' => ['GET', 'PUT', 'HEAD']] as $resource => $methods) {
            foreach ($methods as $method) {
                \Db::$permissionRows[] = ['resource' => $resource, 'method' => $method];
            }
        }

        self::assertTrue((new \TpvConnectInstaller(new \Module()))->grantWebservicePermissions(7));

        $granted = $this->granted(7);
        self::assertNotContains('products GET', $granted);
        self::assertNotContains('customers GET', $granted);
        self::assertContains('customers POST', $granted);
        self::assertContains('cart_rules POST', $granted);
        self::assertCount(array_sum(array_map('count', \TpvConnectInstaller::WEBSERVICE_PERMISSIONS)) - 7, $granted);
    }

    public function test_no_key_gets_nothing(): void
    {
        self::assertTrue((new \TpvConnectInstaller(new \Module()))->grantWebservicePermissions(0));
        self::assertSame([], \Db::$inserted);
    }
}
