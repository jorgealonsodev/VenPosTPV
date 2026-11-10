<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000003CreateCatalogTables;
use VenPosTPV\Tests\Support\TestDatabase;

// Doctrine Migrations loads migration files with require_once; they are not in
// the composer PSR-4 map.
require_once \dirname(__DIR__, 3) . '/migrations/Version00000003CreateCatalogTables.php';

class Version00000003CreateCatalogTablesTest extends TestCase
{
    private function getConnection(): \Doctrine\DBAL\Connection|null
    {
        // An empty throwaway database: this test creates and drops whole tables.
        return TestDatabase::scratchConnection();
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

    /**
     * Plans the migration in one direction and executes its SQL, which is what the
     * Doctrine executor does after up()/down() (addSql only plans).
     */
    private function runMigration(\Doctrine\DBAL\Connection $conn, string $direction): void
    {
        $migration = new Version00000003CreateCatalogTables($conn, new NullLogger());
        $direction === 'up' ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $conn->executeStatement((string) $query);
        }
    }

    public function testMigrationCreatesAndDropsCatalogTables(): void
    {
        $conn = $this->getConnection();

        if ($conn === null || !$conn->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $this->dropCatalogTables($conn);

        // Run up()
        $this->runMigration($conn, 'up');

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
        $this->runMigration($conn, 'down');

        // Assert tables dropped
        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertNotContains('catalog_category', $tables);
        $this->assertNotContains('catalog_product', $tables);
        $this->assertNotContains('catalog_combination', $tables);
        $this->assertNotContains('catalog_stock', $tables);
    }
}
