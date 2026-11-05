<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Fiscal\FiscalRecorder;

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
}
