<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../../migrations/Version00000016CreateParkedTicketTable.php';

use VenPosTPV\Migrations\Version00000016CreateParkedTicketTable;

class Version00000016CreateParkedTicketTableTest extends TestCase
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

    private function dropTable(\Doctrine\DBAL\Connection $conn): void
    {
        try {
            $conn->executeStatement('DROP TABLE IF EXISTS parked_ticket');
        } catch (\Throwable) {
            // ignore
        }
    }

    public function testMigrationCreatesParkedTicketTableWithIndexes(): void
    {
        $conn = $this->getConnection();

        if ($conn === null || !$conn->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->markTestSkipped('MySQL database not available for integration testing');
        }

        $migration = new Version00000016CreateParkedTicketTable($conn, new NullLogger());
        $schema = new Schema();

        $this->dropTable($conn);

        $migration->up($schema);

        foreach ($migration->getSql() as $query) {
            $conn->executeStatement((string) $query);
        }

        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertContains('parked_ticket', $tables);

        $columns = $conn->executeQuery('DESCRIBE parked_ticket')->fetchAllAssociative();
        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('client_ref', $columnNames);
        $this->assertContains('terminal_id', $columnNames);
        $this->assertContains('user_id', $columnNames);
        $this->assertContains('cashier_name', $columnNames);
        $this->assertContains('note', $columnNames);
        $this->assertContains('customer', $columnNames);
        $this->assertContains('lines', $columnNames);
        $this->assertContains('discount', $columnNames);
        $this->assertContains('total', $columnNames);
        $this->assertContains('created_at', $columnNames);

        $indexes = $conn->executeQuery('SHOW INDEX FROM parked_ticket')->fetchAllAssociative();
        $indexNames = array_column($indexes, 'Key_name');
        $this->assertContains('uq_parked_ticket_client_ref', $indexNames);
        $this->assertContains('idx_parked_ticket_created', $indexNames);
        $this->assertContains('idx_parked_ticket_terminal', $indexNames);

        $migration->down($schema);

        foreach ($migration->getSql() as $query) {
            $conn->executeStatement((string) $query);
        }

        $tables = $conn->createSchemaManager()->listTableNames();
        $this->assertNotContains('parked_ticket', $tables);
    }
}
