<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000048IssuedCoupon;
use VenPosTPV\Tests\Support\TestDatabase;

final class Version00000048IssuedCouponTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000048IssuedCoupon.php';
    }

    protected function tearDown(): void
    {
        $this->connection?->executeStatement("DELETE FROM issued_coupon WHERE code LIKE 'MIG48%'");
        $this->connection?->close();
    }

    public static function apply(Connection $connection): void
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000048IssuedCoupon.php';
        $migration = new Version00000048IssuedCoupon($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    public function testCreatesIssuedCouponOnceWithAUniqueCode(): void
    {
        \assert($this->connection !== null);
        self::apply($this->connection);
        self::apply($this->connection); // a second run changes nothing

        $row = ['ps_cart_rule_id' => 1, 'code' => 'MIG48AAA', 'discount_type' => 'amount', 'value' => '12.50', 'expires_at' => '2030-06-30', 'quantity' => 1, 'quantity_per_user' => 1, 'terminal_id' => null, 'user_id' => null, 'created_at' => '2026-11-09 10:00:00'];
        $this->connection->insert('issued_coupon', $row);
        self::assertSame('12.50', (string) $this->connection->fetchOne("SELECT value FROM issued_coupon WHERE code = 'MIG48AAA'"));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('issued_coupon', $row);
    }
}
