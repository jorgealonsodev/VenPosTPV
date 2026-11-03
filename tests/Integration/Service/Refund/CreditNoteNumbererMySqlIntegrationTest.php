<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Refund;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Refund\CreditNoteNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

final class CreditNoteNumbererMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->connection->executeStatement('DELETE FROM credit_note_sequence WHERE year = 2091');
    }

    protected function tearDown(): void
    {
        $this->connection?->executeStatement('DELETE FROM credit_note_sequence WHERE year = 2091');
        $this->connection?->close();
    }

    public function testYearRowIsPreparedOutsideTheTransactionSoNumberingOnlyUpdates(): void
    {
        $numberer = new CreditNoteNumberer($this->connection);
        $date = new \DateTimeImmutable('2091-03-01');

        // Creating the year row outside the refund transaction avoids the insert race
        // that deadlocks concurrent first refunds of a year on MariaDB.
        $numberer->prepareYear($date);
        $numberer->prepareYear($date);
        $this->assertSame('0', (string) $this->connection->fetchOne('SELECT last_number FROM credit_note_sequence WHERE year = 2091'));

        $first = $this->connection->transactional(fn (): string => $numberer->next($date));
        $this->connection->beginTransaction();
        $numberer->next($date);
        $this->connection->rollBack();
        $second = $this->connection->transactional(fn (): string => $numberer->next($date));

        $this->assertSame('R2091-000001', $first);
        $this->assertSame('R2091-000002', $second, 'a rolled-back number is given back');
    }

    public function testNumberingOutsideATransactionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new CreditNoteNumberer($this->connection))->next(new \DateTimeImmutable('2091-03-01'));
    }
}
