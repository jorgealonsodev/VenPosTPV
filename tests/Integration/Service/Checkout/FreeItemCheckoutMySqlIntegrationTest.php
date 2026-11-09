<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Checkout;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\FreeItemSettingsStore;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/articulo-libre.md phase 1: a line typed at the till (concept, price, VAT
 * rate, quantity) is charged, stored with its own VAT, printed and recorded like any
 * other line, without stock, and its upload waits until PrestaShop is set up (A5.3).
 */
final class FreeItemCheckoutMySqlIntegrationTest extends TestCase
{
    private const TERMINAL_ID = 9471;
    private const LETTER = 'L';
    private const SESSION_ID = 9471;
    private const PRODUCT = 947101;
    private const PRICE = 12.10;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $c = $this->connection;
        $c->insert('terminal', ['id' => self::TERMINAL_ID, 'ps_shop_id' => 1, 'name' => 'Free item', 'ticket_letter' => self::LETTER]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $c->insert('session', ['id' => self::SESSION_ID, 'terminal_id' => self::TERMINAL_ID, 'user_id' => 1, 'opened_at' => '2026-11-09 09:00:00', 'opening_cash' => 100]);
        $c->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $c->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Camiseta', 'price' => 10.0, 'price_ttc' => self::PRICE, 'price_net' => 10.0]);
        $c->insert('catalog_stock', ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 5]);
        $_SESSION = ['shop_id' => 1, 'cash_session_id' => self::SESSION_ID, 'user_id' => 1, 'terminal_id' => self::TERMINAL_ID, 'role' => 'cashier'];
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
        $orders = $c->fetchAllAssociative('SELECT id, reference FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        foreach ($orders as $order) {
            $c->executeStatement('DELETE FROM outbox WHERE order_id = ?', [(int) $order['id']]);
            if ($order['reference'] !== null) {
                $c->executeStatement('DELETE FROM fiscal_record WHERE number = ?', [(string) $order['reference']]);
            }
        }
        $c->executeStatement('DELETE FROM movement WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM order_meta WHERE session_id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM session WHERE id = ?', [self::SESSION_ID]);
        $c->executeStatement('DELETE FROM ticket_sequence WHERE letter = ?', [self::LETTER]);
        $c->executeStatement('DELETE FROM terminal WHERE id = ?', [self::TERMINAL_ID]);
        $c->executeStatement('DELETE FROM catalog_stock WHERE id_product = ?', [self::PRODUCT]);
        $c->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
        $c->executeStatement("DELETE FROM app_setting WHERE name LIKE 'free\\_item.%'");
    }

    private function service(): CheckoutService
    {
        $c = $this->connection;
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $cart = $this->createMock(CartService::class);
        $cart->method('clear')->willReturn([]);
        $cash = new CashSessionService($c, $this->createMock(TerminalService::class), new RequestStack());

        return new CheckoutService($cart, $client, $c, $cash, $bus, $this->createMock(PrinterService::class), false, null, new TicketNumberer($c), new Outbox($c), new OutboxSyncTrigger());
    }

    private function setting(string $name, string $value): void
    {
        $this->connection->executeStatement('REPLACE INTO app_setting (name, value) VALUES (?, ?)', ['free_item.' . $name, $value]);
    }

    /** @return array<string, mixed> */
    private static function free(string $concept = 'Arreglo de bañador', mixed $price = 12.0, mixed $rate = 21, mixed $qty = 1, float $discount = 0.0): array
    {
        return ['free_item' => true, 'concept' => $concept, 'name' => $concept, 'product_id' => 0, 'price' => $price, 'vat_rate' => $rate, 'qty' => $qty, 'discount' => $discount];
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private static function cart(array $lines, float $total, mixed $discount = null): array
    {
        return ['lines' => $lines, 'total' => $total] + ($discount !== null ? ['discount' => $discount] : []);
    }

    /** @return array<string, mixed> */
    private function breakdown(int $orderId): array
    {
        return json_decode((string) $this->connection->fetchOne('SELECT payment_breakdown FROM order_meta WHERE id = ?', [$orderId]), true);
    }

    private function stock(): int
    {
        return (int) $this->connection->fetchOne('SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = 0', [self::PRODUCT]);
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movement WHERE session_id = ?', [self::SESSION_ID]));
        $this->assertSame(5, $this->stock());
    }

    public function testFreeItemIsChargedWithItsOwnVatOnTheLineSaleLineFiscalRecordAndTicket(): void
    {
        $result = $this->service()->createOrder(1, self::cart([self::free()], 12.0), 'cash', 20.0);

        $this->assertSame(12.0, $result['total']);
        $this->assertSame(8.0, $result['change_due']);
        $breakdown = $this->breakdown($result['order_id']);
        $this->assertSame(2.08, $breakdown['tax'], '12,00 − 12,00/1,21 = 2,0826');
        $line = $breakdown['lines'][0];
        $this->assertTrue($line['free_item']);
        $this->assertSame('Arreglo de bañador', $line['name']);
        $this->assertSame(0, $line['product_id']);
        $this->assertSame(0, $line['id_product_attribute']);
        $this->assertSame(1, $line['qty']);
        $this->assertEquals(12.0, $line['price']);
        $this->assertEquals(21.0, $line['vat_rate']);
        $this->assertSame(9.917355, $line['price_net']);
        $this->assertSame(2.08, $line['vat_amount']);
        $this->assertEquals(12.0, $line['line_total']);
        $this->assertEquals(12.0, $line['paid_total']);

        $saleLine = $this->connection->fetchAssociative('SELECT * FROM sale_line WHERE order_id = ?', [$result['order_id']]);
        $this->assertNull($saleLine['product_id']);
        $this->assertSame('Arreglo de bañador', $saleLine['name']);
        $this->assertSame('Artículo libre', $saleLine['category_name']);
        $this->assertNull($saleLine['category_id']);
        $this->assertSame('21.00', $saleLine['vat_rate']);
        $this->assertSame('9.92', $saleLine['base_amount']);
        $this->assertSame('2.08', $saleLine['vat_amount']);
        $this->assertSame(0, (int) $saleLine['approximate']);

        $fiscal = $this->connection->fetchAssociative('SELECT tax_total, total FROM fiscal_record WHERE number = ?', [$result['reference']]);
        $this->assertSame(2.08, (float) $fiscal['tax_total']);
        $this->assertSame(12.0, (float) $fiscal['total']);

        $ticket = (new TicketDataResolver($this->connection, $this->createMock(TicketVerificationService::class), 'Club', 'Calle 1'))->resolve(TicketDataResolver::saleKey($result['order_id']));
        $this->assertSame('Arreglo de bañador', $ticket->lines[0]->name);
        $this->assertSame([['rate' => 21.0, 'base' => 9.92, 'vat' => 2.08]], $ticket->vatBreakdown);

        $this->assertSame(5, $this->stock(), 'no stock is touched');
        $this->assertSame([], $result['stock_warnings']);
    }

    public function testATicketWithOnlyAFreeItemAtTenPercentDoesNotFallBackToTwentyOne(): void
    {
        $result = $this->service()->createOrder(1, self::cart([self::free('Curso', 10.0, 10)], 10.0), 'card', 0.0);

        $this->assertSame(0.91, $this->breakdown($result['order_id'])['tax'], 'not 1,74 (21 %)');
        $this->assertSame('10.00', $this->connection->fetchOne('SELECT vat_rate FROM sale_line WHERE order_id = ?', [$result['order_id']]));
    }

    public function testFreeItemAndCatalogLineKeepTheirRatesAndLineAndTicketDiscountsReduceTheVat(): void
    {
        // 12,10 catalog (21 %) + 2 × 10,00 free at 10 % with 50 % off = 22,10; 10 % off the ticket = 19,89.
        $lines = [
            ['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'qty' => 1, 'price' => self::PRICE, 'name' => 'Camiseta'],
            self::free('Curso', 10.0, 10, 2, 50.0),
        ];
        $result = $this->service()->createOrder(1, self::cart($lines, 19.89, ['type' => 'pct', 'value' => 10]), 'card', 0.0);

        $breakdown = $this->breakdown($result['order_id']);
        $this->assertSame(10.89, $breakdown['lines'][0]['paid_total']);
        $this->assertEquals(9.0, $breakdown['lines'][1]['paid_total']);
        $this->assertSame(0.82, $breakdown['lines'][1]['vat_amount'], '9,00 − 9,00/1,10');
        // 21 %: 10,89 → 1,89; 10 %: 9,00 → 0,82.
        $this->assertSame(2.71, $breakdown['tax']);
        $this->assertSame(4, $this->stock(), 'only the catalog line takes stock');
    }

    public function testTwoFreeItemsWithTheSameConceptStayTwoLinesWithTheirOwnUploadSlot(): void
    {
        $lines = [self::free('Arreglo', 5.0), self::free('Arreglo', 7.5), self::free('Cuota', 30.0, 10)];
        $result = $this->service()->createOrder(1, self::cart($lines, 42.5), 'card', 0.0);

        $this->assertCount(3, $this->breakdown($result['order_id'])['lines']);
        $payload = json_decode((string) $this->connection->fetchOne('SELECT payload FROM outbox WHERE order_id = ?', [$result['order_id']]), true);
        // MySQL JSON reorders keys and stores 5.0 as 5: compare values.
        $this->assertEquals([
            ['id_product' => 0, 'id_product_attribute' => 0, 'quantity' => 1, 'price' => 5.0, 'free_item' => true, 'concept' => 'Arreglo', 'vat_rate' => 21.0, 'slot' => 1],
            ['id_product' => 0, 'id_product_attribute' => 0, 'quantity' => 1, 'price' => 7.5, 'free_item' => true, 'concept' => 'Arreglo', 'vat_rate' => 21.0, 'slot' => 2],
            ['id_product' => 0, 'id_product_attribute' => 0, 'quantity' => 1, 'price' => 30.0, 'free_item' => true, 'concept' => 'Cuota', 'vat_rate' => 10.0, 'slot' => 1],
        ], $payload['products']);
    }

    public function testTheConceptIsTrimmedAndItsRepeatedSpacesJoined(): void
    {
        $result = $this->service()->createOrder(1, self::cart([self::free("  Arreglo   de\tbañador  ")], 12.0), 'card', 0.0);

        $this->assertSame('Arreglo de bañador', $this->breakdown($result['order_id'])['lines'][0]['name']);
    }

    public function testTheSameRequestIdReturnsTheSameSale(): void
    {
        $cart = self::cart([self::free()], 12.0);
        $first = $this->service()->createOrder(1, $cart, 'card', 0.0, 0.0, 'free-item-attempt-1');
        $again = $this->service()->createOrder(1, $cart, 'card', 0.0, 0.0, 'free-item-attempt-1');

        $this->assertSame($first['order_id'], $again['order_id']);
        $this->assertTrue($again['replayed']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM order_meta WHERE session_id = ?', [self::SESSION_ID]));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: float, 2: string}> */
    public static function invalidLines(): iterable
    {
        yield 'empty concept' => [self::free('   '), 12.0, 'El concepto es obligatorio'];
        yield 'concept of 101 characters' => [self::free(str_repeat('a', 101)), 12.0, 'El concepto no puede tener más de 100 caracteres'];
        yield 'forbidden character' => [self::free('Arreglo <b>'), 12.0, 'El concepto no puede llevar < > ; = # { }'];
        yield 'emoji' => [self::free('Arreglo 🩱'), 12.0, 'El concepto tiene caracteres que no se pueden imprimir'];
        yield 'control character' => [self::free("Arreglo\x07"), 12.0, 'El concepto tiene caracteres que no se pueden imprimir'];
        yield 'price zero' => [self::free(price: 0), 0.0, 'El precio debe estar entre 0,01 € y 1000,00 €'];
        yield 'price above the maximum' => [self::free(price: 1000.01), 1000.01, 'El precio debe estar entre 0,01 € y 1000,00 €'];
        yield 'price with three decimals' => [self::free(price: 1.005), 1.01, 'El precio no puede tener más de 2 decimales'];
        yield 'price not a number' => [self::free(price: 'doce'), 0.0, 'El precio debe estar entre 0,01 € y 1000,00 €'];
        yield 'rate not configured' => [self::free(rate: 4), 12.0, 'El tipo de IVA 4 % no está configurado'];
        yield 'rate missing' => [self::free(rate: null), 12.0, 'El tipo de IVA  % no está configurado'];
        yield 'quantity zero' => [self::free(qty: 0), 0.0, 'La cantidad debe ser un número entero entre 1 y 999'];
        yield 'quantity above 999' => [self::free(qty: 1000), 12000.0, 'La cantidad debe ser un número entero entre 1 y 999'];
        yield 'quantity not whole' => [self::free(qty: 1.5), 18.0, 'La cantidad debe ser un número entero entre 1 y 999'];
        yield 'with a catalog product' => [['product_id' => self::PRODUCT] + self::free(), 12.0, 'Un artículo libre no puede llevar producto'];
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('invalidLines')]
    public function testAnInvalidFreeItemIsA422WithTheReasonAndStoresNothing(array $line, float $total, string $reason): void
    {
        try {
            $this->service()->createOrder(1, self::cart([$line], $total), 'card', 0.0);
            $this->fail('expected a 422');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame($reason, $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testMoreThanTwentyFreeItemsOfTheSameRateAreRefused(): void
    {
        $lines = array_fill(0, 21, self::free('Arreglo', 1.0));
        try {
            $this->service()->createOrder(1, self::cart($lines, 21.0), 'card', 0.0);
            $this->fail('expected a 422');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('Máximo 20 artículos libres del mismo IVA por ticket', $e->getMessage());
        }
        $this->assertNothingStored();

        $result = $this->service()->createOrder(1, self::cart(array_fill(0, 20, self::free('Arreglo', 1.0)), 20.0), 'card', 0.0);
        $this->assertCount(20, $this->breakdown($result['order_id'])['lines']);
    }

    public function testACouponCannotBeUsedWithAFreeItem(): void
    {
        try {
            $this->service()->createOrder(1, self::cart([self::free()], 12.0) + ['coupon' => ['code' => 'X']], 'card', 0.0);
            $this->fail('expected a 422');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('No se puede usar un cupón con un artículo libre', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testTheSettingsDecideWhoMayUseItItsMaximumAndItsRates(): void
    {
        $this->setting('role', 'admin');
        try {
            $this->service()->createOrder(1, self::cart([self::free()], 12.0), 'card', 0.0);
            $this->fail('expected a 403');
        } catch (CheckoutException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Solo un administrador puede añadir un artículo libre', $e->getMessage());
        }
        $this->assertNothingStored();
        $_SESSION['role'] = 'admin';
        $this->service()->createOrder(1, self::cart([self::free()], 12.0), 'card', 0.0);

        $this->setting('max_price', '2000.00');
        $this->setting('vat_rates', '21,4');
        $result = $this->service()->createOrder(1, self::cart([self::free('Bicicleta', 1500.0, 4)], 1500.0), 'card', 0.0);
        $this->assertSame(57.69, $this->breakdown($result['order_id'])['tax'], '1500 − 1500/1,04');

        $this->setting('enabled', '0');
        try {
            $this->service()->createOrder(1, self::cart([self::free()], 12.0), 'card', 0.0);
            $this->fail('expected a 422');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('El artículo libre no está activado', $e->getMessage());
        }
    }

    public function testSettingsDefaultToEnabledForEveryoneUpToAThousandAtTwentyOneAndTen(): void
    {
        $this->assertSame(
            ['enabled' => true, 'role' => 'all', 'max_price' => 1000.0, 'vat_rates' => [21.0, 10.0]],
            (new FreeItemSettingsStore($this->connection))->load()->toArray()
        );
        $this->setting('enabled', '0');
        $this->setting('role', 'admin');
        $this->setting('max_price', '250.5');
        $this->setting('vat_rates', '10, 21, 99, x');
        $this->assertSame(
            ['enabled' => false, 'role' => 'admin', 'max_price' => 250.5, 'vat_rates' => [10.0, 21.0]],
            (new FreeItemSettingsStore($this->connection))->load()->toArray(),
            'unknown rates are dropped'
        );
    }

    public function testTheUploadOfASaleWithAFreeItemWaitsUntilPrestaShopIsSetUp(): void
    {
        $result = $this->service()->createOrder(1, self::cart([self::free()], 12.0), 'card', 0.0);
        $this->connection->executeStatement('UPDATE outbox SET next_attempt_at = ? WHERE order_id = ?', ['2000-01-01 00:00:00', $result['order_id']]);
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createReferencedOrder');

        (new OutboxSync($this->connection, $client))->run();

        $row = $this->connection->fetchAssociative('SELECT status, last_error FROM outbox WHERE order_id = ?', [$result['order_id']]);
        $this->assertSame('pending', $row['status'], 'retried, not an incident');
        $this->assertSame('Falta configurar el artículo libre en PrestaShop', $row['last_error']);
    }

    /** The ids the module answered for the test shop (POST /module/tpvconnect/freeitem). */
    private function setUpGenericProducts(array $rates = ['21.00' => 22, '10.00' => 23]): void
    {
        $map = [];
        foreach ($rates as $rate => $product) {
            $map[$rate] = ['id_product' => $product, 'slots' => array_combine(range(1, 20), range($product * 100 + 1, $product * 100 + 20))];
        }
        (new FreeItemSettingsStore($this->connection))->savePsProducts($map);
    }

    private function makeDue(int $orderId): void
    {
        $this->connection->executeStatement('UPDATE outbox SET next_attempt_at = ? WHERE order_id = ?', ['2000-01-01 00:00:00', $orderId]);
    }

    public function testTheUploadSendsEachFreeLineOnTheSlotOfTheGenericProductOfItsRate(): void
    {
        $this->setUpGenericProducts();
        $lines = [self::free('Arreglo', 5.0), ['product_id' => self::PRODUCT, 'name' => 'Camiseta', 'price' => self::PRICE, 'qty' => 1], self::free('Arreglo', 7.5), self::free('Cuota', 30.0, 10)];
        $result = $this->service()->createOrder(1, self::cart($lines, 54.6), 'card', 0.0);
        $this->makeDue($result['order_id']);
        $sent = null;
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())->method('createReferencedOrder')->willReturnCallback(static function (array $payload) use (&$sent): array {
            $sent = $payload;

            return ['order_id' => 9471001, 'reference' => $payload['reference'], 'total_paid' => 54.6, 'replayed' => false];
        });

        (new OutboxSync($this->connection, $client))->run();

        $this->assertEquals([
            ['id_product' => 22, 'id_product_attribute' => 2201, 'quantity' => 1, 'price' => 5.0, 'free_item' => true, 'concept' => 'Arreglo', 'vat_rate' => 21.0, 'slot' => 1],
            ['id_product' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 1, 'price' => self::PRICE],
            ['id_product' => 22, 'id_product_attribute' => 2202, 'quantity' => 1, 'price' => 7.5, 'free_item' => true, 'concept' => 'Arreglo', 'vat_rate' => 21.0, 'slot' => 2],
            ['id_product' => 23, 'id_product_attribute' => 2301, 'quantity' => 1, 'price' => 30.0, 'free_item' => true, 'concept' => 'Cuota', 'vat_rate' => 10.0, 'slot' => 1],
        ], $sent['products']);
        $this->assertSame('done', $this->connection->fetchOne('SELECT status FROM outbox WHERE order_id = ?', [$result['order_id']]));
        $stored = json_decode((string) $this->connection->fetchOne('SELECT payload FROM outbox WHERE order_id = ?', [$result['order_id']]), true);
        $this->assertSame(0, $stored['products'][0]['id_product'], 'the stored upload keeps the slot, not the ids');
    }

    public function testTheUploadWaitsWhenTheSetupLacksTheRateOfAFreeLine(): void
    {
        $this->setUpGenericProducts(['21.00' => 22]);
        $result = $this->service()->createOrder(1, self::cart([self::free('Arreglo', 5.0), self::free('Cuota', 30.0, 10)], 35.0), 'card', 0.0);
        $this->makeDue($result['order_id']);
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createReferencedOrder');

        (new OutboxSync($this->connection, $client))->run();

        $row = $this->connection->fetchAssociative('SELECT status, last_error FROM outbox WHERE order_id = ?', [$result['order_id']]);
        $this->assertSame(['status' => 'pending', 'last_error' => 'Falta configurar el artículo libre en PrestaShop'], $row);
    }

    public function testACatalogLineWithTheIdOfAGenericProductIsA422AndStoresNothing(): void
    {
        $this->setUpGenericProducts(['21.00' => self::PRODUCT]);

        try {
            $this->service()->createOrder(1, self::cart([['product_id' => self::PRODUCT, 'name' => 'Camiseta', 'price' => self::PRICE, 'qty' => 1]], self::PRICE), 'card', 0.0);
            $this->fail('Expected a 422');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('El producto ' . self::PRODUCT . ' es el artículo libre de PrestaShop y no se puede vender como producto', $e->getMessage());
        }
        $this->assertNothingStored();
    }
}
