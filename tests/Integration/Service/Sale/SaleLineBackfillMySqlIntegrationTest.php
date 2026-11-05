<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Sale;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\SalesBackfillLinesCommand;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Sale\SaleLineBackfill;
use VenPosTPV\Service\Sale\SaleLineWriter;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/informes-ventas.md, I1 «Relleno de ventas antiguas» and acceptance
 * criterion 11: old sales get their lines from the current catalog, marked
 * approximate; running the command again changes nothing.
 */
final class SaleLineBackfillMySqlIntegrationTest extends TestCase
{
    private const SESSION_ID = 9471;
    private const TERMINAL_ID = 9471;
    private const SHIRT = 947001;
    private const GONE = 947099;
    private const CATEGORY = 947101;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        // Other tests' sales would be backfilled too: start from sales that all have lines.
        (new SaleLineBackfill($this->connection, new SaleLineWriter($this->connection)))->run();
        $c = $this->connection;
        $c->insert('catalog_category', ['id' => self::CATEGORY, 'name' => 'Ropa']);
        $c->insert('catalog_product', ['id' => self::SHIRT, 'name' => 'Camiseta', 'reference' => 'CAM-1', 'price' => 20.66, 'price_ttc' => 25.00, 'price_net' => 20.66, 'id_category_default' => self::CATEGORY]);
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
        $c->executeStatement('DELETE FROM sale_line WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::SHIRT]);
        $c->executeStatement('DELETE FROM catalog_category WHERE id = ?', [self::CATEGORY]);
    }

