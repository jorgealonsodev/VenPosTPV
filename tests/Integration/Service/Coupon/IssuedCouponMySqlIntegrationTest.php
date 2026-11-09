<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Coupon;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Coupon\IssuedCouponStore;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Tests\Integration\Migrations\Version00000048IssuedCouponTest;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * A coupon created from the till is kept in issued_coupon right after PrestaShop
 * confirms it, so its slip is printed from the server's own data.
 */
final class IssuedCouponMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    /** @var list<string> */
    private array $codes = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        // tests/Support/TestDatabase.php does not list Version00000048 yet; the
        // migration is idempotent, so running it here is harmless.
        Version00000048IssuedCouponTest::apply($this->connection);
    }

    protected function tearDown(): void
    {
        foreach ($this->codes as $code) {
            $this->connection?->executeStatement('DELETE FROM issued_coupon WHERE code = ?', [$code]);
        }
        $this->connection?->close();
    }

    public function testCreateIssuedStoresTheCouponPrestaShopConfirmed(): void
    {
        \assert($this->connection !== null);
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            if ($method === 'GET') {
                return new MockResponse('<?xml version="1.0"?><prestashop><cart_rule><name><language id="1"></language></name></cart_rule></prestashop>');
            }
            preg_match('/<code>([^<]+)<\/code>/', (string) $options['body'], $m);

            return new MockResponse('<?xml version="1.0"?><prestashop><cart_rule><id><![CDATA[812]]></id><code><![CDATA[' . $m[1] . ']]></code></cart_rule></prestashop>', ['http_code' => 201]);
        });
        $store = new IssuedCouponStore($this->connection);
        $service = new CouponCreationService('https://shop.test', 'key', $client, 1, 'Europe/Madrid', $store);

        $result = $service->createIssued(['discount_type' => 'percentage', 'value' => 12.5, 'expires_at' => '2030-06-30', 'quantity' => 3, 'quantity_per_user' => 1], 1, 2, 7);
        $this->codes[] = $result['code'];

        self::assertTrue($result['printable']);
        self::assertIsInt($result['local_id']);
        $coupon = $store->find($result['local_id']);
        self::assertNotNull($coupon);
        self::assertSame(812, $coupon->psCartRuleId);
        self::assertSame($result['code'], $coupon->code);
        self::assertSame('percentage', $coupon->discountType);
        self::assertSame(12.5, $coupon->value);
        self::assertSame('2030-06-30', $coupon->expiresAt->format('Y-m-d'));
        self::assertSame(3, $coupon->quantity);
        self::assertSame(1, $coupon->quantityPerUser);
        self::assertSame(2, $coupon->terminalId);
        self::assertSame(7, $coupon->userId);
        self::assertNull($store->find($result['local_id'] + 100000));
    }

    public function testADuplicateCodeIsNotRecordedTwiceAndDoesNotThrow(): void
    {
        \assert($this->connection !== null);
        $store = new IssuedCouponStore($this->connection);
        $this->codes[] = 'DUPL2345';

        self::assertIsInt($store->record(1, 'DUPL2345', 'amount', 5.0, '2030-06-30', 1, 1, null, null));
        self::assertNull($store->record(2, 'DUPL2345', 'amount', 5.0, '2030-06-30', 1, 1, null, null));
    }
}
