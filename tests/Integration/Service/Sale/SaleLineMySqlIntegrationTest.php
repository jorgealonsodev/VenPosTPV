<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Sale;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Sale\SaleLineWriter;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/informes-ventas.md, I1 and acceptance criterion 10: each sale writes one
 * sale_line per ticket line, with its VAT rate, base and VAT amount, in the sale
 * transaction; when a line cannot be written nothing of the sale remains.
 */
final class SaleLineMySqlIntegrationTest extends TestCase
{
    private const TERMINAL_ID = 9461;
    private const LETTER = 'Y';
    private const SESSION_ID = 9461;
    private const SHIRT = 946001;
    private const BOOK = 946002;
    private const CATEGORY = 946101;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'Sale lines', 'ticket_letter' => self::LETTER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-05 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_category', ['id' => self::CATEGORY, 'name' => 'Ropa']);
        $c->insert('catalog_product', ['id' => self::SHIRT, 'name' => 'Camiseta', 'reference' => 'CAM-1', 'price' => 20.66, 'price_ttc' => 25.00, 'price_net' => 20.66, 'id_category_default' => self::CATEGORY]);
        $c->insert('catalog_product', ['id' => self::BOOK, 'name' => 'Libro', 'price' => 10.00, 'price_ttc' => 11.00, 'price_net' => 10.00]);
        foreach ([self::SHIRT, self::BOOK] as $product) {
            $c->insert('catalog_stock', ['id_product' => $product, 'id_product_attribute' => 0, 'quantity' => 5]);
        }
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION_ID, 'user_id' => 1, 'terminal_id' => self::TERMINAL_ID];
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
        $this->connection = null;
        $_SESSION = [];
    }

    private function clean(): void
    {
        $c = $this->connection;
        foreach ($c->fetchFirstColumn('SELECT id FROM order_meta WHERE session_id = ?', [self::SESSION_ID]) as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $id]);
        }
        $c->executeStatement('DELETE FROM sale_line WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product IN (?, ?)', [self::SHIRT, self::BOOK]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id IN (?, ?)', [self::SHIRT, self::BOOK]);
        $c->executeStatement('DELETE FROM catalog_category WHERE id = ?', [self::CATEGORY]);
    }

    private function service(?SaleLineWriter $writer = null): CheckoutService
    {
        $c = $this->connection;
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        return new CheckoutService(
            $this->createMock(CartService::class),
            $client,
            $c,
            $cash,
            $bus,
            $this->createMock(PrinterService::class),
            ticketNumberer: new TicketNumberer($c),
            outbox: new Outbox($c),
            saleLineWriter: $writer,
        );
    }

    /** 2 Camiseta at 25,00 with 10 % line discount (45,00) and 1 Libro at 11,00 (10 % VAT). */
    private function cart(): array
    {
        return [
            'lines' => [
                ['product_id' => self::SHIRT, 'id_product_attribute' => 0, 'qty' => 2, 'price' => 25.00, 'discount' => 10, 'name' => 'Camiseta'],
                ['product_id' => self::BOOK, 'id_product_attribute' => 0, 'qty' => 1, 'price' => 11.00, 'name' => 'Libro'],
            ],
            'total' => 56.00,
        ];
    }

    public function testEachTicketLineIsStoredWithItsVatRateBaseAndVatAmount(): void
    {
        $result = $this->service()->createOrder(7, $this->cart(), 'cash', 60.0);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT line_index, sold_at, terminal_id, session_id, user_id, product_id, id_product_attribute, reference, name,
                    category_id, category_name, quantity, unit_price, line_discount_pct, gross_amount, paid_amount,
                    vat_rate, base_amount, vat_amount, approximate
             FROM sale_line WHERE order_id = ? ORDER BY line_index',
            [$result['order_id']]
        );
        $soldAt = $this->connection->fetchOne('SELECT created_at FROM order_meta WHERE id = ?', [$result['order_id']]);

        $this->assertSame([
            [
                'line_index' => 0, 'sold_at' => $soldAt, 'terminal_id' => self::TERMINAL_ID, 'session_id' => self::SESSION_ID, 'user_id' => 7,
                'product_id' => self::SHIRT, 'id_product_attribute' => 0, 'reference' => 'CAM-1', 'name' => 'Camiseta',
                'category_id' => self::CATEGORY, 'category_name' => 'Ropa', 'quantity' => 2, 'unit_price' => '25.00',
                'line_discount_pct' => '10.00', 'gross_amount' => '50.00', 'paid_amount' => '45.00',
                'vat_rate' => '21.00', 'base_amount' => '37.19', 'vat_amount' => '7.81', 'approximate' => 0,
            ],
            [
                'line_index' => 1, 'sold_at' => $soldAt, 'terminal_id' => self::TERMINAL_ID, 'session_id' => self::SESSION_ID, 'user_id' => 7,
                'product_id' => self::BOOK, 'id_product_attribute' => 0, 'reference' => null, 'name' => 'Libro',
                'category_id' => null, 'category_name' => null, 'quantity' => 1, 'unit_price' => '11.00',
                'line_discount_pct' => '0.00', 'gross_amount' => '11.00', 'paid_amount' => '11.00',
                'vat_rate' => '10.00', 'base_amount' => '10.00', 'vat_amount' => '1.00', 'approximate' => 0,
            ],
        ], $rows);
    }

    public function testALineThatCannotBeWrittenLeavesNoSaleMovementFiscalRecordOrUpload(): void
    {
        $fiscalBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record');
        $outboxBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox');
        $failing = $this->createMock(SaleLineWriter::class);
        $failing->method('write')->willThrowException(new \RuntimeException('disk full'));

        try {
            $this->service($failing)->createOrder(7, $this->cart(), 'cash', 60.0);
            $this->fail('the sale must fail');
        } catch (CheckoutException $e) {
            $this->assertSame(0, $e->getCode(), 'nothing was stored: charging again is safe');
        }

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM sale_line WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame($fiscalBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'));
        $this->assertSame($outboxBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox'));
        $this->assertSame(5, (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ?', [self::SHIRT]));
    }

    public function testTheTicketAndTheFiscalRecordShowTheVatBoxOfTheStoredLines(): void
    {
        $result = $this->service()->createOrder(7, $this->cart(), 'cash', 60.0);

        $ticket = (new TicketDataResolver($this->connection, $this->createMock(TicketVerificationService::class), 'Tienda', ''))
            ->resolve(TicketDataResolver::saleKey($result['order_id']));

        $this->assertSame([
            ['rate' => 21.0, 'base' => 37.19, 'vat' => 7.81],
            ['rate' => 10.0, 'base' => 10.0, 'vat' => 1.0],
        ], $ticket->vatBreakdown);
        $this->assertSame(8.81, $ticket->tax);
        $this->assertSame(47.19, $ticket->subtotal);
        $breakdown = json_decode((string) $this->connection->fetchOne('SELECT payment_breakdown FROM order_meta WHERE id = ?', [$result['order_id']]), true);
        $this->assertSame(8.81, $breakdown['tax']);
        $this->assertSame('8.81', (string) $this->connection->fetchOne('SELECT tax_total FROM fiscal_record WHERE order_id = ?', [$result['order_id']]));
    }
}
