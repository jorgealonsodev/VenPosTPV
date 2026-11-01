<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Sync\SyncLogRepository;

final class CheckoutServiceTest extends TestCase
{
    private function createService(
        ?CartService $cart = null,
        ?PsOrderClient $client = null,
        ?Connection $conn = null,
        ?CashSessionService $cashSessionService = null,
        ?MessageBusInterface $messageBus = null,
        ?PrinterService $printerService = null,
        ?PrestaShopApiClient $psApiClient = null,
        ?SyncLogRepository $syncLogRepo = null,
    ): CheckoutService {
        return new CheckoutService(
            $cart ?? $this->createMock(CartService::class),
            $client ?? $this->createMock(PsOrderClient::class),
            $conn ?? $this->createMock(Connection::class),
            $cashSessionService ?? $this->createMock(CashSessionService::class),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            $printerService ?? $this->createMock(PrinterService::class),
            $psApiClient ?? $this->createMock(PrestaShopApiClient::class),
            $syncLogRepo ?? $this->createMock(SyncLogRepository::class),
        );
    }

    public function testEmptyCartThrowsValidationException(): void
    {
        $service = $this->createService();

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Cart is empty');

        $service->createOrder(1, ['lines' => []], 'cash', 50.0);
    }

    public function testZeroStockThrowsValidationException(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturn(0);

        $service = $this->createService(conn: $conn);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 10.0],
            ],
            'total' => 10.0,
        ], 'cash', 10.0);
    }

    public function testSuccessfulCheckoutReturnsOrderDetails(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->willReturn([
                'order_id' => 42,
                'reference' => 'ORD-042',
                'total_paid' => 99.99,
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')
            ->willReturn(10);
        $conn->expects($this->once())
            ->method('insert')
            ->with('order_meta', $this->anything());
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE catalog_stock SET quantity = quantity - ? WHERE id_product = ? AND id_product_attribute = 0 AND quantity >= ?',
                [2, 1, 2]
            )
            ->willReturn(1);

        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);
        $cashSessionService->expects($this->once())->method('addMovement');

        $service = $this->createService(cart: $cart, client: $client, conn: $conn, cashSessionService: $cashSessionService);

        $result = $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 2, 'price' => 49.995],
            ],
            'total' => 99.99,
        ], 'cash', 100.0);

        $this->assertSame(42, $result['ps_order_id']);
        $this->assertSame('ORD-042', $result['reference']);
        $this->assertSame(99.99, $result['total']);
        $this->assertSame(0.01, $result['change_due']);
        $this->assertSame('cash', $result['payment_method']);
    }

    public function testMissingCashSessionStopsBeforePsOrderAndKeepsCart(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->expects($this->once())
            ->method('requireOpenSession')
            ->with(1)
            ->willThrowException(new NoOpenCashSessionException());

        $cart = $this->createMock(CartService::class);
        $cart->expects($this->never())->method('clear');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);

        $service = $this->createService(
            cart: $cart,
            client: $client,
            conn: $conn,
            cashSessionService: $cashSessionService,
        );

        $this->expectException(NoOpenCashSessionException::class);
        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]],
            'total' => 10.0,
        ], 'cash', 10.0);
    }

    public function testCashSessionClosureAfterPsOrderReturnsCreatedOrderAndCompletesLocalSaga(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->willReturn([
                'order_id' => 77,
                'reference' => 'ORD-077',
                'total_paid' => 10.0,
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $insertedTables = [];
        $conn->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table) use (&$insertedTables): int {
                $insertedTables[] = $table;

                return 1;
            });
        $conn->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->expects($this->once())
            ->method('requireOpenSession')
            ->willReturn(['session_id' => 12, 'terminal_id' => 3, 'shop_id' => 1, 'user_id' => 1]);
        $cashSessionService->expects($this->once())
            ->method('addMovement')
            ->willThrowException(new NoOpenCashSessionException());

        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');

        $service = $this->createService(
            cart: $cart,
            client: $client,
            conn: $conn,
            cashSessionService: $cashSessionService,
        );

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]],
            'total' => 10.0,
        ], 'cash', 10.0);

        $this->assertSame(77, $result['ps_order_id']);
        $this->assertSame('ORD-077', $result['reference']);
        $this->assertTrue($result['saga_error']);
        $this->assertSame(['order_meta', 'audit_log'], $insertedTables);
    }

    public function testSagaLogsOrphanWhenOrderMetaFails(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->willReturn([
                'order_id' => 99,
                'reference' => 'ORD-099',
                'total_paid' => 50.0,
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);

        $callCount = 0;
        $conn->method('insert')
            ->willReturnCallback(function (string $table) use (&$callCount): int {
                ++$callCount;
                if ($table === 'order_meta' && $callCount === 1) {
                    throw new \RuntimeException('DB deadlock');
                }

                return 1;
            });

        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');

        $service = $this->createService(cart: $cart, client: $client, conn: $conn);

        $result = $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 50.0],
            ],
            'total' => 50.0,
        ], 'cash', 50.0);

        $this->assertSame(99, $result['ps_order_id']);
        $this->assertTrue($result['saga_error']);
    }

    public function testSagaLogsOrphanWhenStockUpdateFails(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->willReturn([
                'order_id' => 88,
                'reference' => 'ORD-088',
                'total_paid' => 30.0,
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);

        $callCount = 0;
        $conn->method('executeStatement')
            ->willReturnCallback(function () use (&$callCount): int {
                ++$callCount;
                if ($callCount === 1) {
                    throw new \RuntimeException('Lock timeout');
                }

                return 1;
            });

        $conn->method('insert')->willReturn(1);

        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');

        $service = $this->createService(cart: $cart, client: $client, conn: $conn);

        $result = $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 30.0],
            ],
            'total' => 30.0,
        ], 'cash', 30.0);

        $this->assertSame(88, $result['ps_order_id']);
        $this->assertTrue($result['saga_error']);
    }

    public function testClearCartIsCalledAfterSuccess(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn([
            'order_id' => 1,
            'reference' => 'REF',
            'total_paid' => 10.0,
        ]);

        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $service = $this->createService(cart: $cart, client: $client, conn: $conn);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 10.0],
            ],
            'total' => 10.0,
        ], 'cash', 10.0);
    }

    public function testCustomerResolutionUsesCartCustomerId(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(function (array $payload): bool {
                return $payload['id_customer'] === 5;
            }))
            ->willReturn([
                'order_id' => 1,
                'reference' => 'REF',
                'total_paid' => 10.0,
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 10.0],
            ],
            'total' => 10.0,
            'customer' => ['id' => 5, 'name' => 'Juan'],
        ], 'cash', 10.0);
    }

    public function testAnonymousCustomerSendsZero(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(function (array $payload): bool {
                return $payload['id_customer'] === 0;
            }))
            ->willReturn([
                'order_id' => 1,
                'reference' => 'REF',
                'total_paid' => 10.0,
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 10.0],
            ],
            'total' => 10.0,
            'customer' => ['name' => 'Consumidor final', 'default' => true],
        ], 'cash', 10.0);
    }

    public function testMixedPaymentRegistersOnlyCashAmountInDrawerMovement(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn([
            'order_id' => 501,
            'reference' => 'MIX-501',
            'total_paid' => 50.0,
        ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);
        $cashSessionService->expects($this->once())
            ->method('addMovement')
            ->with(
                1,
                'sale',
                20.0,
                'Checkout sale',
                501
            );

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 50.0],
            ],
            'total' => 50.0,
            'cash_amount' => 20.0,
        ], 'mixed', 50.0);
    }

    public function testMixedPaymentWithoutCashAmountDoesNotCreateCashMovement(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn([
            'order_id' => 601,
            'reference' => 'MIX-601',
            'total_paid' => 50.0,
        ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);
        $cashSessionService->expects($this->never())->method('addMovement');

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 50.0],
            ],
            'total' => 50.0,
        ], 'mixed', 50.0);
    }

    public function testCashPaymentOpensDrawer(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn([
            'order_id' => 701,
            'reference' => 'CASH-701',
            'total_paid' => 10.0,
        ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);

        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())
            ->method('openDrawer')
            ->with(3);

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService, printerService: $printerService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 10.0],
            ],
            'total' => 10.0,
        ], 'cash', 10.0);
    }

    public function testMixedPaymentOpensDrawer(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn([
            'order_id' => 702,
            'reference' => 'MIX-702',
            'total_paid' => 50.0,
        ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);

        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->once())
            ->method('openDrawer')
            ->with(3);

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService, printerService: $printerService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 50.0],
            ],
            'total' => 50.0,
            'cash_amount' => 20.0,
        ], 'mixed', 50.0);
    }

    public function testCardPaymentDoesNotOpenDrawer(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn([
            'order_id' => 703,
            'reference' => 'CARD-703',
            'total_paid' => 10.0,
        ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);

        $printerService = $this->createMock(PrinterService::class);
        $printerService->expects($this->never())->method('openDrawer');

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService, printerService: $printerService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 10.0],
            ],
            'total' => 10.0,
        ], 'card', 10.0);
    }

    /**
     * A catalog row is either a plain base price string or a full row map
     * (e.g. ['price' => '23.90', 'price_ttc' => '23.14']).
     *
     * @param array<string, string|array<string, string|null>|null> $catalogPrices
     */

    public function testOrderMetaRecordsTheExactVatOfEachLine(): void
    {
        $conn = $this->catalogConnection([
            '1' => ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12],
        ], 10);

        $inserted = [];
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('fetchAssociative')->willReturn(['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12]);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('insert')->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
            $inserted[$table] = $data;

            return 1;
        });

        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn(['order_id' => 7, 'reference' => 'ORD-007', 'total_paid' => 46.28]);

        $service = $this->createService(client: $client, conn: $conn);

        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 2, 'price' => 23.14]],
            'total' => 46.28,
        ], 'cash', 50.0);

        $breakdown = json_decode($inserted['order_meta']['payment_breakdown'], true);

        $this->assertSame(46.28, $breakdown['subtotal']);
        // 2 units x (23.14 tax included - 19.12 tax excluded) = 8.04 of VAT, taken
        // from the shop prices instead of an assumed rate.
        $this->assertSame(8.04, $breakdown['tax']);
    }

    private function catalogConnection(array $catalogPrices, int $stock = 10): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn($stock);
        $conn->method('fetchAssociative')
            ->willReturnCallback(function (string $sql, array $params = []) use ($catalogPrices): array|false {
                $key = (string) ($params[0] ?? '');
                $row = $catalogPrices[$key] ?? null;
                if ($row === null) {
                    return false;
                }
                if (str_contains($sql, 'FROM catalog_product')) {
                    return is_array($row) ? $row : ['price' => $row];
                }
                if (str_contains($sql, 'FROM catalog_combination')) {
                    return is_array($row) ? $row : false;
                }

                return false;
            });
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);

        return $conn;
    }

    public function testRejectsSubmittedPriceAboveDeclaredCatalogPriceDifference(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => '9.00']),
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Price mismatch for product 1');

        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 0.01]],
            'total' => 0.01,
        ], 'cash', 0.01);
    }

    public function testAcceptsSubmittedPriceMatchingCatalogPrice(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 9.0;
            }))
            ->willReturn(['order_id' => 501, 'reference' => 'ORD-501', 'total_paid' => 18.0]);

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => '9.00']),
        );

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 2, 'price' => 9.0]],
            'total' => 18.0,
        ], 'card', 18.0);

        $this->assertSame(18.0, $result['total']);
    }

    public function testCombinationPriceImpactIsAddedToExpectedUnitPrice(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection([
                '1' => '9.00',
                '7' => ['price_impact' => '2.50'],
            ]),
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Price mismatch for product 1 combination 7');

        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'id_product_attribute' => 7, 'qty' => 1, 'price' => 9.0]],
            'total' => 9.0,
        ], 'cash', 9.0);
    }

    public function testRejectsSubmittedTotalThatDoesNotMatchCatalogPrices(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => '9.00']),
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Total mismatch: expected 18.00, received 1.00');

        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 2, 'price' => 9.0]],
            'total' => 1.0,
        ], 'cash', 1.0);
    }

    public function testGlobalPercentageDiscountIsAppliedToAuthoritativeTotal(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn(['order_id' => 502, 'reference' => 'ORD-502', 'total_paid' => 16.2]);

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => '9.00']),
        );

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 2, 'price' => 9.0]],
            'discount' => ['type' => 'pct', 'value' => 10],
            'total' => 16.2,
        ], 'card', 16.2);

        $this->assertSame(16.2, $result['total']);
    }

    public function testOutOfRangePercentageDiscountIsRejected(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => '9.00']),
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Invalid discount');

        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 9.0]],
            'discount' => ['type' => 'pct', 'value' => 150],
            'total' => 0.0,
        ], 'cash', 0.0);
    }

    public function testAcceptsSubmittedTtcPriceAndBooksTheTtcTotal(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 23.14;
            }))
            ->willReturn(['order_id' => 504, 'reference' => 'ORD-504', 'total_paid' => 46.28]);

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => ['price' => '23.90', 'price_ttc' => '23.14']]),
        );

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 2, 'price' => 23.14]],
            'total' => 46.28,
        ], 'card', 46.28);

        $this->assertSame(46.28, $result['total']);
    }

    public function testRejectsTaxExcludedBasePriceWhenTtcPriceIsKnown(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => ['price' => '23.90', 'price_ttc' => '23.14']]),
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Price mismatch for product 1 (expected 23.14, received 23.90)');

        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.90]],
            'total' => 23.90,
        ], 'cash', 23.90);
    }

    public function testNullTtcPriceKeepsTheBasePriceFallback(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 9.0;
            }))
            ->willReturn(['order_id' => 505, 'reference' => 'ORD-505', 'total_paid' => 9.0]);

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => ['price' => '9.00', 'price_ttc' => null]]),
        );

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 9.0]],
            'total' => 9.0,
        ], 'card', 9.0);

        $this->assertSame(9.0, $result['total']);
    }

    public function testCombinationTtcPriceIsTheAbsoluteUnitPrice(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 25.50;
            }))
            ->willReturn(['order_id' => 506, 'reference' => 'ORD-506', 'total_paid' => 25.50]);

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection([
                '1' => ['price' => '23.90', 'price_ttc' => '23.14'],
                '7' => ['price_impact' => '0.76', 'price_ttc' => '25.50'],
            ]),
        );

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'id_product_attribute' => 7, 'qty' => 1, 'price' => 25.50]],
            'total' => 25.50,
        ], 'card', 25.50);

        $this->assertSame(25.50, $result['total']);
    }

    public function testCombinationWithoutTtcPriceFallsBackToBasePricePlusImpact(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())
            ->method('createOrder')
            ->with($this->callback(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 11.5;
            }))
            ->willReturn(['order_id' => 507, 'reference' => 'ORD-507', 'total_paid' => 11.5]);

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection([
                '1' => ['price' => '9.00', 'price_ttc' => null],
                '7' => ['price_impact' => '2.50', 'price_ttc' => null],
            ]),
        );

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'id_product_attribute' => 7, 'qty' => 1, 'price' => 11.5]],
            'total' => 11.5,
        ], 'card', 11.5);

        $this->assertSame(11.5, $result['total']);
    }

    public function testNonNumericTtcPriceFallsBackToTheBasePrice(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection(['1' => ['price' => '9.00', 'price_ttc' => '']]),
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Price mismatch for product 1 (expected 9.00, received 0.01)');

        $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 0.01]],
            'total' => 0.01,
        ], 'cash', 0.01);
    }

    public function testProductAbsentFromCatalogIsRejectedInsteadOfTrustingTheSubmittedPrice(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $service = $this->createService(
            client: $client,
            conn: $this->catalogConnection([], stock: 10),
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Product 999 is not in the synced catalog');

        $service->createOrder(1, [
            'lines' => [['product_id' => 999, 'qty' => 1, 'price' => 12.0]],
            'total' => 12.0,
        ], 'card', 12.0);
    }
}
