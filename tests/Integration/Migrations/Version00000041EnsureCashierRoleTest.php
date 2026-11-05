<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000041EnsureCashierRole;
use VenPosTPV\Tests\Support\TestDatabase;

final class Version00000041EnsureCashierRoleTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000041EnsureCashierRole.php';
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    private function applyMigration(): void
    {
        \assert($this->connection !== null);
        $migration = new Version00000041EnsureCashierRole($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    public function testCreatesTheCashierRoleOnceAndKeepsAnExistingOne(): void
    {
        \assert($this->connection !== null);
        $hasUsers = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM user u JOIN role r ON r.id = u.role_id WHERE r.name = 'cashier'"
        );
        if ($hasUsers === 0) {
            $this->connection->executeStatement("DELETE FROM role WHERE name = 'cashier'");
        }
        $before = $this->connection->fetchOne("SELECT id FROM role WHERE name = 'cashier'");

        $this->applyMigration();
        $this->applyMigration(); // a second run changes nothing

        $ids = $this->connection->fetchFirstColumn("SELECT id FROM role WHERE name = 'cashier'");
        self::assertCount(1, $ids);
        if ($before !== false) {
            self::assertSame((int) $before, (int) $ids[0]);
        }
    }
}
