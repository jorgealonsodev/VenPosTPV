<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000050CatalogOverride;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * catalog_override: EAN and reference edited at the till («Modo edición»), kept
 * apart from the synced catalog tables so the sync never overwrites them.
 */
final class Version00000050CatalogOverrideTest extends TestCase
{
    private const PRODUCT_ID = 995901;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
    }

    protected function tearDown(): void
    {
        $this->connection?->executeStatement('DELETE FROM catalog_override WHERE id_product = ?', [self::PRODUCT_ID]);
        $this->connection?->close();
    }

    public static function apply(Connection $connection): void
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000050CatalogOverride.php';
        $migration = new Version00000050CatalogOverride($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    public function testCreatesCatalogOverrideOnceWithOneRowPerItem(): void
    {
        \assert($this->connection !== null);
        self::apply($this->connection);
        self::apply($this->connection); // a second run changes nothing

        $row = ['id_product' => self::PRODUCT_ID, 'id_product_attribute' => 0, 'ean13' => '4006381333931', 'reference' => null, 'updated_by_user_id' => 1, 'updated_at' => '2026-11-09 10:00:00'];
        $this->connection->insert('catalog_override', $row);
        $this->connection->insert('catalog_override', ['id_product_attribute' => 7] + $row);
        self::assertSame('4006381333931', $this->connection->fetchOne('SELECT ean13 FROM catalog_override WHERE id_product = ? AND id_product_attribute = 0', [self::PRODUCT_ID]));
        self::assertNull($this->connection->fetchOne('SELECT reference FROM catalog_override WHERE id_product = ? AND id_product_attribute = 0', [self::PRODUCT_ID]));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('catalog_override', $row);
    }
}
