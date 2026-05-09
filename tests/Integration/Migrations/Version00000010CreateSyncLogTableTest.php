<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
require_once __DIR__ . '/../../../migrations/Version00000010CreateSyncLogTable.php';
use VenPosTPV\Migrations\Version00000010CreateSyncLogTable;

class Version00000010CreateSyncLogTableTest extends TestCase
{
    private function getConnection(): ?\Doctrine\DBAL\Connection
    {
        $config = [
            'driver' => 'pdo_mysql',
            'host' => getenv('TPV_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TPV_DB_PORT') ?: 3306),
            'dbname' => getenv('TPV_DB_NAME') ?: 'tpv',
            'user' => getenv('TPV_DB_USER') ?: 'tpv_user',
            'password' => getenv('TPV_DB_PASSWORD') ?: '',
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

    private function dropSyncLogTable(\Doctrine\DBAL\Connection $conn): void
    {
        try {
            $conn->executeStatement('DROP TABLE IF EXISTS sync_log');
        } catch (\Throwable) {
            // ignore
        }
    }

    public function testMigrationCreatesSyncLogTableWithIndexes(): void
    {
        $conn = $this->getConnection();

        if ($conn === null || !$conn->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $migration = new Version00000010CreateSyncLogTable($conn, new NullLogger());
        $schema = new Schema();

        $this->dropSyncLogTable($conn);

        $migration->up($schema);

        foreach ($migration->getSql() as $query) {
            $conn->executeStatement((string) $query);
        }

        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertContains('sync_log', $tables);

        $columns = $conn->executeQuery('DESCRIBE sync_log')->fetchAllAssociative();
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('type', $columnNames);
        $this->assertContains('direction', $columnNames);
        $this->assertContains('synced_at', $columnNames);
        $this->assertContains('row_count', $columnNames);
        $this->assertContains('created_at', $columnNames);

        $indexes = $conn->executeQuery('SHOW INDEX FROM sync_log')->fetchAllAssociative();
        $indexNames = array_column($indexes, 'Key_name');
        $this->assertContains('uk_type_direction', $indexNames);
        $this->assertContains('idx_type_direction', $indexNames);
        $this->assertContains('idx_synced_at', $indexNames);

        $migration->down($schema);

        foreach ($migration->getSql() as $query) {
            $conn->executeStatement((string) $query);
        }

        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertNotContains('sync_log', $tables);
    }
}
