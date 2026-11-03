<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Cash;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Tests\Support\TestDatabase;

final class CashReportByMethodMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9201;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => 1, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        // Sales: 50 cash, 30 card, 40 mixed (10 cash + 30 card), and a legacy card sale of 20 without tender.
        $sales = [
            [920001, 'cash', ['cash' => 50, 'card' => 0], 50],
            [920002, 'card', ['cash' => 0, 'card' => 30], 30],
            [920003, 'mixed', ['cash' => 10, 'card' => 30], 40],
            [920004, 'card', null, 20],
        ];
        foreach ($sales as [$id, $method, $tender, $total]) {
            $c->insert('order_meta', ['ps_order_id' => $id, 'terminal_id' => 1, 'session_id' => self::SESSION_ID, 'user_id' => 1,
                'payment_breakdown' => json_encode(array_filter(['reference' => 'R' . $id, 'total' => $total, 'payment_method' => $method, 'tender' => $tender], static fn ($v) => $v !== null))]);
            if ($tender !== null && $tender['cash'] > 0) {
                $c->insert('movement', ['session_id' => self::SESSION_ID, 'type' => 'sale', 'amount' => $tender['cash'], 'ps_order_id' => $id]);
            }
        }

        // Refunds in this session: 12 cash; 8 card; 5 cash + 7 card; a failed one that must not count.
        foreach ([['done', 12, 0], ['done', 0, 8], ['done', 5, 7], ['failed', 99, 99]] as $i => [$status, $cash, $card]) {
            $c->insert('refund', ['request_id' => 'cashreport' . $i, 'status' => $status, 'ps_order_id' => 920001, 'terminal_id' => 1,
                'session_id' => self::SESSION_ID, 'user_id' => 1, 'reason_code' => 'size', 'total' => $cash + $card, 'cash_amount' => $cash, 'card_amount' => $card]);
            if ($status === 'done' && $cash > 0) {
                $c->insert('movement', ['session_id' => self::SESSION_ID, 'type' => 'refund', 'amount' => $cash, 'ps_order_id' => 920001]);
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
    }

    private function clean(): void
    {
        $this->connection->executeStatement("DELETE FROM refund WHERE request_id LIKE 'cashreport%'");
        $this->connection->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $this->connection->executeStatement('DELETE FROM order_meta WHERE ps_order_id BETWEEN 920001 AND 920004');
        $this->connection->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
    }

    public function testXReportShowsRefundsByMethodSeparatelyWithNetAndCashDrawerUnchanged(): void
    {
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION_ID];
        $service = new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack());

        $report = $service->getXReport(1);
        $totals = $report['totals'];

        $this->assertSame(60.0, $totals['sales_cash']);
        $this->assertSame(80.0, $totals['sales_card']);
        $this->assertSame(140.0, $totals['sales_total']);
        $this->assertSame(17.0, $totals['refunds_cash']);
        $this->assertSame(15.0, $totals['refunds_card']);
        $this->assertSame(32.0, $totals['refunds_total']);
        $this->assertSame(108.0, $totals['net_total']);
        $this->assertSame(3, $totals['counts']['refunds']);
        // Drawer: 100 opening + 60 cash sales - 17 cash refunds.
        $this->assertSame(143.0, $totals['expected_cash']);
        $this->assertSame(15.0, $report['refunds_card']);
        $this->assertSame(108.0, $report['net_total']);
    }
}
