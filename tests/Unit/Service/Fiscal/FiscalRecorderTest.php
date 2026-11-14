<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;

final class FiscalRecorderTest extends TestCase
{
    public function testASaleGoesOnWhenTheFiscalTablesAreNotMigratedYetAndTheGapIsLogged(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('fetchAssociative')->willThrowException(new TableNotFoundException($this->createMock(DriverException::class), null));
        $conn->expects($this->never())->method('insert');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('without a fiscal record'), $this->callback(
            static fn (array $context): bool => $context['number'] === 'A26000001'
        ));

        (new FiscalRecorder($conn, $logger))->recordSale(1, 1, 'A26000001', 10.0, 1.74, new \DateTimeImmutable());
    }

    /** fiscal-journal D5: under the chain lock, before the hash, the intent and the insert. */
    public function testTheGuardRefusesUnderTheChainLockAndNothingIsWritten(): void
    {
        $calls = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('fetchAssociative')->willReturnCallback(static function (string $sql) use (&$calls): array {
            $calls[] = $sql;

            return ['last_hash' => str_repeat('A', 64)];
        });
        $conn->expects($this->never())->method('insert');
        $conn->expects($this->never())->method('update');
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->expects($this->once())->method('assertCanIssue')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'guard';

            throw new FiscalJournalBehindException(FiscalJournalGuard::BEHIND_JOURNAL, 'A26000005');
        });
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->expects($this->never())->method('prepared');

        try {
            (new FiscalRecorder($conn, journal: $intents, guard: $guard))->recordSale(1, 1, 'A26000006', 10.0, 1.74, new \DateTimeImmutable());
            $this->fail('the issue must be refused');
        } catch (FiscalJournalBehindException $e) {
            $this->assertSame('A26000005', $e->missingNumber);
        }
        $this->assertSame(['SELECT last_hash FROM fiscal_chain WHERE id = 1 FOR UPDATE', 'guard'], $calls, 'checked once the chain is locked');
    }
}
