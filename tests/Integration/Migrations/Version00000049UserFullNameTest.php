<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000049UserFullName;
use VenPosTPV\Tests\Support\TestDatabase;

final class Version00000049UserFullNameTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000049UserFullName.php';
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            self::apply($this->connection); // leave the shared schema migrated
            $this->connection->close();
        }
    }

    public static function apply(Connection $connection, bool $down = false): void
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version00000049UserFullName.php';
        $migration = new Version00000049UserFullName($connection, new NullLogger());
        $down ? $migration->down(new Schema()) : $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    private function column(): array|false
    {
        \assert($this->connection !== null);

        return $this->connection->fetchAssociative(
            "SELECT IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'full_name'"
        );
    }

    public function testAddsANullableFullNameOnceAndDownDropsIt(): void
    {
        \assert($this->connection !== null);
        self::apply($this->connection);
        self::apply($this->connection); // a second run changes nothing

        $column = $this->column();
        self::assertNotFalse($column);
        self::assertSame('YES', $column['IS_NULLABLE']);
        self::assertSame('varchar', strtolower((string) $column['DATA_TYPE']));
        self::assertSame(128, (int) $column['CHARACTER_MAXIMUM_LENGTH']);

        self::apply($this->connection, down: true);
        self::assertFalse($this->column());
        self::apply($this->connection, down: true); // down is guarded too
        self::assertFalse($this->column());
    }
}
