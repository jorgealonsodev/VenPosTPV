<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Report;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Report\ReportFilter;
use VenPosTPV\Service\Report\SalesReportService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/informes-ventas.md I2 and acceptance criteria 1, 2, 3, 4, 7 and 8 on the
 * §7 data: Resumen, Por día and Por forma de pago, read from the local database.
 */
final class SalesReportMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private SalesReportFixture $fixture;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixture = new SalesReportFixture($this->connection);
        $this->fixture->load();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixture->clean();
            $this->connection->close();
        }
        $this->connection = null;
        $_SESSION = [];
    }

    private function reports(): SalesReportService
    {
        return new SalesReportService($this->connection);
    }

    /** @param array<string, string> $query */
    private function filter(string $type, array $query): ReportFilter
    {
        return ReportFilter::fromQuery($type, $query);
    }

    /** @param array<string, string> $extra @return array<string, mixed> */
    private function summary(string $from, string $to, array $extra = []): array
    {
        return $this->reports()->build('summary', $this->filter('summary', ['from' => $from, 'to' => $to] + $extra));
    }

    public function testSummaryOfTheTenthMatchesCriterion1(): void
    {
        $report = $this->summary('2026-03-10', '2026-03-10');
        $data = $report['data'];

        $this->assertSame(3, $data['tickets']);
        $this->assertSame(110.0, $data['gross_before_discounts']);
        $this->assertSame(4.0, $data['discounts']);
        $this->assertSame(106.0, $data['gross_sales']);
        $this->assertSame(0.0, $data['refunds']);
        $this->assertSame(106.0, $data['net_sales']);
        $this->assertSame(35.33, $data['average_ticket']);
        $this->assertSame(5, $data['units_sold']);
        $this->assertSame(0, $data['units_refunded']);
        $this->assertSame(5, $data['units_net']);
        $this->assertSame(0.0, $data['exchange_credit']);
        $this->assertSame(0.0, $data['online_refunds']);
        $this->assertSame(['sales' => 0, 'refunds' => 0], $data['pending_upload']);
        $this->assertSame(0.0, $report['approximate_amount']);
        $this->assertSame(0, $report['missing_lines']);
    }

    public function testSummaryOfTheEleventhCountsTheRefundOnItsOwnDayCriterion2(): void
    {
        $data = $this->summary('2026-03-11', '2026-03-11')['data'];

        $this->assertSame(1, $data['tickets']);
        $this->assertSame(25.0, $data['gross_sales']);
        $this->assertSame(25.0, $data['refunds']);
        $this->assertSame(0.0, $data['net_sales']);
        $this->assertSame(1, $data['units_sold']);
        $this->assertSame(1, $data['units_refunded']);
        $this->assertSame(0, $data['units_net']);
        // The tenth does not change because of D1.
        $this->assertSame(106.0, $this->summary('2026-03-10', '2026-03-10')['data']['net_sales']);
        $this->assertSame(0.0, $this->summary('2026-03-10', '2026-03-10')['data']['refunds']);
    }

    public function testSummaryOfTheTwelfthShowsTheExchangeCreditApartCriterion3(): void
    {
        $data = $this->summary('2026-03-12', '2026-03-12')['data'];

        $this->assertSame(40.0, $data['gross_sales']);
        $this->assertSame(36.0, $data['refunds']);
        $this->assertSame(4.0, $data['net_sales']);
        $this->assertSame(36.0, $data['exchange_credit']);
        $this->assertSame(1, $data['tickets']);
    }

    public function testSummaryComparesWithThePreviousPeriodOfTheSameLength(): void
    {
        $data = $this->summary('2026-03-11', '2026-03-11')['data'];

        $this->assertSame(['from' => '2026-03-10', 'to' => '2026-03-10'], ['from' => $data['previous']['from'], 'to' => $data['previous']['to']]);
        $this->assertSame(106.0, $data['previous']['gross_sales']);
        $this->assertSame(['difference' => -81.0, 'percent' => -76.42], $data['comparison']['gross_sales']);
        $this->assertSame(['difference' => -106.0, 'percent' => -100.0], $data['comparison']['net_sales']);
        $this->assertSame(['difference' => 25.0, 'percent' => null], $data['comparison']['refunds'], 'no percentage against 0');
        $this->assertSame(['difference' => -2, 'percent' => -66.67], $data['comparison']['tickets']);

        // A three-day range compares with the three days before it.
        $wide = $this->summary('2026-03-13', '2026-03-15')['data'];
        $this->assertSame(['2026-03-10', '2026-03-12'], [$wide['previous']['from'], $wide['previous']['to']]);
        $this->assertSame(171.0, $wide['previous']['gross_sales']);
        $this->assertSame(110.0, $wide['previous']['net_sales']);
    }

    public function testSummaryFiltersByTillAndCashier(): void
    {
        $tillB = $this->summary('2026-03-10', '2026-03-12', ['terminal_id' => (string) SalesReportFixture::TILL_B])['data'];
        $this->assertSame(2, $tillB['tickets']);
        $this->assertSame(76.0, $tillB['gross_sales']);
        $this->assertSame(36.0, $tillB['refunds']);

        $ana = $this->summary('2026-03-10', '2026-03-12', ['user_id' => (string) SalesReportFixture::ANA])['data'];
        $this->assertSame(3, $ana['tickets']);
        $this->assertSame(95.0, $ana['gross_sales']);
        $this->assertSame(25.0, $ana['refunds']);
        $this->assertSame(5, $ana['units_sold']);
        $this->assertSame(1, $ana['units_refunded']);
    }

    public function testSummaryWarnsAboutSalesWithoutLinesApproximateLinesAndPendingUploads(): void
    {
        $this->fixture->addSaleWithoutLines();
        $this->connection->executeStatement('UPDATE sale_line SET approximate = 1 WHERE order_id = ? AND line_index = 1', [SalesReportFixture::T1]);
        $d1 = (int) $this->connection->fetchOne("SELECT id FROM refund WHERE request_id = 'rpt955-d1'");
        $this->connection->insert('outbox', ['type' => 'sale', 'order_id' => SalesReportFixture::T1, 'sequence' => 1, 'request_id' => 'rpt955-o1', 'payload' => '{}', 'status' => 'pending']);
        $this->connection->insert('outbox', ['type' => 'sale', 'order_id' => SalesReportFixture::T2, 'sequence' => 1, 'request_id' => 'rpt955-o2', 'payload' => '{}', 'status' => 'done']);
        $this->connection->insert('outbox', ['type' => 'refund', 'order_id' => SalesReportFixture::T1, 'sequence' => 2, 'request_id' => 'rpt955-d1', 'payload' => '{}', 'status' => 'incident']);
        $this->assertGreaterThan(0, $d1);

        $tenth = $this->summary('2026-03-10', '2026-03-10');
        $this->assertSame(1, $tenth['missing_lines']);
        $this->assertSame(10.0, $tenth['approximate_amount']);
        $this->assertSame(4, $tenth['data']['tickets'], 'a sale without lines is still a ticket');
        $this->assertSame(['sales' => 1, 'refunds' => 0], $tenth['data']['pending_upload']);

        $eleventh = $this->summary('2026-03-11', '2026-03-11');
        $this->assertSame(0, $eleventh['missing_lines']);
        $this->assertSame(['sales' => 0, 'refunds' => 1], $eleventh['data']['pending_upload']);
    }

    public function testDailyHasOneRowPerDayWithEmptyDaysAtZeroCriterion4(): void
    {
        $report = $this->reports()->build('daily', $this->filter('daily', ['from' => '2026-03-10', 'to' => '2026-03-12']));
        $days = $report['data']['days'];

        $this->assertSame(['2026-03-10', '2026-03-11', '2026-03-12'], array_column($days, 'date'));
        $this->assertSame([106.0, 0.0, 4.0], array_column($days, 'net_sales'));
        $this->assertSame(['date' => '2026-03-10', 'tickets' => 3, 'units' => 5, 'gross_sales' => 106.0, 'refunds' => 0.0, 'net_sales' => 106.0], $days[0]);
        $this->assertSame(['date' => '2026-03-11', 'tickets' => 1, 'units' => 1, 'gross_sales' => 25.0, 'refunds' => 25.0, 'net_sales' => 0.0], $days[1]);
        $this->assertSame(['date' => '2026-03-12', 'tickets' => 1, 'units' => 1, 'gross_sales' => 40.0, 'refunds' => 36.0, 'net_sales' => 4.0], $days[2]);
        $this->assertSame(['tickets' => 5, 'units' => 7, 'gross_sales' => 171.0, 'refunds' => 61.0, 'net_sales' => 110.0], $report['data']['totals']);

        $wider = $this->reports()->build('daily', $this->filter('daily', ['from' => '2026-03-09', 'to' => '2026-03-12']))['data']['days'];
        $this->assertCount(4, $wider);
        $this->assertSame(['date' => '2026-03-09', 'tickets' => 0, 'units' => 0, 'gross_sales' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0], $wider[0]);
    }

    public function testPaymentsOfTheTenthMatchCriterion7(): void
    {
        $report = $this->reports()->build('payments', $this->filter('payments', ['from' => '2026-03-10', 'to' => '2026-03-10']));
        $data = $report['data'];

        $this->assertSame(64.0, $data['sales_cash']);
        $this->assertSame(42.0, $data['sales_card']);
        $this->assertSame(106.0, $data['sales_total']);
        $this->assertSame(0.0, $data['exchange_credit']);
        $this->assertSame(['cash' => 1, 'card' => 1, 'mixed' => 1, 'exchange' => 0], $data['tickets_by_method']);
        $this->assertSame(0.0, $data['refunds_total']);
        $this->assertSame(106.0, $data['net_total']);
        $this->assertNull($data['session']);

        $twelfth = $this->reports()->build('payments', $this->filter('payments', ['from' => '2026-03-11', 'to' => '2026-03-12']))['data'];
        $this->assertSame(25.0, $twelfth['sales_cash']);
        $this->assertSame(4.0, $twelfth['sales_card']);
        $this->assertSame(25.0, $twelfth['refunds_cash']);
        $this->assertSame(36.0, $twelfth['exchange_credit']);
        $this->assertSame(4.0, $twelfth['net_total']);

        $tillA = $this->reports()->build('payments', $this->filter('payments', ['from' => '2026-03-10', 'to' => '2026-03-10', 'terminal_id' => (string) SalesReportFixture::TILL_A]))['data'];
        $this->assertSame(64.0, $tillA['sales_cash']);
        $this->assertSame(6.0, $tillA['sales_card']);
        $this->assertSame(['cash' => 1, 'card' => 0, 'mixed' => 1, 'exchange' => 0], $tillA['tickets_by_method']);
    }

    public function testPaymentsBySessionReproduceTheZOfEachSessionCriterion8(): void
    {
        $cash = new CashSessionService($this->connection, $this->createMock(TerminalService::class), new RequestStack());
        foreach ([SalesReportFixture::SESSION_1, SalesReportFixture::SESSION_2, SalesReportFixture::SESSION_3] as $session) {
            $_SESSION = ['shop_id' => 1, 'cash_session_id' => $session];
            $cash->closeSession(1, 0.0);
        }

        $columns = ['sales_cash', 'sales_card', 'sales_total', 'refunds_cash', 'refunds_card', 'refunds_voucher', 'refunds_total', 'exchange_credit', 'net_total'];
        $reports = [];
        foreach ([SalesReportFixture::SESSION_1, SalesReportFixture::SESSION_2, SalesReportFixture::SESSION_3] as $session) {
            $z = json_decode((string) $this->connection->fetchOne('SELECT totals FROM z_report WHERE session_id = ?', [$session]), true);
            $report = $this->reports()->build('payments', $this->filter('payments', ['session_id' => (string) $session]))['data'];
            foreach ($columns as $column) {
                $this->assertSame((float) $z[$column], $report[$column], sprintf('session %d, %s', $session, $column));
            }
            $this->assertSame($session, $report['session']['session_id']);
            $reports[$session] = $report;
        }

        $this->assertSame(89.0, $reports[SalesReportFixture::SESSION_1]['sales_cash']);
        $this->assertSame(25.0, $reports[SalesReportFixture::SESSION_1]['refunds_cash']);
        $this->assertSame(70.0, $reports[SalesReportFixture::SESSION_1]['net_total']);
        $this->assertSame(36.0, $reports[SalesReportFixture::SESSION_2]['sales_card']);
        $this->assertSame(4.0, $reports[SalesReportFixture::SESSION_3]['sales_card']);
        $this->assertSame(36.0, $reports[SalesReportFixture::SESSION_3]['exchange_credit']);
        $this->assertSame(4.0, $reports[SalesReportFixture::SESSION_3]['net_total']);
    }

    public function testPaymentsOfAnUnknownSessionAreNotFound(): void
    {
        $this->expectException(\VenPosTPV\Service\Report\ReportNotFound::class);

        $this->reports()->build('payments', $this->filter('payments', ['session_id' => '9559']));
    }
}
