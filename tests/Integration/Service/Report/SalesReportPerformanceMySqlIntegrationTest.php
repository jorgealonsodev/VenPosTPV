<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Report;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Report\ReportFilter;
use VenPosTPV\Service\Report\SalesReportService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/informes-ventas.md I6 and criterion 14, optional (TPV_PERF_TEST=1): with
 * the reference volume (one year, 20 000 tickets, 60 000 lines, 2 000 refunds) after a
 * year of older sales, every report of the year answers in < 1 s, the products CSV in
 * < 3 s, and the range queries use the date indexes instead of scanning order_meta.
 */
final class SalesReportPerformanceMySqlIntegrationTest extends TestCase
{
    private const FIRST_ID = 9700001;
    private const TICKETS_PER_YEAR = 20000;
    private const REFUNDS = 2000;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('TPV_PERF_TEST') !== '1') {
            $this->markTestSkipped('Performance test: run with TPV_PERF_TEST=1');
        }
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $this->generate();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function clean(): void
    {
        $c = $this->connection;
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->executeStatement("DELETE rl FROM refund_line rl INNER JOIN refund r ON r.id = rl.refund_id WHERE r.request_id LIKE 'perf970-%'");
        $c->executeStatement("DELETE FROM refund WHERE request_id LIKE 'perf970-%'");
        $c->executeStatement('DELETE FROM sale_line WHERE order_id BETWEEN 9700001 AND 9799999');
        $c->executeStatement('DELETE FROM order_meta WHERE id BETWEEN 9700001 AND 9799999');
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** 2025 (older year) and 2026 (the year reported), 20 000 tickets of 3 lines each per year. */
    private function generate(): void
    {
        $c = $this->connection;
        $c->executeStatement('SET SESSION cte_max_recursion_depth = 100000');
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $tickets = 2 * self::TICKETS_PER_YEAR;
        // One ticket every ~26 minutes, 2025-01-01 to 2026-12-31.
        $c->executeStatement(
            "INSERT INTO order_meta (id, request_id, terminal_id, session_id, user_id, payment_breakdown, created_at)
             WITH RECURSIVE seq (n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < ? - 1)
             SELECT ? + n, CONCAT('perf970-', n), 9551 + n % 2, 9551, 9551 + n % 2,
                    JSON_OBJECT('total', 30, 'payment_method', IF(n % 3 = 0, 'card', 'cash'), 'tender', JSON_OBJECT('cash', IF(n % 3 = 0, 0, 30), 'card', IF(n % 3 = 0, 30, 0))),
                    TIMESTAMP('2025-01-01 00:00:00') + INTERVAL FLOOR(n * 1576.8) SECOND
             FROM seq",
            [$tickets, self::FIRST_ID]
        );
        $c->executeStatement(
            "INSERT INTO sale_line (order_id, line_index, sold_at, terminal_id, session_id, user_id, product_id, id_product_attribute, reference, name,
                                    category_id, category_name, quantity, unit_price, gross_amount, paid_amount, vat_rate, base_amount, vat_amount, approximate)
             SELECT o.id, k.i, o.created_at, o.terminal_id, o.session_id, o.user_id, 9700 + (o.id * 7 + k.i) % 500, 0, CONCAT('P', (o.id * 7 + k.i) % 500),
                    CONCAT('Producto ', (o.id * 7 + k.i) % 500), 1 + (o.id + k.i) % 20, CONCAT('Categoría ', 1 + (o.id + k.i) % 20), 1, 10.00, 10.00, 10.00, 21.00, 8.26, 1.74, 0
             FROM order_meta o CROSS JOIN (SELECT 0 AS i UNION ALL SELECT 1 UNION ALL SELECT 2) k
             WHERE o.id BETWEEN 9700001 AND 9799999"
        );
        // 2 000 refunds of 2026, each returning the first line of a 2026 ticket.
        $c->executeStatement(
            "INSERT INTO refund (request_id, status, order_id, terminal_id, session_id, user_id, reason_code, total, cash_amount, card_amount, created_at, completed_at)
             SELECT CONCAT('perf970-r', o.id), 'done', o.id, o.terminal_id, 9551, o.user_id, 'size', 10.00, 10.00, 0, o.created_at + INTERVAL 1 DAY, o.created_at + INTERVAL 1 DAY
             FROM order_meta o WHERE o.id BETWEEN ? AND 9799999 AND o.id % 10 = 0 LIMIT " . self::REFUNDS,
            [self::FIRST_ID + self::TICKETS_PER_YEAR]
        );
        $c->executeStatement(
            "INSERT INTO refund_line (refund_id, line_index, product_id, id_product_attribute, name, quantity, amount)
             SELECT r.id, 0, sl.product_id, 0, sl.name, 1, 10.00 FROM refund r INNER JOIN sale_line sl ON sl.order_id = r.order_id AND sl.line_index = 0
             WHERE r.request_id LIKE 'perf970-r%'"
        );
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (['order_meta', 'sale_line', 'refund', 'refund_line'] as $table) {
            $c->fetchAllAssociative('ANALYZE TABLE ' . $table);
        }
    }

    public function testEveryReportOfAYearAnswersInUnderASecondAndTheCsvInUnderThree(): void
    {
        $this->assertSame(60000, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM sale_line WHERE sold_at >= '2026-01-01' AND sold_at < '2027-01-01' AND order_id >= 9700001"));
        $this->assertSame(self::REFUNDS, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM refund WHERE request_id LIKE 'perf970-r%'"));

        $service = new SalesReportService($this->connection);
        foreach (SalesReportService::TYPES as $type) {
            $filter = ReportFilter::fromQuery($type, ['from' => '2026-01-01', 'to' => '2026-12-31']);
            $start = hrtime(true);
            $report = $service->build($type, $filter);
            $seconds = (hrtime(true) - $start) / 1e9;
            $this->assertLessThan(1.0, $seconds, sprintf('%s of a year took %.3f s', $type, $seconds));
            $this->assertArrayHasKey('data', $report);
        }
        $summary = $service->build('summary', ReportFilter::fromQuery('summary', ['from' => '2026-01-01', 'to' => '2026-12-31']))['data'];
        $this->assertGreaterThanOrEqual(self::TICKETS_PER_YEAR, $summary['tickets']);

        $start = hrtime(true);
        $csv = $service->build('products', ReportFilter::fromQuery('products', ['from' => '2026-01-01', 'to' => '2026-12-31', 'format' => 'csv']));
        $seconds = (hrtime(true) - $start) / 1e9;
        $this->assertLessThan(3.0, $seconds, sprintf('products CSV took %.3f s', $seconds));
        $this->assertGreaterThanOrEqual(500, \count($csv['data']['rows']));
    }

    public function testTheRangeQueriesUseTheDateIndexes(): void
    {
        $range = ['from' => '2026-01-01 00:00:00', 'to' => '2027-01-01 00:00:00'];
        $plans = [
            'order_meta' => ['SELECT COUNT(*) FROM order_meta o WHERE o.created_at >= :from AND o.created_at < :to', 'o', 'idx_order_meta_created'],
            'sale_line' => ['SELECT SUM(l.paid_amount) FROM sale_line l WHERE l.sold_at >= :from AND l.sold_at < :to', 'l', 'idx_sale_line_sold'],
            'sale_line by product' => ['SELECT l.product_id, SUM(l.paid_amount) FROM sale_line l WHERE l.sold_at >= :from AND l.sold_at < :to GROUP BY l.product_id', 'l', 'idx_sale_line_sold'],
            'refund' => ["SELECT SUM(r.total) FROM refund r WHERE r.status = 'done' AND r.completed_at >= :from AND r.completed_at < :to", 'r', 'idx_refund_status_completed'],
        ];
        foreach ($plans as $name => [$sql, $alias, $index]) {
            $rows = $this->connection->fetchAllAssociative('EXPLAIN ' . $sql, $range);
            $row = array_values(array_filter($rows, static fn (array $r): bool => $r['table'] === $alias))[0];
            // The WHERE is sargable: the date index is a candidate, whatever the optimizer picks for a whole year.
            $this->assertContains($index, explode(',', (string) $row['possible_keys']), $name);
        }

        // order_meta is never scanned in full, even for a year: a date index answers it.
        $explain = $this->connection->fetchAllAssociative('EXPLAIN SELECT COUNT(*) FROM order_meta o WHERE o.created_at >= :from AND o.created_at < :to', $range)[0];
        $this->assertNotSame('ALL', $explain['type']);
        $this->assertContains($explain['key'], ['idx_order_meta_created', 'idx_order_meta_terminal_created']);

        // A month (a selective range) reads only through the date indexes.
        $month = ['from' => '2026-03-01 00:00:00', 'to' => '2026-04-01 00:00:00'];
        foreach ($plans as $name => [$sql, $alias, $index]) {
            $row = array_values(array_filter($this->connection->fetchAllAssociative('EXPLAIN ' . $sql, $month), static fn (array $r): bool => $r['table'] === $alias))[0];
            $this->assertSame('range', $row['type'], $name);
            $this->assertSame($index, $row['key'], $name);
        }
    }
}
