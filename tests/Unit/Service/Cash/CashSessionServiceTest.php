<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Cash;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;

final class CashSessionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        $_SESSION = ['shop_id' => 1];
    }

    public function testCurrentSessionAggregatesExpectedCashFormula(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);

        $_SESSION['cash_session_id'] = 11;

        $conn->method('fetchAssociative')->willReturn([
            'id' => 11,
            'terminal_id' => 2,
            'opened_at' => '2026-05-05 10:00:00',
            'opening_cash' => 100,
        ]);

        $conn->method('fetchAllAssociative')->willReturn([
            ['type' => 'sale', 'amount' => 50, 'cnt' => 1],
            ['type' => 'refund', 'amount' => 10, 'cnt' => 1],
            ['type' => 'cash_in', 'amount' => 20, 'cnt' => 1],
            ['type' => 'cash_out', 'amount' => 5, 'cnt' => 1],
        ]);
        $conn->method('fetchOne')->willReturn(100);

        $service = new CashSessionService($conn, $terminal);
        $current = $service->getCurrentSession(1);

        $this->assertNotNull($current);
        $this->assertSame(155.0, $current['expected_cash']);
    }

    public function testOpenSessionRejectsDuplicateOpenForResolvedTerminal(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);

        $terminal->expects($this->once())
            ->method('resolveForShop')
            ->with(1)
            ->willReturn(9);

        $conn->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());

        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                'SELECT id FROM session WHERE terminal_id = ? AND closed_at IS NULL LIMIT 1',
                [9]
            )
            ->willReturn('33');

        $conn->expects($this->never())->method('insert');

        $service = new CashSessionService($conn, $terminal);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cash session already open for terminal');

        $service->openSession(5, 100.0);
    }
}
