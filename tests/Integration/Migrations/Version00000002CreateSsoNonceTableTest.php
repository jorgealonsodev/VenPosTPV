<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Migrations\Version00000002CreateSsoNonceTable;

class Version00000002CreateSsoNonceTableTest extends TestCase
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
            // Force connection to verify driver is available
            $conn->executeQuery('SELECT 1');

            return $conn;
        } catch (\Throwable) {
            return null;
        }
    }

    public function testMigrationCreatesAndDropsSsoNonceTable(): void
    {
        $conn = $this->getConnection();

        if ($conn === null || !$conn->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $migration = new Version00000002CreateSsoNonceTable($conn);
        $schema = new Schema();

        // Ensure clean state
        try {
            $conn->executeStatement('DROP TABLE IF EXISTS sso_nonce');
        } catch (\Throwable) {
            // Table may not exist
        }

        // Run up()
        $migration->up($schema);

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
        $migration->down($schema);

        // Assert table dropped
        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertNotContains('sso_nonce', $tables);
    }
}
