<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Report;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Report\ReportFilter;
use VenPosTPV\Service\Report\SalesReportService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/informes-ventas.md I2 and acceptance criteria 5, 6 and 9 on the §7 data:
 * Por hora, Por producto (with «Sin ventas», free items and pages of 50), Por
 * categoría, Por cajero and IVA; plus «Por forma de pago» by dates and till with
 * vouchers and exchange vouchers.
 */
final class SalesReportDetailMySqlIntegrationTest extends TestCase
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
        $this->fixture->addCatalog();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixture->clean();
            $this->connection->close();
        }
        $this->connection = null;
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{data: array<string, mixed>, approximate_amount: float, missing_lines: int}
     */
    private function report(string $type, string $from, string $to, array $extra = []): array
    {
        return (new SalesReportService($this->connection))->build($type, ReportFilter::fromQuery($type, ['from' => $from, 'to' => $to] + $extra));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<list<mixed>>
     */
    private function pick(array $rows, string ...$keys): array
    {
        return array_map(static fn (array $row): array => array_map(static fn (string $key): mixed => $row[$key], $keys), $rows);
    }

    public function testHourlyHas24SlotsWithTicketsAndGrossCriterion5(): void
    {
        $data = $this->report('hourly', '2026-03-10', '2026-03-10')['data'];

        $this->assertSame(range(0, 23), array_column($data['hours'], 'hour'));
        $byHour = array_column($data['hours'], null, 'hour');
        $this->assertSame(['hour' => 9, 'tickets' => 1, 'gross_sales' => 60.0], $byHour[9]);
        $this->assertSame(['hour' => 18, 'tickets' => 1, 'gross_sales' => 36.0], $byHour[18]);
        $this->assertSame(['hour' => 23, 'tickets' => 1, 'gross_sales' => 10.0], $byHour[23]);
        foreach ($byHour as $hour => $row) {
            if (!\in_array($hour, [9, 18, 23], true)) {
                $this->assertSame(['hour' => $hour, 'tickets' => 0, 'gross_sales' => 0.0], $row);
            }
        }
        $this->assertSame(['tickets' => 3, 'gross_sales' => 106.0], $data['totals']);
        $this->assertSame(['2026-03-10', '2026-03-10'], [$data['from'], $data['to']]);

        // All the days of the range add up in the same slot; the till filter applies.
        $tillA = $this->report('hourly', '2026-03-10', '2026-03-12', ['terminal_id' => (string) SalesReportFixture::TILL_A])['data'];
        $this->assertSame(['hour' => 0, 'tickets' => 1, 'gross_sales' => 25.0], $tillA['hours'][0]);
        $this->assertSame(0, $tillA['hours'][18]['tickets']);
        $this->assertSame(['tickets' => 3, 'gross_sales' => 95.0], $tillA['totals']);
    }

    public function testProductsOfTheTenthAndEleventhMatchCriterion6(): void
    {
        $report = $this->report('products', '2026-03-10', '2026-03-11');
        $data = $report['data'];

        $this->assertSame(
            [
                [SalesReportFixture::SHIRT[0], 0, 'CAM-01', 'Camiseta', false, 3, 1, 2, 75.0, 25.0, 50.0, 47.17],
                [SalesReportFixture::SWIMSUIT[0], 0, 'BAN-01', 'Bañador', false, 1, 0, 1, 36.0, 0.0, 36.0, 33.96],
                [SalesReportFixture::HAT[0], 0, 'GOR-01', 'Gorro', false, 2, 0, 2, 20.0, 0.0, 20.0, 18.87],
            ],
            $this->pick($data['rows'], 'product_id', 'id_product_attribute', 'reference', 'name', 'free_item', 'units_sold', 'units_refunded', 'units_net',
                'sales_amount', 'refunds_amount', 'net_amount', 'percent')
        );
        $this->assertSame(['units_sold' => 6, 'units_refunded' => 1, 'units_net' => 5, 'sales_amount' => 131.0, 'refunds_amount' => 25.0, 'net_amount' => 106.0], $data['totals']);
        $this->assertSame(['view' => 'sold', 'sort' => 'amount', 'group' => 'combination'], ['view' => $data['view'], 'sort' => $data['sort'], 'group' => $data['group']]);
        $this->assertSame(['cursor' => '0', 'size' => 50, 'total_rows' => 3, 'next_cursor' => null, 'previous_cursor' => null], $data['page']);
        $this->assertSame(0.0, $report['approximate_amount']);
        $this->assertSame(0, $report['missing_lines']);
    }

    public function testProductsSortByNetUnitsAndCountRefundsWithoutTicketAndProductsOnlyRefunded(): void
    {
        // Libro was never sold; its refund without ticket still makes a row.
        $this->fixture->addRefundWithoutTicket('rpt955-r-book', '2026-03-11 12:00:00', SalesReportFixture::TILL_A, SalesReportFixture::SESSION_1,
            SalesReportFixture::ANA, SalesReportFixture::BOOK, 11.0);

        $data = $this->report('products', '2026-03-10', '2026-03-11', ['sort' => 'units'])['data'];

        $this->assertSame('units', $data['sort']);
        $this->assertSame(
            [['Camiseta', 2, 50.0], ['Gorro', 2, 20.0], ['Bañador', 1, 36.0], ['Libro', -1, -11.0]],
            $this->pick($data['rows'], 'name', 'units_net', 'net_amount'),
            'net units, then amount'
        );
        $this->assertSame(['LIB-01', 0, 1, 0.0, 11.0], [$data['rows'][3]['reference'], $data['rows'][3]['units_sold'], $data['rows'][3]['units_refunded'],
            $data['rows'][3]['sales_amount'], $data['rows'][3]['refunds_amount']]);
        $this->assertSame(95.0, $data['totals']['net_amount']);
    }

    public function testProductsGroupFreeItemsByNameAndMayGroupCombinationsByProduct(): void
    {
        $this->fixture->addSale(SalesReportFixture::EXTRA_SALE, '2026-03-10 12:00:00', [
            [[null, 'Cuota club', 15.0], 1, 15.0],
            [[SalesReportFixture::SHIRT[0], 'Camiseta (Talla: M)', 25.0], 1, 25.0],
        ]);
        $this->fixture->addSale(SalesReportFixture::EXTRA_SALE + 1, '2026-03-10 12:30:00', [
            [[null, 'Cuota club', 15.0], 2, 30.0],
            [[null, 'Arreglo', 5.0], 1, 5.0],
        ]);
        $this->connection->executeStatement('UPDATE sale_line SET id_product_attribute = 7 WHERE order_id = ? AND line_index = 1', [SalesReportFixture::EXTRA_SALE]);

        $rows = $this->report('products', '2026-03-10', '2026-03-10')['data']['rows'];
        $this->assertSame(
            [
                [SalesReportFixture::SHIRT[0], 0, 'Camiseta', false, 2, 50.0],
                ['free', null, 'Cuota club', true, 3, 45.0],
                [SalesReportFixture::SWIMSUIT[0], 0, 'Bañador', false, 1, 36.0],
                [SalesReportFixture::SHIRT[0], 7, 'Camiseta (Talla: M)', false, 1, 25.0],
                [SalesReportFixture::HAT[0], 0, 'Gorro', false, 2, 20.0],
                ['free', null, 'Arreglo', true, 1, 5.0],
            ],
            array_map(static fn (array $r): array => [$r['product_id'] ?? 'free', $r['id_product_attribute'], $r['name'], $r['free_item'], $r['units_sold'], $r['net_amount']], $rows)
        );
        $this->assertNull($rows[1]['reference']);

        // By product: the combinations of Camiseta add up, under the catalog name.
        $byProduct = $this->report('products', '2026-03-10', '2026-03-10', ['group' => 'product'])['data'];
        $this->assertSame('product', $byProduct['group']);
        $this->assertSame(
            [['Camiseta', null, 3, 75.0], ['Cuota club', null, 3, 45.0], ['Bañador', null, 1, 36.0], ['Gorro', null, 2, 20.0], ['Arreglo', null, 1, 5.0]],
            $this->pick($byProduct['rows'], 'name', 'id_product_attribute', 'units_sold', 'net_amount')
        );
        $this->assertSame('CAM-01', $byProduct['rows'][0]['reference']);
    }

    public function testProductsArePagedBy50WithACursorAndTheCsvKeepsEveryRow(): void
    {
        $lines = [];
        for ($i = 1; $i <= 55; ++$i) {
            $lines[] = [[null, sprintf('Libre %02d', $i), 1.0], 1, 1.0];
        }
        $this->fixture->addSale(SalesReportFixture::EXTRA_SALE, '2026-03-10 13:00:00', $lines);

        $first = $this->report('products', '2026-03-10', '2026-03-10')['data'];
        $this->assertCount(50, $first['rows']);
        $this->assertSame(['cursor' => '0', 'size' => 50, 'total_rows' => 58, 'next_cursor' => '50', 'previous_cursor' => null], $first['page']);
        $this->assertSame(161.0, $first['totals']['net_amount'], 'the totals are of every row, not the page');

        $second = $this->report('products', '2026-03-10', '2026-03-10', ['cursor' => '50'])['data'];
        $this->assertCount(8, $second['rows']);
        $this->assertSame(['cursor' => '50', 'size' => 50, 'total_rows' => 58, 'next_cursor' => null, 'previous_cursor' => '0'], $second['page']);
        $this->assertSame([], array_intersect(array_column($first['rows'], 'name'), array_column($second['rows'], 'name')));

        $csv = $this->report('products', '2026-03-10', '2026-03-10', ['format' => 'csv', 'cursor' => '50'])['data'];
        $this->assertCount(58, $csv['rows'], 'a CSV export has every row');
        $this->assertNull($csv['page']['next_cursor']);
    }

    public function testUnsoldListsActiveCatalogProductsWithoutLinesInTheRange(): void
    {
        // Every row (as the CSV), so other catalog products of the database never push these off the page.
        $data = $this->report('products', '2026-03-11', '2026-03-11', ['view' => 'unsold', 'format' => 'csv'])['data'];

        $this->assertSame('unsold', $data['view']);
        // 11/03 only sold Camiseta (T4); Antiguo is inactive.
        $this->assertSame(
            [[SalesReportFixture::SWIMSUIT[0], 'BAN-01', 'Bañador', 'Ropa'], [SalesReportFixture::GOGGLES[0], 'GAF-01', 'Gafas', 'Accesorios'],
                [SalesReportFixture::HAT[0], 'GOR-01', 'Gorro', 'Accesorios'], [SalesReportFixture::BOOK[0], 'LIB-01', 'Libro', 'Libros']],
            array_values(array_filter(
                $this->pick($data['rows'], 'product_id', 'reference', 'name', 'category_name'),
                static fn (array $row): bool => $row[0] >= 955101 && $row[0] <= 955199
            ))
        );
        $ids = array_column($data['rows'], 'product_id');
        $this->assertNotContains(SalesReportFixture::SHIRT[0], $ids);
        $this->assertNotContains(955106, $ids, 'inactive products are not listed');
        $this->assertSame($data['page']['total_rows'], $data['totals']['products']);

        // The till filter applies: till B sold nothing on the 11th.
        $tillB = $this->report('products', '2026-03-11', '2026-03-11', ['view' => 'unsold', 'format' => 'csv', 'terminal_id' => (string) SalesReportFixture::TILL_B])['data'];
        $this->assertContains(SalesReportFixture::SHIRT[0], array_column($tillB['rows'], 'product_id'));
    }

    public function testCategoriesTakeTheSaleLineCategoryAndTheCatalogOneForRefundsWithoutTicket(): void
    {
        // Camiseta moves to «Accesorios» after it was sold: D1 (with ticket) stays in
        // «Ropa»; a refund without ticket of a Camiseta takes the current category.
        $this->connection->executeStatement('UPDATE catalog_product SET id_category_default = ? WHERE id = ?', [SalesReportFixture::ACCESSORIES, SalesReportFixture::SHIRT[0]]);
        $this->fixture->addRefundWithoutTicket('rpt955-r-shirt', '2026-03-11 12:00:00', SalesReportFixture::TILL_A, SalesReportFixture::SESSION_1,
            SalesReportFixture::ANA, SalesReportFixture::SHIRT, 25.0);
        $this->fixture->addSale(SalesReportFixture::EXTRA_SALE, '2026-03-10 12:00:00', [[[null, 'Cuota club', 15.0], 1, 15.0]]);

        $data = $this->report('categories', '2026-03-10', '2026-03-11')['data'];

        $this->assertSame(
            [
                [SalesReportFixture::CLOTHES, 'Ropa', 4, 1, 3, 111.0, 25.0, 86.0, 89.58],
                [null, 'Sin categoría', 1, 0, 1, 15.0, 0.0, 15.0, 15.63],
                [SalesReportFixture::ACCESSORIES, 'Accesorios', 2, 1, 1, 20.0, 25.0, -5.0, -5.21],
            ],
            $this->pick($data['rows'], 'category_id', 'name', 'units_sold', 'units_refunded', 'units_net', 'sales_amount', 'refunds_amount', 'net_amount', 'percent')
        );
        $this->assertSame(['units_sold' => 7, 'units_refunded' => 2, 'units_net' => 5, 'sales_amount' => 146.0, 'refunds_amount' => 50.0, 'net_amount' => 96.0], $data['totals']);
    }

    public function testCategoriesShowTheStoredNameWhenTheCategoryWasRenamed(): void
    {
        $this->connection->executeStatement("UPDATE catalog_category SET name = 'Textil' WHERE id = ?", [SalesReportFixture::CLOTHES]);

        $rows = $this->report('categories', '2026-03-10', '2026-03-10')['data']['rows'];

        $this->assertSame([['Ropa', 86.0], ['Accesorios', 20.0]], $this->pick($rows, 'name', 'net_amount'));
    }

    public function testCashiersCountTicketsByWhoSoldAndRefundsByWhoRefundedWithTheCurrentName(): void
    {
        $this->connection->executeStatement("UPDATE user SET name = 'Ana María' WHERE id = ?", [SalesReportFixture::ANA]);

        $data = $this->report('cashiers', '2026-03-10', '2026-03-12')['data'];

        $this->assertSame(
            [
                ['user_id' => SalesReportFixture::ANA, 'name' => 'Ana María', 'tickets' => 3, 'gross_sales' => 95.0, 'average_ticket' => 31.67, 'refunds' => 25.0, 'net_sales' => 70.0],
                ['user_id' => SalesReportFixture::LUIS, 'name' => 'Luis', 'tickets' => 2, 'gross_sales' => 76.0, 'average_ticket' => 38.0, 'refunds' => 36.0, 'net_sales' => 40.0],
            ],
            $data['rows']
        );
        $this->assertSame(['tickets' => 5, 'gross_sales' => 171.0, 'average_ticket' => 34.2, 'refunds' => 61.0, 'net_sales' => 110.0], $data['totals']);

        // Luis makes D1 (Ana's T1) on the 11th: the refund is his.
        $this->connection->executeStatement("UPDATE refund SET user_id = ? WHERE request_id = 'rpt955-d1'", [SalesReportFixture::LUIS]);
        $eleventh = $this->report('cashiers', '2026-03-11', '2026-03-11')['data']['rows'];
        $this->assertSame(
            [[SalesReportFixture::ANA, 1, 25.0, 0.0, 25.0], [SalesReportFixture::LUIS, 0, 0.0, 25.0, -25.0]],
            $this->pick($eleventh, 'user_id', 'tickets', 'gross_sales', 'refunds', 'net_sales')
        );
        $this->assertSame(0.0, $eleventh[1]['average_ticket']);
    }

    public function testVatOfTheTenthIsTheSumOfTheLineBasesCriterion9(): void
    {
        $report = $this->report('vat', '2026-03-10', '2026-03-10');

        $this->assertSame(
            [[
                'rate' => 21.0,
                'sales' => ['base' => 87.59, 'vat' => 18.41, 'total' => 106.0],
                'refunds' => ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0],
                'net' => ['base' => 87.59, 'vat' => 18.41, 'total' => 106.0],
            ]],
            $report['data']['rows']
        );
        $this->assertSame(['sales' => ['base' => 87.59, 'vat' => 18.41, 'total' => 106.0], 'refunds' => ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0],
            'net' => ['base' => 87.59, 'vat' => 18.41, 'total' => 106.0]], $report['data']['totals']);
        $this->assertSame(0.0, $report['approximate_amount']);
    }

    public function testVatOfRefundsTakesTheRateOfTheirSaleLineOrTheCurrentCatalogAndWarnsAboutApproximateAmounts(): void
    {
        // The catalog now sells Camiseta at 10 %: D1 keeps the 21 % of its sale line.
        $this->connection->executeStatement('UPDATE catalog_product SET price_net = 22.73 WHERE id = ?', [SalesReportFixture::SHIRT[0]]);
        // Libro (10 % in the catalog) and a product no longer in the catalog (21 %, approximate).
        $this->fixture->addRefundWithoutTicket('rpt955-r-book', '2026-03-11 12:00:00', SalesReportFixture::TILL_A, SalesReportFixture::SESSION_1,
            SalesReportFixture::ANA, SalesReportFixture::BOOK, 11.0);
        $this->fixture->addRefundWithoutTicket('rpt955-r-gone', '2026-03-11 12:05:00', SalesReportFixture::TILL_A, SalesReportFixture::SESSION_1,
            SalesReportFixture::ANA, [955199, 'Descatalogado', 5.0], 5.0);
        $this->connection->executeStatement('UPDATE sale_line SET approximate = 1 WHERE order_id = ?', [SalesReportFixture::T4]);

        $report = $this->report('vat', '2026-03-10', '2026-03-12');

        $this->assertSame(
            [
                [
                    'rate' => 21.0,
                    // T1-T4 and C1: 87,59 + 20,66 + 33,06 of base.
                    'sales' => ['base' => 141.31, 'vat' => 29.69, 'total' => 171.0],
                    // D1 25,00 + C1 36,00 + the discontinued product 5,00.
                    'refunds' => ['base' => 54.54, 'vat' => 11.46, 'total' => 66.0],
                    'net' => ['base' => 86.77, 'vat' => 18.23, 'total' => 105.0],
                ],
                [
                    'rate' => 10.0,
                    'sales' => ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0],
                    'refunds' => ['base' => 10.0, 'vat' => 1.0, 'total' => 11.0],
                    'net' => ['base' => -10.0, 'vat' => -1.0, 'total' => -11.0],
                ],
            ],
            $report['data']['rows']
        );
        $this->assertSame(['base' => 76.77, 'vat' => 17.23, 'total' => 94.0], $report['data']['totals']['net']);
        // T4 (approximate line) 25,00 + the refund of the discontinued product 5,00.
        $this->assertSame(30.0, $report['approximate_amount']);
    }

    public function testPaymentsByDatesAndTillCountTheRefundsAndExchangeVouchersOfThatTill(): void
    {
        // Till B, 12/03: a refund without ticket paid with a voucher (15,00) and an
        // exchange closed with a voucher (20,00) in session 3. Till A, 12/03: a card refund.
        $this->fixture->addRefundWithoutTicket('rpt955-r-voucher', '2026-03-12 12:00:00', SalesReportFixture::TILL_B, SalesReportFixture::SESSION_3,
            SalesReportFixture::LUIS, SalesReportFixture::HAT, 15.0, true);
        $this->connection->insert('refund', ['request_id' => 'rpt955-r-exv', 'status' => 'done', 'order_id' => SalesReportFixture::T1, 'terminal_id' => SalesReportFixture::TILL_B,
            'session_id' => SalesReportFixture::SESSION_3, 'user_id' => SalesReportFixture::LUIS, 'reason_code' => 'size', 'total' => 20.0, 'cash_amount' => 0, 'card_amount' => 0,
            'exchange_credit' => 20.0, 'created_at' => '2026-03-12 13:00:00', 'completed_at' => '2026-03-12 13:00:00']);
        $this->connection->insert('exchange', ['status' => 'closed_voucher', 'order_id' => SalesReportFixture::T1, 'refund_id' => (int) $this->connection->lastInsertId(),
            'user_id' => SalesReportFixture::LUIS, 'credit' => 20.0, 'credit_applied' => 0, 'new_total' => 0, 'balance' => 0, 'payment_method' => 'card', 'cart_data' => '{}',
            'voucher_amount' => 20.0, 'voucher_session_id' => SalesReportFixture::SESSION_3, 'created_at' => '2026-03-12 13:00:00', 'completed_at' => '2026-03-12 13:05:00']);
        $this->connection->insert('refund', ['request_id' => 'rpt955-r-card', 'status' => 'done', 'order_id' => SalesReportFixture::T4, 'terminal_id' => SalesReportFixture::TILL_A,
            'session_id' => SalesReportFixture::SESSION_1, 'user_id' => SalesReportFixture::ANA, 'reason_code' => 'size', 'total' => 7.0, 'cash_amount' => 0, 'card_amount' => 7.0,
            'created_at' => '2026-03-12 14:00:00', 'completed_at' => '2026-03-12 14:00:00']);

        $columns = ['refunds_cash', 'refunds_card', 'refunds_voucher', 'exchange_vouchers', 'exchange_credit', 'refunds_total', 'sales_total', 'net_total'];
        $pick = fn (array $extra): array => array_intersect_key($this->report('payments', '2026-03-12', '2026-03-12', $extra)['data'], array_flip($columns));

        $this->assertEquals(
            ['refunds_cash' => 0.0, 'refunds_card' => 0.0, 'refunds_voucher' => 35.0, 'exchange_vouchers' => 20.0, 'exchange_credit' => 36.0,
                'refunds_total' => 35.0, 'sales_total' => 4.0, 'net_total' => -31.0],
            $pick(['terminal_id' => (string) SalesReportFixture::TILL_B])
        );
        $this->assertEquals(
            ['refunds_cash' => 0.0, 'refunds_card' => 7.0, 'refunds_voucher' => 0.0, 'exchange_vouchers' => 0.0, 'exchange_credit' => 0.0,
                'refunds_total' => 7.0, 'sales_total' => 0.0, 'net_total' => -7.0],
            $pick(['terminal_id' => (string) SalesReportFixture::TILL_A])
        );
        $this->assertEquals(
            ['refunds_cash' => 0.0, 'refunds_card' => 7.0, 'refunds_voucher' => 35.0, 'exchange_vouchers' => 20.0, 'exchange_credit' => 36.0,
                'refunds_total' => 42.0, 'sales_total' => 4.0, 'net_total' => -38.0],
            $pick([])
        );
    }
}
