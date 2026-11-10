<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Coupon;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Coupon\CouponListService;
use VenPosTPV\Service\Coupon\IssuedCouponStore;
use VenPosTPV\Service\PrestaShop\CouponStatusLookup;

/**
 * «Cupones»: the coupons created from the till (issued_coupon), newest first, with
 * who created them and their state in PrestaShop (available, used, expired…).
 */
final class CouponListServiceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE user (id INTEGER PRIMARY KEY, name VARCHAR(128), full_name VARCHAR(128))');
        $this->connection->executeStatement('CREATE TABLE terminal (id INTEGER PRIMARY KEY, name VARCHAR(64))');
        $this->connection->executeStatement('CREATE TABLE issued_coupon (id INTEGER PRIMARY KEY AUTOINCREMENT, ps_cart_rule_id INT, code VARCHAR(32), discount_type VARCHAR(16), value DECIMAL(10,2), expires_at DATE, quantity INT, quantity_per_user INT, terminal_id INT NULL, user_id INT NULL, created_at DATETIME)');
        $this->connection->insert('user', ['id' => 9, 'name' => 'ana', 'full_name' => 'Ana García']);
        $this->connection->insert('user', ['id' => 8, 'name' => 'luis', 'full_name' => null]);
        $this->connection->insert('terminal', ['id' => 3, 'name' => 'Tienda']);
        $store = new IssuedCouponStore($this->connection);
        $store->record(101, 'TPV-AAAA', 'amount', 20.0, '2027-01-31', 1, 1, 3, 9);
        $store->record(102, 'TPV-BBBB', 'percentage', 10.0, '2026-01-31', 5, 1, null, 8);
        $store->record(103, 'TPV-CCCC', 'amount', 5.5, '2027-06-30', 1, 1, 3, null);
        // Created in that order: AAAA oldest, CCCC newest.
        $this->connection->executeStatement("UPDATE issued_coupon SET created_at = '2026-11-01 10:00:00' WHERE code = 'TPV-AAAA'");
        $this->connection->executeStatement("UPDATE issued_coupon SET created_at = '2026-11-02 10:00:00' WHERE code = 'TPV-BBBB'");
        $this->connection->executeStatement("UPDATE issued_coupon SET created_at = '2026-11-03 10:00:00' WHERE code = 'TPV-CCCC'");
    }

    /** @param list<string> $urls */
    private function service(string|\Throwable $psAnswer, array &$urls = []): CouponListService
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($psAnswer, &$urls): MockResponse {
            $urls[] = urldecode($url);
            if ($psAnswer instanceof \Throwable) {
                throw $psAnswer;
            }

            return new MockResponse($psAnswer);
        });

        return new CouponListService(
            new IssuedCouponStore($this->connection),
            new CouponStatusLookup('https://shop.example.com', 'KEY', $http),
            'Europe/Madrid',
        );
    }

    public function testCouponsComeNewestFirstWithTheirStateInPrestaShop(): void
    {
        $urls = [];
        $ps = json_encode(['cart_rules' => [
            ['id' => 103, 'quantity' => '1', 'active' => '1', 'date_to' => '2027-06-30 23:59:59'],
            ['id' => 102, 'quantity' => '3', 'active' => '1', 'date_to' => '2026-01-31 23:59:59'],
            ['id' => 101, 'quantity' => '0', 'active' => '1', 'date_to' => '2027-01-31 23:59:59'],
        ]]);

        $page = $this->service($ps, $urls)->list(null, 1, new \DateTimeImmutable('2026-11-10 12:00:00', new \DateTimeZone('Europe/Madrid')));

        self::assertSame(['TPV-CCCC', 'TPV-BBBB', 'TPV-AAAA'], array_column($page['data'], 'code'));
        self::assertSame(['available', 'expired', 'used'], array_column($page['data'], 'status'));
        self::assertFalse($page['has_more']);
        self::assertTrue($page['status_checked']);
        $first = $page['data'][0];
        self::assertSame(['id' => 3, 'ps_cart_rule_id' => 103, 'code' => 'TPV-CCCC', 'type' => 'amount', 'value' => 5.5,
            'expires_at' => '2027-06-30', 'created_at' => '2026-11-03 10:00:00', 'cashier' => null, 'terminal' => 'Tienda',
            'status' => 'available', 'uses_left' => 1], $first);
        // The full name is the cashier, else the user name.
        self::assertSame('luis', $page['data'][1]['cashier']);
        self::assertSame('Ana García', $page['data'][2]['cashier']);
        self::assertSame(3, $page['data'][1]['uses_left']);
        // One request for the whole page, only the fields it needs.
        self::assertCount(1, $urls);
        self::assertStringContainsString('/api/cart_rules?', $urls[0]);
        self::assertStringContainsString('filter[id]=[103|102|101]', $urls[0]);
        self::assertStringContainsString('display=[id,quantity,active,date_from,date_to,reduction_amount,reduction_percent,reduction_tax,partial_use]', $urls[0]);
    }

    public function testADisabledOrDeletedCartRuleSaysSo(): void
    {
        $ps = json_encode(['cart_rules' => [
            ['id' => 103, 'quantity' => '1', 'active' => '0', 'date_to' => '2027-06-30 23:59:59'],
        ]]);

        $page = $this->service($ps)->list(null, 1, new \DateTimeImmutable('2026-11-10 12:00:00'));

        self::assertSame(['disabled', 'deleted', 'deleted'], array_column($page['data'], 'status'));
        self::assertNull($page['data'][1]['uses_left']);
    }

    public function testWithoutPrestaShopTheListStillShowsAndOnlyTheLocalExpiryIsKnown(): void
    {
        $page = $this->service(new \RuntimeException('down'))->list(null, 1, new \DateTimeImmutable('2026-11-10 12:00:00'));

        self::assertSame(['unknown', 'expired', 'unknown'], array_column($page['data'], 'status'));
        self::assertFalse($page['status_checked']);
        self::assertSame([null, null, null], array_column($page['data'], 'uses_left'));
    }

    public function testSearchFindsPartOfTheCodeIgnoringCase(): void
    {
        $page = $this->service('[]')->list('bbb', 1, new \DateTimeImmutable('2026-11-10 12:00:00'));

        self::assertSame(['TPV-BBBB'], array_column($page['data'], 'code'));
        // PrestaShop answers [] when no cart rule matches the filter.
        self::assertSame(['deleted'], array_column($page['data'], 'status'));
    }

    public function testPagesOfTwentyFive(): void
    {
        $store = new IssuedCouponStore($this->connection);
        for ($i = 0; $i < 30; ++$i) {
            $store->record(200 + $i, sprintf('TPV-X%03d', $i), 'amount', 1.0, '2027-01-01', 1, 1, null, null);
        }
        $service = $this->service('[]');
        $now = new \DateTimeImmutable('2026-11-10 12:00:00');

        $first = $service->list(null, 1, $now);
        $second = $service->list(null, 2, $now);

        self::assertCount(25, $first['data']);
        self::assertTrue($first['has_more']);
        self::assertCount(8, $second['data']);
        self::assertFalse($second['has_more']);
        self::assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
    }
}
