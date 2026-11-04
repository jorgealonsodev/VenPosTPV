<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000039PublicProductImageUrls;
use VenPosTPV\Tests\Support\TestDatabase;

final class Version00000039PublicProductImageUrlsTest extends TestCase
{
    private const IDS = [93901, 93902, 93903];

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000039PublicProductImageUrls.php';
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
    }

    private function clean(): void
    {
        $this->connection?->executeStatement('DELETE FROM catalog_product WHERE id IN (?, ?, ?)', self::IDS);
    }

    private function insert(int $id, ?string $imageUrl): void
    {
        $this->connection?->insert('catalog_product', ['id' => $id, 'name' => 'P' . $id, 'price' => 1, 'image_url' => $imageUrl]);
    }

    private function applyMigration(): void
    {
        \assert($this->connection !== null);
        $migration = new Version00000039PublicProductImageUrls($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    public function testStoredWebserviceUrlsLoseTheKeyAndOthersAreUntouched(): void
    {
        $this->insert(93901, 'https://shop.example.com/api/images/products/93901/123?ws_key=SECRET');
        $this->insert(93902, 'https://shop.example.com/img/p/4/5/45-home_default.jpg');
        $this->insert(93903, null);

        $this->applyMigration();
        $this->applyMigration(); // a second run changes nothing

        \assert($this->connection !== null);
        $rows = $this->connection->fetchAllKeyValue('SELECT id, image_url FROM catalog_product WHERE id IN (?, ?, ?) ORDER BY id', self::IDS);
        self::assertSame([
            93901 => 'https://shop.example.com/img/p/1/2/3/123-home_default.jpg',
            93902 => 'https://shop.example.com/img/p/4/5/45-home_default.jpg',
            93903 => null,
        ], $rows);
    }
}
