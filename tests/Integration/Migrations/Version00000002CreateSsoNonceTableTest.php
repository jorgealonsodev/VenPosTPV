<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000002CreateSsoNonceTable;
use VenPosTPV\Tests\Support\TestDatabase;

// Doctrine Migrations loads migration files with require_once; they are not in
// the composer PSR-4 map.
require_once \dirname(__DIR__, 3) . '/migrations/Version00000002CreateSsoNonceTable.php';

class Version00000002CreateSsoNonceTableTest extends TestCase
{
    private function getConnection(): \Doctrine\DBAL\Connection|null
    {
        // An empty throwaway database: this test creates and drops whole tables.
        return TestDatabase::scratchConnection();
    }

    /**
     * Plans the migration in one direction and executes its SQL, which is what the
     * Doctrine executor does after up()/down() (addSql only plans).
     */
    private function runMigration(\Doctrine\DBAL\Connection $conn, string $direction): void
    {
        $migration = new Version00000002CreateSsoNonceTable($conn, new NullLogger());
        $direction === 'up' ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $conn->executeStatement((string) $query);
        }
    }

    public function testMigrationCreatesAndDropsSsoNonceTable(): void
    {
        $conn = $this->getConnection();

        if ($conn === null || !$conn->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        // Ensure clean state
        try {
            $conn->executeStatement('DROP TABLE IF EXISTS sso_nonce');
        } catch (\Throwable) {
            // Table may not exist
        }

        // Run up()
        $this->runMigration($conn, 'up');

        // Assert table exists
        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertContains('sso_nonce', $tables);

        // Assert columns
        $table = $conn->createSchemaManager()->introspectTable('sso_nonce');
        $this->assertTrue($table->hasColumn('nonce'));
        $this->assertTrue($table->hasColumn('created_at'));

        $nonceColumn = $table->getColumn('nonce');
        $this->assertSame(64, $nonceColumn->getLength());

        $this->assertTrue($table->hasPrimaryKey());
        $primaryKey = $table->getPrimaryKey();
        $this->assertNotNull($primaryKey);
        $this->assertSame(['nonce'], $primaryKey->getColumns());

        // Run down()
        $this->runMigration($conn, 'down');

        // Assert table dropped
        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertNotContains('sso_nonce', $tables);
    }
}
