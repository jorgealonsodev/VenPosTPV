<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Tests\Support\TestDatabase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000018AddPriceNetToCatalogTables;

// Doctrine Migrations loads migration files with require_once and they are not in
// the composer PSR-4 map, so the test loads the file the same way.
require_once \dirname(__DIR__, 3) . '/migrations/Version00000018AddPriceNetToCatalogTables.php';

/**
 * The migration only adds and drops two nullable columns, so the test is
 * idempotent and never touches existing catalog rows: it removes the columns
 * first when a previous run left them behind, then asserts up() and down().
 */
class Version00000018AddPriceNetToCatalogTablesTest extends TestCase
{
    private function getConnection(): ?Connection
    {
        return TestDatabase::connection();
    }

    private function column(Connection $conn, string $table, string $column): ?Column
    {
        $columns = $conn->createSchemaManager()->introspectTable($table)->getColumns();

        return $columns[$column] ?? null;
    }

    private function dropColumnIfExists(Connection $conn, string $table, string $column): void
    {
        if ($this->column($conn, $table, $column) !== null) {
            $conn->executeStatement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }

    /**
     * Runs the migration and executes its planned SQL, which is exactly what the
     * Doctrine executor does after calling up()/down() (addSql only plans).
     */
    private function runMigration(Connection $conn, string $direction): void
    {
        $migration = new Version00000018AddPriceNetToCatalogTables($conn, new NullLogger());
        $schema = new Schema();

        if ($direction === 'up') {
            $migration->up($schema);
        } else {
            $migration->down($schema);
        }

        foreach ($migration->getSql() as $query) {
            $conn->executeStatement((string) $query);
        }
    }

    public function testMigrationAddsAndDropsNullableColumns(): void
    {
        $conn = $this->getConnection();

        if ($conn === null || !$conn->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $tables = $conn->createSchemaManager()->listTableNames();
        if (!\in_array('catalog_product', $tables, true) || !\in_array('catalog_combination', $tables, true)) {
            $this->markTestSkipped('Catalog tables not present; run Version00000003CreateCatalogTables first');
        }

        $this->dropColumnIfExists($conn, 'catalog_product', 'price_net');
        $this->dropColumnIfExists($conn, 'catalog_combination', 'price_net');

        $this->runMigration($conn, 'up');

        $productColumn = $this->column($conn, 'catalog_product', 'price_net');
        $this->assertNotNull($productColumn, 'catalog_product.price_ttc must exist after up()');
        $this->assertFalse($productColumn->getNotnull(), 'price_net must be nullable so a failed price fetch never blocks the sync');
        $this->assertSame('decimal', $productColumn->getType()->getName());
        $this->assertSame(20, $productColumn->getPrecision());
        $this->assertSame(6, $productColumn->getScale());

        $combinationColumn = $this->column($conn, 'catalog_combination', 'price_net');
        $this->assertNotNull($combinationColumn, 'catalog_combination.price_ttc must exist after up()');
        $this->assertFalse($combinationColumn->getNotnull());
        $this->assertSame('decimal', $combinationColumn->getType()->getName());
        $this->assertSame(20, $combinationColumn->getPrecision());
        $this->assertSame(6, $combinationColumn->getScale());

        // Existing data and the base price columns must survive untouched.
        $this->assertNotNull($this->column($conn, 'catalog_product', 'price'));
        $this->assertNotNull($this->column($conn, 'catalog_combination', 'price_impact'));

        // install.sql declares the same columns for fresh installations, so running
        // the migration on top of an already complete schema must be a no-op.
        $this->runMigration($conn, 'up');
        $this->assertNotNull($this->column($conn, 'catalog_product', 'price_net'), 'up() must be idempotent');
        $this->assertNotNull($this->column($conn, 'catalog_combination', 'price_net'), 'up() must be idempotent');

        $this->runMigration($conn, 'down');

        $this->assertNull($this->column($conn, 'catalog_product', 'price_net'), 'up() must be reversible');
        $this->assertNull($this->column($conn, 'catalog_combination', 'price_net'), 'up() must be reversible');
        $this->assertNotNull($this->column($conn, 'catalog_product', 'price'));
        $this->assertNotNull($this->column($conn, 'catalog_combination', 'price_impact'));

        // Leave the schema migrated: the application needs the columns, so a test
        // run must not end by removing them.
        $this->runMigration($conn, 'up');
        $this->assertNotNull($this->column($conn, 'catalog_product', 'price_net'));
        $this->assertNotNull($this->column($conn, 'catalog_combination', 'price_net'));
    }
}