    /** @param array<string, mixed> $breakdown */
    private function oldSale(array $breakdown): int
    {
        $this->connection->insert('order_meta', [
            'terminal_id' => self::TERMINAL_ID,
            'session_id' => self::SESSION_ID,
            'user_id' => 3,
            'payment_breakdown' => json_encode($breakdown, JSON_THROW_ON_ERROR),
            'created_at' => '2026-03-10 09:15:00',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $orderId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT line_index, sold_at, user_id, product_id, reference, name, category_id, category_name, quantity, unit_price,
                    line_discount_pct, gross_amount, paid_amount, vat_rate, base_amount, vat_amount, approximate
             FROM sale_line WHERE order_id = ? ORDER BY line_index',
            [$orderId]
        );
    }

    private function backfill(): CommandTester
    {
        $tester = new CommandTester(new SalesBackfillLinesCommand(new SaleLineBackfill($this->connection, new SaleLineWriter($this->connection))));
        $tester->execute([]);

        return $tester;
    }

    public function testOldSalesGetApproximateLinesFromTheCurrentCatalogAndARerunChangesNothing(): void
    {
        // Stored with paid_total: lines take it as is.
        $withPaid = $this->oldSale(['total' => 45.0, 'lines' => [
            ['name' => 'Camiseta', 'product_id' => self::SHIRT, 'id_product_attribute' => 0, 'qty' => 2, 'price' => 25.0, 'line_total' => 50.0, 'paid_total' => 45.0],
        ]]);
        // Stored before paid_total: the total is spread by line_total; a product no
        // longer in the catalog has no category and 21 % VAT.
        $beforePaid = $this->oldSale(['total' => 30.0, 'lines' => [
            ['name' => 'Camiseta', 'product_id' => self::SHIRT, 'id_product_attribute' => 0, 'qty' => 1, 'price' => 25.0, 'line_total' => 25.0],
            ['name' => 'Gorro viejo', 'product_id' => self::GONE, 'id_product_attribute' => 0, 'qty' => 1, 'price' => 10.0, 'line_total' => 10.0],
        ]]);

        $tester = $this->backfill();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame("Sales processed: 2, lines created: 3, approximate lines: 3\n", $tester->getDisplay());
        $this->assertSame([[
            'line_index' => 0, 'sold_at' => '2026-03-10 09:15:00', 'user_id' => 3, 'product_id' => self::SHIRT, 'reference' => 'CAM-1',
            'name' => 'Camiseta', 'category_id' => self::CATEGORY, 'category_name' => 'Ropa', 'quantity' => 2, 'unit_price' => '25.00',
            'line_discount_pct' => null, 'gross_amount' => '50.00', 'paid_amount' => '45.00',
            'vat_rate' => '21.00', 'base_amount' => '37.19', 'vat_amount' => '7.81', 'approximate' => 1,
        ]], $this->lines($withPaid));
        $this->assertSame([
            [
                'line_index' => 0, 'sold_at' => '2026-03-10 09:15:00', 'user_id' => 3, 'product_id' => self::SHIRT, 'reference' => 'CAM-1',
                'name' => 'Camiseta', 'category_id' => self::CATEGORY, 'category_name' => 'Ropa', 'quantity' => 1, 'unit_price' => '25.00',
                'line_discount_pct' => null, 'gross_amount' => '25.00', 'paid_amount' => '21.43',
                'vat_rate' => '21.00', 'base_amount' => '17.71', 'vat_amount' => '3.72', 'approximate' => 1,
            ],
            [
                'line_index' => 1, 'sold_at' => '2026-03-10 09:15:00', 'user_id' => 3, 'product_id' => self::GONE, 'reference' => null,
                'name' => 'Gorro viejo', 'category_id' => null, 'category_name' => null, 'quantity' => 1, 'unit_price' => '10.00',
                'line_discount_pct' => null, 'gross_amount' => '10.00', 'paid_amount' => '8.57',
                'vat_rate' => '21.00', 'base_amount' => '7.08', 'vat_amount' => '1.49', 'approximate' => 1,
            ],
        ], $this->lines($beforePaid));

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM sale_line WHERE session_id = ?', [self::SESSION_ID]);
        $again = $this->backfill();
        $this->assertSame("Sales processed: 0, lines created: 0, approximate lines: 0\n", $again->getDisplay());
        $this->assertSame($count, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM sale_line WHERE session_id = ?', [self::SESSION_ID]));
    }

    public function testASaleWhoseLinesCannotBeReadIsListedAndExitsWithOne(): void
    {
        $broken = $this->oldSale(['total' => 5.0, 'lines' => 'not a list']);
        $good = $this->oldSale(['total' => 25.0, 'lines' => [
            ['name' => 'Camiseta', 'product_id' => self::SHIRT, 'id_product_attribute' => 0, 'qty' => 1, 'price' => 25.0, 'line_total' => 25.0, 'paid_total' => 25.0],
        ]]);

        $tester = $this->backfill();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame(
            "Sales processed: 1, lines created: 1, approximate lines: 1\n"
            . "Sales skipped, their lines cannot be read (order_meta.id): {$broken}\n",
            $tester->getDisplay()
        );
        $this->assertCount(1, $this->lines($good));
        $this->assertSame([], $this->lines($broken));
    }

    public function testAReprintedBackfilledSaleKeepsTheVatItWasSoldWith(): void
    {
        $sale = $this->oldSale(['reference' => 'A26000001', 'total' => 45.0, 'tax' => 7.82, 'subtotal' => 50.0, 'lines' => [
            ['name' => 'Camiseta', 'product_id' => self::SHIRT, 'id_product_attribute' => 0, 'qty' => 2, 'price' => 25.0, 'line_total' => 50.0, 'paid_total' => 45.0],
        ]]);
        $this->backfill();

        $ticket = (new TicketDataResolver($this->connection, $this->createMock(TicketVerificationService::class), 'Tienda', ''))
            ->resolve(TicketDataResolver::saleKey($sale));

        $this->assertSame([], $ticket->vatBreakdown, 'backfilled lines are a guess: no VAT box');
        $this->assertSame(7.82, $ticket->tax);
        $this->assertSame(50.0, $ticket->subtotal);
    }
}
