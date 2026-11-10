<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Checkout;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Service\Transfer\TransferPayments;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * PRD V1, V2, V4: the sale is stored in one local transaction (lines, tender, cash
 * movement, local stock, ticket number and outbox row), printed and the drawer
 * opened "sin esperar a PrestaShop"; stock 0 or less warns and still sells.
 */
final class LocalFirstCheckoutMySqlIntegrationTest extends TestCase
{
    private const TERMINAL_ID = 9401;
    private const LETTER = 'Q';
    private const SESSION_ID = 9401;
    private const PRODUCT = 940001;
    private const PRICE = 12.10;

    private ?Connection $connection = null;

    /** @var list<object> */
    private array $dispatched = [];

    private int $drawerOpens = 0;

    private int $cartClears = 0;

    private OutboxSyncTrigger $trigger;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'Local first', 'ticket_letter' => self::LETTER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-03 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Camiseta', 'price' => 10.0, 'price_ttc' => self::PRICE, 'price_net' => 10.0]);
        $c->insert('catalog_stock', ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 5]);
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION_ID, 'user_id' => 1, 'terminal_id' => self::TERMINAL_ID];
        $this->trigger = new OutboxSyncTrigger();
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
        $ids = $c->fetchFirstColumn('SELECT id FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        foreach ($ids as $id) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $id]);
            $c->executeStatement('DELETE FROM transfer_payment WHERE order_id = ?', [(int) $id]);
        }
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
    }

    private function service(?PsOrderClient $client = null, ?Outbox $outbox = null): CheckoutService
    {
        $c = $this->connection;
        if ($client === null) {
            $client = $this->createMock(PsOrderClient::class);
            $client->expects($this->never())->method('createOrder');
        }
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $m): Envelope {
            $this->dispatched[] = $m;

            return new Envelope($m);
        });
        $printer = $this->createMock(PrinterService::class);
        $printer->method('openDrawer')->willReturnCallback(function (): void {
            ++$this->drawerOpens;
        });
        $cart = $this->createMock(CartService::class);
        $cart->method('clear')->willReturnCallback(function (): array {
            ++$this->cartClears;

            return [];
        });
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        return new CheckoutService($cart, $client, $c, $cash, $bus, $printer, false, null, new TicketNumberer($c), $outbox ?? new Outbox($c), $this->trigger);
    }

    /** @return array<string, mixed> */
    private function cart(int $qty = 1): array
    {
        return ['lines' => [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => $qty, 'price' => self::PRICE, 'name' => 'Camiseta']], 'total' => round(self::PRICE * $qty, 2)];
    }

    private function lastNumber(): int
    {
        return (int) $this->connection->fetchOne('SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = ?', [self::LETTER, (int) date('Y')]);
    }

    private function stock(): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = 0', [self::PRODUCT]);
    }

    private function code(int $number): string
    {
        return sprintf('%s%s%06d', self::LETTER, date('y'), $number);
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(5, $this->stock());
        $this->assertSame([], $this->dispatched);
        $this->assertSame(0, $this->drawerOpens);
        $this->assertSame(0, $this->cartClears);
        $this->assertFalse($this->trigger->isRequested());
    }

    public function testSaleIsStoredLocallyInOneTransactionAndAnsweredWithoutPrestaShop(): void
    {
        $result = $this->service()->createOrder(1, $this->cart(2) + ['cash_amount' => 24.20], 'cash', 30.0);

        $orderId = $result['order_id'];
        $this->assertIsInt($orderId);
        $this->assertSame($this->code(1), $result['reference']);
        $this->assertNull($result['ps_order_id'], 'no PrestaShop order yet');
        $this->assertFalse($result['synced']);
        $this->assertSame(24.2, $result['total']);
        $this->assertSame(5.8, $result['change_due']);
        $this->assertSame('cash', $result['payment_method']);
        $this->assertSame(self::TERMINAL_ID, $result['terminal_id']);
        $this->assertFalse($result['saga_error']);
        $this->assertSame([], $result['stock_warnings']);

        $meta = $this->connection->fetchAssociative('SELECT * FROM order_meta WHERE id = ?', [$orderId]);
        $this->assertSame($this->code(1), $meta['reference']);
        $this->assertNull($meta['ps_order_id']);
        $this->assertNull($meta['ps_reference']);
        $this->assertSame(0, (int) $meta['synced']);
        $breakdown = json_decode((string) $meta['payment_breakdown'], true);
        $this->assertSame($this->code(1), $breakdown['reference']);
        $this->assertSame(24.2, $breakdown['lines'][0]['paid_total']);
        $this->assertEquals(['cash' => 24.2, 'card' => 0.0], $breakdown['tender']);

        $this->assertEquals(
            [['type' => 'sale', 'amount' => '24.20', 'ps_order_id' => null, 'order_id' => (string) $orderId]],
            $this->connection->fetchAllAssociative('SELECT type, amount, ps_order_id, order_id FROM movement WHERE session_id = ?', [self::SESSION_ID])
        );
        $this->assertSame(3, $this->stock());

        $outbox = $this->connection->fetchAllAssociative('SELECT * FROM outbox WHERE order_id = ?', [$orderId]);
        $this->assertCount(1, $outbox);
        $this->assertSame('sale', $outbox[0]['type']);
        $this->assertSame(1, (int) $outbox[0]['sequence']);
        $this->assertSame('pending', $outbox[0]['status']);
        $this->assertSame(0, (int) $outbox[0]['attempts']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $outbox[0]['request_id']);
        $payload = json_decode((string) $outbox[0]['payload'], true);
        $this->assertSame($this->code(1), $payload['reference'], 'the ticket code is the PrestaShop reference');
        $this->assertSame([['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 2, 'price' => self::PRICE]], $payload['products']);
        $this->assertSame('cash', $payload['payment_method']);

        $this->assertEquals([new PrintTicketMessage(TicketDataResolver::saleKey($orderId), self::TERMINAL_ID)], $this->dispatched);
        $this->assertSame(1, $this->drawerOpens);
        $this->assertSame(1, $this->cartClears);
        $this->assertTrue($this->trigger->isRequested(), 'an upload attempt follows the response');
        $this->assertSame(1, $this->lastNumber());
    }

    public function testFailureInsideTheTransactionRollsBackEverythingAndLeavesNoNumberGap(): void
    {
        $failing = $this->createMock(Outbox::class);
        $failing->method('enqueueSale')->willThrowException(new \RuntimeException('disk full'));

        try {
            $this->service(outbox: $failing)->createOrder(1, $this->cart(), 'cash', 20.0);
            $this->fail('the sale must fail');
        } catch (CheckoutException $e) {
            $this->assertSame(0, $e->getCode(), 'a local failure is retryable: nothing was stored');
        }

        $this->assertNothingStored();
        $this->assertSame(0, $this->lastNumber(), 'the rolled back number is given back');
        // No upload of the rolled back sale was left behind.
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox WHERE type = 'sale' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.reference')) = ?", [$this->code(1)]));

        $result = $this->service()->createOrder(1, $this->cart(), 'card', 12.10);
        $this->assertSame($this->code(1), $result['reference'], 'the next sale takes the same number: no gap');
    }

    public function testRepeatedRequestIdReturnsTheSameSaleWithoutASecondNumberMovementOrUpload(): void
    {
        $service = $this->service();
        $first = $service->createOrder(1, $this->cart() + ['cash_amount' => self::PRICE], 'cash', 20.0, 0.0, 'attempt-0001-abcd');

        // The browser retries, or replays the request it queued when the network dropped.
        $again = $this->service()->createOrder(1, $this->cart() + ['cash_amount' => self::PRICE], 'cash', 20.0, 0.0, 'attempt-0001-abcd');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        foreach (['order_id', 'ps_order_id', 'reference', 'synced', 'total', 'change_due', 'payment_method', 'terminal_id'] as $key) {
            $this->assertSame($first[$key], $again[$key], $key);
        }
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(1, $this->lastNumber(), 'no second ticket number');
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]), 'no second cash movement');
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM outbox WHERE order_id = ?', [$first['order_id']]), 'no second upload');
        $this->assertSame(4, $this->stock(), 'stock lowered once');
        $this->assertCount(1, $this->dispatched, 'printed once');
        $this->assertSame(1, $this->drawerOpens, 'the drawer opened once');
        $this->assertSame('attempt-0001-abcd', $this->connection->fetchOne('SELECT request_id FROM order_meta WHERE id = ?', [$first['order_id']]));
    }

    public function testCheckoutWithoutRequestIdStillCreatesASaleEachTimeAndABadOneIsRejected(): void
    {
        $service = $this->service();

        $first = $service->createOrder(1, $this->cart(), 'card', 12.10);
        $second = $service->createOrder(1, $this->cart(), 'card', 12.10);

        $this->assertArrayNotHasKey('replayed', $first, 'the answer is as before');
        $this->assertNotSame($first['order_id'], $second['order_id']);
        $this->assertSame($this->code(2), $second['reference']);
        try {
            $service->createOrder(1, $this->cart(), 'card', 12.10, 0.0, 'bad id!');
            $this->fail('an invalid request_id is refused');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->assertSame(2, $this->lastNumber());
    }

    public function testStockZeroOrLessWarnsPerLineAndStillSells(): void
    {
        $this->connection->executeStatement('UPDATE catalog_stock SET quantity = 0 WHERE id_product = ?', [self::PRODUCT]);

        $result = $this->service()->createOrder(1, $this->cart(), 'card', 12.10);

        $this->assertSame([['line_index' => 0, 'product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'requested' => 1, 'available' => 0]], $result['stock_warnings']);
        $this->assertSame(-1, $this->stock(), 'the local stock goes below zero');
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]), 'card sales move no cash');
    }

    public function testTerminalWithoutTicketLetterIsACashierFacing422AndStoresNothing(): void
    {
        $this->connection->executeStatement('UPDATE terminal SET ticket_letter = NULL WHERE id = ?', [self::TERMINAL_ID]);

        try {
            $this->service()->createOrder(1, $this->cart(), 'cash', 20.0);
            $this->fail('the sale must be refused');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('La caja no tiene letra de ticket: asígnala en Ajustes', $e->getMessage());
        }

        $this->assertNothingStored();
    }

    public function testExhaustedNumbersAreA422AndTheCounterStaysPut(): void
    {
        $this->connection->insert('ticket_sequence', ['letter' => self::LETTER, 'year' => (int) date('Y'), 'last_number' => TicketNumberer::MAX_NUMBER]);

        try {
            $this->service()->createOrder(1, $this->cart(), 'cash', 20.0);
            $this->fail('the sale must be refused');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame(sprintf('La caja Q ha agotado los números de ticket de %d', (int) date('Y')), $e->getMessage());
        }

        $this->assertNothingStored();
        $this->assertSame(TicketNumberer::MAX_NUMBER, $this->lastNumber());
    }

    public function testMixedSaleWithCashAboveTheTotalBooksOnlyThePayableInTheDrawer(): void
    {
        // PaymentModal.js sends the cash handed over; anything above the total is change.
        $result = $this->service()->createOrder(1, $this->cart(2) + ['cash_amount' => 1e6], 'mixed', 1e6);
        $c = $this->connection;

        $this->assertEquals(
            [['type' => 'sale', 'amount' => '24.20']],
            $c->fetchAllAssociative('SELECT type, amount FROM movement WHERE session_id = ?', [self::SESSION_ID])
        );
        $breakdown = json_decode((string) $c->fetchOne('SELECT payment_breakdown FROM order_meta WHERE id = ?', [$result['order_id']]), true);
        $this->assertEquals(['cash' => 24.2, 'card' => 0.0], $breakdown['tender']);

        $report = (new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack()))->getXReport(1);
        $this->assertSame(124.2, $report['expected_cash'], 'opening 100 + the 24.20 charged');
    }

    public function testTransferSaleIsChargedWholeWithoutDrawerOrCashAndCountedApart(): void
    {
        $result = $this->service()->createOrder(1, $this->cart(2), 'transfer', 24.2);
        $c = $this->connection;

        $this->assertSame('transfer', $result['payment_method']);
        $this->assertSame(0.0, $result['change_due']);
        $this->assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]), 'no cash moves');
        $this->assertSame(0, $this->drawerOpens, 'the drawer stays shut');
        $breakdown = json_decode((string) $c->fetchOne('SELECT payment_breakdown FROM order_meta WHERE id = ?', [$result['order_id']]), true);
        $this->assertSame('transfer', $breakdown['payment_method']);
        $this->assertEquals(['cash' => 0.0, 'card' => 0.0, 'transfer' => 24.2], $breakdown['tender']);
        $payload = json_decode((string) $c->fetchOne('SELECT payload FROM outbox WHERE order_id = ?', [$result['order_id']]), true);
        $this->assertSame('transfer', $payload['payment_method'], 'the PrestaShop order says transfer');

        $report = (new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack()))->getXReport(1);
        $this->assertSame(24.2, $report['totals']['sales_transfer']);
        $this->assertSame(24.2, $report['totals']['sales_transfer_pending'], 'not received in the bank yet');
        $this->assertSame(0.0, $report['totals']['sales_card']);
        $this->assertSame(24.2, $report['totals']['sales_total']);
        $this->assertSame(100.0, $report['expected_cash'], 'only the opening cash');
    }

    public function testATransferSaleWaitsPendingWithTheIbanOnItsTicketUntilAnAdminMarksItReceived(): void
    {
        $c = $this->connection;
        $c->update('terminal', ['bank_account' => 'ES9121000418450200051332'], ['id' => self::TERMINAL_ID]);
        $result = $this->service()->createOrder(1, $this->cart(2), 'transfer', 24.2);
        $transfers = new TransferPayments($c);
        $resolver = new TicketDataResolver($c, new TicketVerificationService('test-secret', 'http://tpv'), 'Tienda', '');

        $pending = $transfers->pending();
        $this->assertCount(1, $pending);
        $this->assertSame([$result['order_id'], $this->code(1), 24.2], [$pending[0]['order_id'], $pending[0]['reference'], $pending[0]['amount']]);
        $ticket = $resolver->resolve(TicketDataResolver::saleKey($result['order_id']));
        $this->assertSame(['account' => 'ES91 2100 0418 4502 0005 1332', 'pending' => true], $ticket->transfer);

        $this->assertSame('received', $transfers->markReceived($pending[0]['id'], 1));
        $this->assertSame('already_received', $transfers->markReceived($pending[0]['id'], 1), 'only once');
        $this->assertSame('not_found', $transfers->markReceived(999999999, 1));
        $this->assertSame([], $transfers->pending());
        $this->assertFalse($resolver->resolve(TicketDataResolver::saleKey($result['order_id']))->transfer['pending'], 'the reprint no longer says pending');
        $row = $c->fetchAssociative('SELECT status, received_by_user_id, received_at IS NOT NULL AS dated FROM transfer_payment WHERE order_id = ?', [$result['order_id']]);
        $this->assertEquals(['status' => 'received', 'received_by_user_id' => 1, 'dated' => 1], $row);

        $report = (new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack()))->getXReport(1);
        $this->assertSame(24.2, $report['totals']['sales_transfer']);
        $this->assertSame(0.0, $report['totals']['sales_transfer_pending']);
    }

    public function testACashSaleHasNoTransferRow(): void
    {
        $result = $this->service()->createOrder(1, $this->cart(1), 'cash', 20.0);

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transfer_payment WHERE order_id = ?', [$result['order_id']]));
        $ticket = (new TicketDataResolver($this->connection, new TicketVerificationService('test-secret', 'http://tpv'), 'Tienda', ''))->resolve(TicketDataResolver::saleKey($result['order_id']));
        $this->assertNull($ticket->transfer);
    }

    public function testTheTicketShowsEachDiscountAndTheVatWhateverThePaymentMethod(): void
    {
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(\dirname(__DIR__, 4) . '/templates'));
        $resolver = new TicketDataResolver($this->connection, new TicketVerificationService('test-secret', 'http://tpv'), 'Tienda', '');
        foreach (['cash' => 20.69, 'card' => 0.0, 'transfer' => 20.69] as $method => $tendered) {
            // 2 x 12,10 = 24,20; 10 % off the line (-2,42) and 5 % off the ticket (-1,09): 20,69.
            $cart = ['lines' => [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => 2, 'price' => self::PRICE, 'name' => 'Camiseta', 'discount' => 10]],
                'discount' => ['type' => 'pct', 'value' => 5], 'total' => 20.69];
            $sale = $this->service()->createOrder(1, $cart, $method, $tendered);
            $ticket = $resolver->resolve(TicketDataResolver::saleKey($sale['order_id']));

            $this->assertSame([10.0, 2.42], [$ticket->lines[0]->discountPct, $ticket->lines[0]->discountAmount], $method);
            $this->assertSame([2.42, 1.09, 'Dto. ticket'], [$ticket->itemsDiscount(), $ticket->ticketDiscount, $ticket->ticketDiscountLabel], $method);

            $paper = (new \VenPosTPV\Service\Print\EscPosTicketBuilder(['type' => 'network', 'host' => '127.0.0.1', 'port' => 9100, 'paperWidth' => 80, 'enabled' => true]))->build($ticket);
            foreach (['Dto. 10 %: -2.42', 'Subtotal: 24.20', 'Dto. articulos: -2.42', 'Dto. ticket: -1.09', 'IVA 21 %: 3.59', 'TOTAL: 20.69'] as $text) {
                $this->assertStringContainsString($text, $paper, $method . ' paper');
            }
            $pdf = $twig->render('ticket/print.html.twig', ['ticket' => $ticket, 'giftMode' => false, 'barcodePath' => '', 'qrPath' => '', 'logoPath' => '']);
            foreach (['Dto. 10 %: -2,42 €', 'Dto. artículos: -2,42 €', 'Dto. ticket: -1,09 €', 'IVA 21 %: 3,59 €', 'TOTAL: 20,69 €'] as $text) {
                $this->assertStringContainsString($text, $pdf, $method . ' pdf');
            }
        }
    }

    public function testATicketWithoutDiscountsPrintsNoDiscountLine(): void
    {
        $sale = $this->service()->createOrder(1, $this->cart(2), 'transfer', 24.2);
        $ticket = (new TicketDataResolver($this->connection, new TicketVerificationService('test-secret', 'http://tpv'), 'Tienda', ''))->resolve(TicketDataResolver::saleKey($sale['order_id']));

        $this->assertSame([0.0, 0.0], [$ticket->itemsDiscount(), $ticket->ticketDiscount]);
        $paper = (new \VenPosTPV\Service\Print\EscPosTicketBuilder(['type' => 'network', 'host' => '127.0.0.1', 'port' => 9100, 'paperWidth' => 80, 'enabled' => true]))->build($ticket);
        $this->assertStringNotContainsString('Dto.', $paper);
        $this->assertStringContainsString('IVA 21 %: 4.20', $paper);
    }

    public function testUnknownPaymentMethodIsA422AndStoresNothing(): void
    {
        try {
            $this->service()->createOrder(1, $this->cart(2), 'bizum', 24.2);
            $this->fail('An unknown payment method must be rejected, not booked as card');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->assertNothingStored();
        $this->assertSame(0, $this->lastNumber());
    }

    public function testMixedSaleWithNegativeCashIsA422AndStoresNothing(): void
    {
        try {
            $this->service()->createOrder(1, $this->cart(2) + ['cash_amount' => -20], 'mixed', 24.2);
            $this->fail('A negative cash amount must be rejected');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->assertNothingStored();
        $this->assertSame(0, $this->lastNumber());
    }

    public function testUnsyncedSaleIsPrintedSearchedByReferenceAndCountedInTheCashReport(): void
    {
        $result = $this->service()->createOrder(1, $this->cart() + ['cash_amount' => 12.10], 'cash', 20.0);
        $c = $this->connection;

        $verification = $this->createMock(TicketVerificationService::class);
        $verification->method('generateToken')->willReturn('t');
        $verification->method('buildVerificationUrl')->willReturn('https://example.com/v');
        $ticket = (new TicketDataResolver($c, $verification, 'Tienda', ''))->resolve(TicketDataResolver::saleKey($result['order_id']));
        $this->assertNotNull($ticket);
        $this->assertSame($this->code(1), $ticket->reference);
        $this->assertSame(12.1, $ticket->total);
        $this->assertSame('Camiseta', $ticket->lines[0]->name);

        $search = new OrderSearchService($c);
        $found = $search->search(['reference' => $this->code(1)]);
        $this->assertCount(1, $found);
        $this->assertSame($result['order_id'], $found[0]['order_id']);
        $this->assertNull($found[0]['ps_order_id']);
        $this->assertFalse($found[0]['synced']);
        $this->assertSame($this->code(1), $found[0]['reference']);
        $this->assertSame(1, $found[0]['lines'][0]['refundable_qty']);
        $this->assertSame($result['order_id'], $search->getOrderByReference($this->code(1))['order_id']);
        // The refund screen names a sale not uploaded yet by its local id or ticket code (PRD D1).
        $this->assertSame($result['order_id'], $search->findSaleId($result['order_id'], ''));
        $this->assertSame($result['order_id'], $search->findSaleId(0, strtolower($this->code(1))));
        $this->assertNull($search->findSaleId(0, 'Z99999999'));
        $this->assertSame($result['order_id'], $search->getOrderById($result['order_id'])['order_id']);

        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());
        $report = $cash->getXReport(1);
        $this->assertSame(12.1, $report['sales_cash']);
        $this->assertSame(112.1, $report['expected_cash']);
    }
}
