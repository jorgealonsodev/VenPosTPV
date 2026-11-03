<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Sale;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VenPosTPV\Migrations\Version00000030AddTicketNumbering;
use VenPosTPV\Tests\Support\TestDatabase;

final class TicketNumberingMigrationMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    /** @var list<int> */
    private array $terminalIds = [];

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            foreach ($this->terminalIds as $id) {
                $this->connection->executeStatement('DELETE FROM terminal WHERE id = ?', [$id]);
            }
            // Leave the schema as the rest of the suite expects it.
            $this->migrate();
            $this->connection->close();
        }
    }

    public function testExistingTerminalsGetLettersByIdAndRunningTwiceChangesNothing(): void
    {
        $this->removeTicketNumbering();
        for ($i = 0; $i < 2; ++$i) {
            $this->connection->insert('terminal', ['ps_shop_id' => 1, 'name' => 'Migration letter test']);
            $this->terminalIds[] = (int) $this->connection->lastInsertId();
        }

        $this->migrate();

        $rows = $this->connection->fetchAllAssociative('SELECT id, ticket_letter FROM terminal ORDER BY id');
        $this->assertGreaterThanOrEqual(2, \count($rows));
        foreach (\array_slice($rows, 0, 26) as $position => $row) {
            $this->assertSame(\chr(\ord('A') + $position), $row['ticket_letter'], 'terminal ' . $row['id']);
        }
        $this->assertSame(1, $this->uniqueLetterIndexes());
        $this->assertSame(['letter', 'year', 'last_number'], $this->sequenceColumns());
        $this->assertSame(['letter', 'year'], $this->sequencePrimaryKey());

        $this->migrate();

        $this->assertSame($rows, $this->connection->fetchAllAssociative('SELECT id, ticket_letter FROM terminal ORDER BY id'));
        $this->assertSame(1, $this->uniqueLetterIndexes());
    }

    public function testRerunGivesFreeLettersOnlyToTerminalsWithoutOne(): void
    {
        $this->migrate();
        $this->connection->insert('terminal', ['ps_shop_id' => 1, 'name' => 'Migration letter test']);
        $id = (int) $this->connection->lastInsertId();
        $this->terminalIds[] = $id;
        $before = $this->connection->fetchAllKeyValue('SELECT id, ticket_letter FROM terminal WHERE id <> ? ORDER BY id', [$id]);

        $this->migrate();

        $after = $this->connection->fetchAllKeyValue('SELECT id, ticket_letter FROM terminal WHERE id <> ? ORDER BY id', [$id]);
        $this->assertSame($before, $after, 'existing letters never change');
        $letter = $this->connection->fetchOne('SELECT ticket_letter FROM terminal WHERE id = ?', [$id]);
        if (\count(array_filter($before)) < 26) {
            $this->assertNotContains($letter, $before);
            $this->assertMatchesRegularExpression('/^[A-Z]$/', (string) $letter);
        } else {
            $this->assertNull($letter, 'no letters left');
        }
    }

    private function migrate(): void
    {
        // Doctrine Migrations loads these files itself; they are not in the PSR-4 map.
        require_once \dirname(__DIR__, 4) . '/migrations/Version00000030AddTicketNumbering.php';

        $migration = new Version00000030AddTicketNumbering($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement((string) $query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function removeTicketNumbering(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ticket_sequence');
        if ($this->uniqueLetterIndexes() > 0) {
            $this->connection->executeStatement('ALTER TABLE terminal DROP INDEX uk_terminal_ticket_letter');
        }
        $hasColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = 'ticket_letter'"
        ) > 0;
        if ($hasColumn) {
            $this->connection->executeStatement('ALTER TABLE terminal DROP COLUMN ticket_letter');
        }
    }

    private function uniqueLetterIndexes(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminal' AND COLUMN_NAME = 'ticket_letter' AND NON_UNIQUE = 0"
        );
    }

    /** @return list<string> */
    private function sequenceColumns(): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_sequence' ORDER BY ORDINAL_POSITION"
        );
    }

    /** @return list<string> */
    private function sequencePrimaryKey(): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_sequence' AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX"
        );
    }
}
