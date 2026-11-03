<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Cash;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
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

    public function testSessionOpenedByAnotherCashierOnTheSameTerminalIsUsable(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);

        $_SESSION = ['shop_id' => 1, 'cash_session_id' => 10];

        $conn->method('fetchAssociative')->willReturn([
            'id' => 10,
            'terminal_id' => 1,
            'opened_at' => '2026-05-09 15:14:46',
            'opening_cash' => 0,
            'user_id' => 2,
            'opened_by' => 'Master Admin',
        ]);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $conn->method('fetchOne')->willReturn(0);

        $service = new CashSessionService($conn, $terminal, new RequestStack());

        // Cashier 1 must be able to work on a drawer cashier 2 opened: a drawer
        // belongs to the terminal, and the movement records who acts.
        $current = $service->getCurrentSession(1);

        $this->assertNotNull($current, 'another cashier\'s open session must not hide the drawer');
        $this->assertSame(10, $current['session_id']);
        $this->assertSame(2, $current['opened_by_user_id']);
        $this->assertSame('Master Admin', $current['opened_by']);

        $context = $service->requireOpenSession(1);
        $this->assertSame(10, $context['session_id']);
        $this->assertSame(1, $context['user_id'], 'the acting cashier is the one using it now');
        $this->assertSame(2, $context['opened_by_user_id']);
    }

    public function testAnotherCashierCanRecordMovementsAndReadTheXReportOfTheTerminalDrawer(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => 10];
        $opened = ['id' => 10, 'terminal_id' => 1, 'opened_at' => '2026-05-09 15:14:46', 'opening_cash' => 500, 'user_id' => 2, 'opened_by' => 'Master Admin'];

        $conn->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $conn->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params = []) use ($opened): array|false {
            // The drawer belongs to the terminal: never require that the acting cashier opened it.
            $this->assertStringNotContainsString('user_id = ?', $sql);

            return $opened;
        });
        $inserted = [];
        $conn->method('insert')->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
            $inserted[] = [$table, $data];

            return 1;
        });
        $conn->method('fetchAllAssociative')->willReturn([]);
        $conn->method('fetchOne')->willReturn(0);

        $service = new CashSessionService($conn, $terminal, new RequestStack());

        $service->addMovement(1, 'sale', 46.28, 'Checkout sale', 25);
        $report = $service->getXReport(1);

        $this->assertSame('movement', $inserted[0][0]);
        $this->assertSame(10, $inserted[0][1]['session_id']);
        $this->assertSame(10, $report['session_id']);
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

        $service = new CashSessionService($conn, $terminal, new RequestStack());
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

        $service = new CashSessionService($conn, $terminal, new RequestStack());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cash session already open for terminal');

        $service->openSession(5, 100.0);
    }

    public function testAddMovementLocksItsOpenSessionInsideTheTransaction(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);
        $_SESSION['cash_session_id'] = 11;

        $session = [
            'id' => 11,
            'terminal_id' => 2,
            'opened_at' => '2026-05-05 10:00:00',
            'opening_cash' => 100,
        ];
        $conn->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) use ($session): array {
                $this->assertStringContainsString('FROM session', $sql);
                $this->assertStringContainsString('FOR UPDATE', $sql);

                return $session;
            });
        $conn->expects($this->once())
            ->method('insert')
            ->with('movement', $this->arrayHasKey('session_id'));
        $conn->method('fetchAllAssociative')->willReturn([]);
        $conn->method('fetchOne')->willReturn(100);

        $service = new CashSessionService($conn, $terminal, new RequestStack());
        $state = $service->addMovement(1, 'cash_in', 25.0, 'Float added');

        $this->assertSame(100.0, $state['expected_cash']);
    }

    public function testCloseSessionLocksBeforeAggregatingAndWritingTheReport(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);
        $_SESSION['cash_session_id'] = 12;

        $session = [
            'id' => 12,
            'terminal_id' => 2,
            'opened_at' => '2026-05-05 10:00:00',
            'opening_cash' => 100,
        ];
        $conn->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) use ($session): array {
                $this->assertStringContainsString('FOR UPDATE', $sql);

                return $session;
            });
        $conn->method('fetchAllAssociative')->willReturn([]);
        $conn->method('fetchOne')->willReturn(100);
        $conn->expects($this->once())->method('update')->with('session', $this->arrayHasKey('closed_at'), ['id' => 12]);
        $conn->expects($this->once())->method('insert')->with('z_report', $this->arrayHasKey('totals'));

        $service = new CashSessionService($conn, $terminal, new RequestStack());
        $closed = $service->closeSession(1, 100.0);

        $this->assertSame(100.0, $closed['expected_cash']);
    }

    public function testCloseSessionKeepsCachedOpenSessionWhenTransactionCommitFails(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);
        $_SESSION['cash_session_id'] = 13;

        $session = [
            'id' => 13,
            'terminal_id' => 2,
            'opened_at' => '2026-05-05 10:00:00',
            'opening_cash' => 100,
        ];
        $conn->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static function (callable $callback): never {
                $callback();
                throw new \RuntimeException('Commit failed');
            });
        $conn->method('fetchAssociative')->willReturn($session);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $conn->method('fetchOne')->willReturn(100);
        $conn->method('update');
        $conn->method('insert');

        $service = new CashSessionService($conn, $terminal, new RequestStack());

        try {
            $service->closeSession(1, 100.0);
            self::fail('Expected the simulated commit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Commit failed', $e->getMessage());
        }

        $this->assertSame(13, $_SESSION['cash_session_id']);
    }

    public function testAddMovementRejectsAnExistingTransactionBeforeDatabaseWork(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);
        $conn->expects($this->once())->method('isTransactionActive')->willReturn(true);
        $conn->expects($this->never())->method('transactional');
        $conn->expects($this->never())->method('fetchAssociative');
        $conn->expects($this->never())->method('insert');

        $service = new CashSessionService($conn, $terminal, new RequestStack());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cash session mutations cannot run inside an existing transaction');

        $service->addMovement(1, 'cash_in', 25.0, 'Float added');
    }

    public function testCloseSessionRejectsAnExistingTransactionBeforeDatabaseWork(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);
        $conn->expects($this->once())->method('isTransactionActive')->willReturn(true);
        $conn->expects($this->never())->method('transactional');
        $conn->expects($this->never())->method('fetchAssociative');
        $conn->expects($this->never())->method('update');
        $conn->expects($this->never())->method('insert');

        $service = new CashSessionService($conn, $terminal, new RequestStack());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cash session mutations cannot run inside an existing transaction');

        $service->closeSession(1, 100.0);
    }

    public function testRequireOpenSessionUsesDedicatedExceptionWhenUserHasNoOpenSession(): void
    {
        $conn = $this->createMock(Connection::class);
        $terminal = $this->createMock(TerminalService::class);
        $conn->method('fetchAssociative')->willReturn(false);

        $service = new CashSessionService($conn, $terminal, new RequestStack());

        $this->expectException(NoOpenCashSessionException::class);
        $this->expectExceptionMessage('No open cash session');

        $service->requireOpenSession(5);
    }
}
