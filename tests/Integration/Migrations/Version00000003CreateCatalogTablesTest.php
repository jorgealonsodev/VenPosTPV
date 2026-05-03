<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Migrations\Version00000003CreateCatalogTables;

class Version00000003CreateCatalogTablesTest extends TestCase
{
    private function getConnection(): \Doctrine\DBAL\Connection|null
    {
        $config = [
            'driver' => 'pdo_mysql',
            'host' => $_ENV['TPV_DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['TPV_DB_PORT'] ?? 3306),
            'dbname' => $_ENV['TPV_DB_NAME'] ?? 'tpv',
            'user' => $_ENV['TPV_DB_USER'] ?? 'tpv_user',
            'password' => $_ENV['TPV_DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
        ];

        try {
            $conn = DriverManager::getConnection($config);
            $conn->executeQuery('SELECT 1');

            return $conn;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dropCatalogTables(\Doctrine\DBAL\Connection $conn): void
    {
        foreach (['catalog_stock', 'catalog_combination', 'catalog_product', 'catalog_category'] as $table) {
            try {
                $conn->executeStatement("DROP TABLE IF EXISTS {$table}");
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public function testMigrationCreatesAndDropsCatalogTables(): void
    {
        $conn = $this->getConnection();

        if ($conn === null || !$conn->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $migration = new Version00000003CreateCatalogTables($conn);
        $schema = new Schema();

        $this->dropCatalogTables($conn);

        // Run up()
        $migration->up($schema);

        // Assert all 4 tables exist
        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertContains('catalog_category', $tables);
        $this->assertContains('catalog_product', $tables);
        $this->assertContains('catalog_combination', $tables);
        $this->assertContains('catalog_stock', $tables);

        // Verify catalog_product columns
        $productTable = $conn->createSchemaManager()->introspectTable('catalog_product');
        $this->assertTrue($productTable->hasColumn('id'));
        $this->assertTrue($productTable->hasColumn('name'));
        $this->assertTrue($productTable->hasColumn('reference'));
        $this->assertTrue($productTable->hasColumn('ean13'));
        $this->assertTrue($productTable->hasColumn('price'));
        $this->assertTrue($productTable->hasColumn('id_category_default'));
        $this->assertTrue($productTable->hasColumn('active'));
        $this->assertTrue($productTable->hasColumn('synced_at'));

        // Verify catalog_stock composite PK
        $stockTable = $conn->createSchemaManager()->introspectTable('catalog_stock');
        $this->assertTrue($stockTable->hasPrimaryKey());
        $pk = $stockTable->getPrimaryKey();
        $this->assertNotNull($pk);
        $this->assertSame(['id_product', 'id_product_attribute'], $pk->getColumns());

        // Verify indexes on catalog_product
        $this->assertTrue($productTable->hasIndex('idx_name'));
        $this->assertTrue($productTable->hasIndex('idx_ean13'));
        $this->assertTrue($productTable->hasIndex('idx_active'));

        // Run down()
        $migration->down($schema);

        // Assert tables dropped
        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertNotContains('catalog_category', $tables);
        $this->assertNotContains('catalog_product', $tables);
        $this->assertNotContains('catalog_combination', $tables);
        $this->assertNotContains('catalog_stock', $tables);
    }
}
