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
use VenPosTPV\Service\Print\PrinterService;

final class CheckoutServiceTest extends TestCase
{
    private function createService(
        ?CartService $cart = null,
        ?PsOrderClient $client = null,
        ?Connection $conn = null,
        ?CashSessionService $cashSessionService = null,
        ?MessageBusInterface $messageBus = null,
        ?PrinterService $printerService = null,
        bool $couponCheckoutEnabled = false,
    ): CheckoutService {
        return new CheckoutService(
            $cart ?? $this->createMock(CartService::class),
            $client ?? $this->createMock(PsOrderClient::class),
            $conn ?? $this->createMock(Connection::class),
            $cashSessionService ?? $this->createMock(CashSessionService::class),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            $printerService ?? $this->createMock(PrinterService::class),
            $couponCheckoutEnabled,
        );
    }

    public function testCouponPreviewGateOffDoesNotContactPrestaShopOrCreateLocalEffects(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('previewCoupon');
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->expects($this->never())->method('requireOpenSession');
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->never())->method('clear');

        $service = $this->createService(cart: $cart, client: $client, cashSessionService: $cashSession);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('coupon_unavailable');
        $service->previewCoupon(1, ['lines' => []], 'SAVE10');
    }

    public function testCouponPreviewUsesLocalGrossAndNativeAuthoritativePayable(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())->method('previewCoupon')
            ->with($this->callback(static fn (array $payload): bool => $payload['operation'] === 'coupon_preview' && $payload['coupon_code'] === 'SAVE10' && $payload['products'][0]['price'] === 23.14))
            ->willReturn([
                'operation' => 'coupon_preview', 'cart_id' => 7,
                'before_discount_total' => 23.14, 'discount_amount' => 10.0, 'total_paid' => 13.14,
                'coupon' => ['code' => 'SAVE10', 'expires_at' => '2026-12-31 23:59:59'], 'quote' => 'signed.quote',
            ]);
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->expects($this->once())->method('requireOpenSession')->with(1)->willReturn(['session_id' => 1, 'terminal_id' => 1]);
        $conn = $this->catalogConnection(['1' => ['price' => 23.90, 'price_ttc' => 23.14]], 10);
        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSession, couponCheckoutEnabled: true);

        $preview = $service->previewCoupon(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
            'customer' => ['id' => 2],
        ], 'SAVE10');

        $this->assertSame(13.14, $preview['total_paid']);
    }

    public function testCouponPreviewRejectsManualDiscountBeforePrestaShopCall(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('previewCoupon');
        $service = $this->createService(client: $client, couponCheckoutEnabled: true);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Remove the manual discount');
        $service->previewCoupon(1, ['discount' => ['type' => 'pct', 'value' => 10]], 'SAVE10');
    }

    public function testAnonymousCouponCheckoutBindsCreationToSignedNativeCustomer(): void
    {
        $quote = [
            'cart_id' => 7, 'customer_id' => 42, 'shop_id' => 1, 'currency_id' => 1,
            'coupon_code' => 'SAVE10', 'before_discount_total' => 23.14,
            'discount_amount' => 10.0, 'total_paid' => 13.14,
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
        ];
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn($quote);
        $client->expects($this->once())->method('createCouponOrder')->with($this->callback(static fn (array $payload): bool => $payload['id_customer'] === 42))->willReturn(['order_id' => 76, 'reference' => 'CPN-76', 'total_paid' => 13.14]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(static function (string $sql): array|false {
            if (str_contains($sql, 'catalog_product')) return ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12];
            return false;
        });
        $conn->method('fetchOne')->willReturnCallback(static function (string $sql): int|false {
            if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) return 1;
            if (str_contains($sql, 'order_meta')) return false;
            return 10;
        });
        $conn->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 3]);
        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSession, couponCheckoutEnabled: true);

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
            'coupon' => ['code' => 'SAVE10', 'preview_cart_id' => 7, 'quote' => 'signed.quote'],
        ], 'card', 13.14);

        $this->assertSame(76, $result['ps_order_id']);
    }

    public function testCouponCashCheckoutBooksOnlyNetPayableAndDiscountMetadata(): void
    {
        $quote = [
            'cart_id' => 7, 'customer_id' => 2, 'shop_id' => 1, 'currency_id' => 1,
            'coupon_code' => 'SAVE10', 'before_discount_total' => 23.14,
            'discount_amount' => 10.0, 'total_paid' => 13.14,
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
        ];
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())->method('verifyCouponQuote')->with('signed.quote')->willReturn($quote);
        $client->expects($this->once())->method('createCouponOrder')->willReturn(['order_id' => 77, 'reference' => 'CPN-77', 'total_paid' => 13.14]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(static function (string $sql): array|false {
            if (str_contains($sql, 'catalog_product')) return ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12];
            return false;
        });
        $conn->method('fetchOne')->willReturnCallback(static function (string $sql): int|false {
            if (str_contains($sql, 'GET_LOCK')) return 1;
            if (str_contains($sql, 'order_meta')) return false;
            if (str_contains($sql, 'RELEASE_LOCK')) return 1;
            return 10;
        });
        $conn->method('executeStatement')->willReturn(1);
        $conn->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $inserted = [];
        $conn->expects($this->exactly(2))->method('insert')->willReturnCallback(function (string $table, array $values) use (&$inserted): int {
            if ($table === 'order_meta') {
                $inserted[$table] = $values;
            }
            return 1;
        });
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 3]);
        $cashSession->expects($this->never())->method('addMovement');
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');
        $service = $this->createService(cart: $cart, client: $client, conn: $conn, cashSessionService: $cashSession, couponCheckoutEnabled: true);

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
            'customer' => ['id' => 2],
            'coupon' => ['code' => 'SAVE10', 'preview_cart_id' => 7, 'quote' => 'signed.quote'],
        ], 'cash', 20.0);

        $breakdown = json_decode($inserted['order_meta']['payment_breakdown'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(13.14, $result['total']);
        $this->assertSame(6.86, $result['change_due']);
        $this->assertSame(23.14, $breakdown['coupon']['gross_total']);
        $this->assertEquals(10.0, $breakdown['coupon']['discount_amount']);
        $this->assertSame(13.14, $breakdown['coupon']['cash_collected']);
    }

    public function testCouponLocalAccountingUsesOneSharedTransactionInsteadOfSeparateCashSaga(): void
    {
        $quote = [
            'cart_id' => 7, 'customer_id' => 2, 'shop_id' => 1, 'currency_id' => 1,
            'coupon_code' => 'SAVE10', 'before_discount_total' => 23.14,
            'discount_amount' => 10.0, 'total_paid' => 13.14,
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
        ];
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn($quote);
        $client->method('createCouponOrder')->willReturn(['order_id' => 78, 'reference' => 'CPN-78', 'total_paid' => 13.14]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(static function (string $sql): array|false {
            if (str_contains($sql, 'catalog_product')) return ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12];
            return false;
        });
        $conn->method('fetchOne')->willReturnCallback(static function (string $sql): int|false {
            if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) return 1;
            if (str_contains($sql, 'order_meta')) return false;
            return 10;
        });
        $conn->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willReturn(1);
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 3]);
        $cashSession->expects($this->never())->method('addMovement');
        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSession, couponCheckoutEnabled: true);

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
            'customer' => ['id' => 2], 'coupon' => ['code' => 'SAVE10', 'preview_cart_id' => 7, 'quote' => 'signed.quote'],
        ], 'cash', 13.14);

        $this->assertFalse($result['saga_error']);
    }

    public function testCouponMovementFailureRollsBackLocalEffectsAndNativeReplayCompletesThem(): void
    {
        $quote = [
            'cart_id' => 7, 'customer_id' => 2, 'shop_id' => 1, 'currency_id' => 1,
            'coupon_code' => 'SAVE10', 'before_discount_total' => 23.14,
            'discount_amount' => 10.0, 'total_paid' => 13.14,
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
        ];
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->exactly(2))->method('verifyCouponQuote')->willReturn($quote);
        $client->expects($this->exactly(2))->method('createCouponOrder')->willReturn(['order_id' => 79, 'reference' => 'CPN-79', 'total_paid' => 13.14]);
        $metadata = false;
        $movementWrites = 0;
        $stockWrites = 0;
        $failMovement = true;
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(static function (string $sql): array|false {
            if (str_contains($sql, 'catalog_product')) return ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12];
            return false;
        });
        $conn->method('fetchOne')->willReturnCallback(function (string $sql) use (&$metadata): int|false {
            if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK') || str_contains($sql, 'FROM session')) return 1;
            if (str_contains($sql, 'order_meta')) return $metadata ? 1 : false;
            return 10;
        });
        $conn->expects($this->exactly(2))->method('transactional')->willReturnCallback(function (callable $operation) use (&$metadata, &$movementWrites, &$stockWrites): mixed {
            $snapshot = [$metadata, $movementWrites, $stockWrites];
            try {
                return $operation();
            } catch (\Throwable $e) {
                [$metadata, $movementWrites, $stockWrites] = $snapshot;
                throw $e;
            }
        });
        $conn->method('insert')->willReturnCallback(function (string $table) use (&$metadata, &$movementWrites, &$failMovement): int {
            if ($table === 'order_meta') {
                $metadata = true;
            }
            if ($table === 'movement') {
                if ($failMovement) {
                    throw new \RuntimeException('movement write failed');
                }
                ++$movementWrites;
            }
            return 1;
        });
        $conn->method('executeStatement')->willReturnCallback(function () use (&$stockWrites): int {
            ++$stockWrites;
            return 1;
        });
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 3]);
        $cashSession->expects($this->never())->method('addMovement');
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->once())->method('openDrawer')->with(3);
        $service = $this->createService(cart: $cart, client: $client, conn: $conn, cashSessionService: $cashSession, printerService: $printer, couponCheckoutEnabled: true);
        $cartData = [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
            'customer' => ['id' => 2], 'coupon' => ['code' => 'SAVE10', 'preview_cart_id' => 7, 'quote' => 'signed.quote'],
        ];

        $failed = $service->createOrder(1, $cartData, 'cash', 13.14);
        $this->assertTrue($failed['saga_error']);
        $this->assertFalse($metadata);
        $this->assertSame(0, $movementWrites);
        $this->assertSame(0, $stockWrites);

        $failMovement = false;
        $completed = $service->createOrder(1, $cartData, 'cash', 13.14);
        $this->assertFalse($completed['saga_error']);
        $this->assertTrue($metadata);
        $this->assertSame(1, $movementWrites);
        $this->assertSame(1, $stockWrites);
    }

    public function testCouponNativeReplayDoesNotDuplicateLocalMetadataCashOrStock(): void
    {
        $quote = [
            'cart_id' => 7, 'customer_id' => 2, 'shop_id' => 1, 'currency_id' => 1,
            'coupon_code' => 'SAVE10', 'before_discount_total' => 23.14,
            'discount_amount' => 10.0, 'total_paid' => 13.14,
            'products' => [['id_product' => 1, 'id_product_attribute' => 0, 'quantity' => 1]],
        ];
        $client = $this->createMock(PsOrderClient::class);
        $client->method('verifyCouponQuote')->willReturn($quote);
        $client->method('createCouponOrder')->willReturn(['order_id' => 77, 'reference' => 'CPN-77', 'total_paid' => 13.14]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12]);
        $conn->method('fetchOne')->willReturnCallback(static function (string $sql): int {
            if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) return 1;
            if (str_contains($sql, 'order_meta')) return 1;
            return 10;
        });
        $conn->expects($this->once())->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $conn->expects($this->never())->method('insert');
        $conn->expects($this->never())->method('executeStatement');
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 3]);
        $cashSession->expects($this->never())->method('addMovement');
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');
        $service = $this->createService(cart: $cart, client: $client, conn: $conn, cashSessionService: $cashSession, couponCheckoutEnabled: true);

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
            'customer' => ['id' => 2], 'coupon' => ['code' => 'SAVE10', 'preview_cart_id' => 7, 'quote' => 'signed.quote'],
        ], 'card', 13.14);

        $this->assertFalse($result['saga_error']);
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

    public function testCheckoutNeverWritesStockToPrestaShop(): void
    {
        // PrestaShop lowers stock_available itself when the order is created, so a
        // push from the TPV would count the sale twice or erase online sales.
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn(['order_id' => 42, 'reference' => 'REF', 'total_paid' => 20.0]);
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 4]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $localStockUpdates = [];
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) use (&$localStockUpdates): int {
            if (str_contains($sql, 'UPDATE catalog_stock')) {
                $localStockUpdates[] = $params;
            }

            return 1;
        });
        $conn->method('fetchAssociative')->willReturn(['quantity' => 8, 'ps_stock_id' => 55]);
        $result = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSession)
            ->createOrder(1, ['lines' => [['product_id' => 3, 'qty' => 2, 'price' => 10.0]], 'total' => 20.0], 'card', 20.0);

        $this->assertSame(42, $result['ps_order_id']);
        $this->assertFalse($result['saga_error'] ?? false);
        $this->assertSame([[2, 3, 2]], $localStockUpdates);
    }

    /** @return array<string, mixed> decoded order_meta.payment_breakdown */
    private function captureBreakdown(array $cartData, string $paymentMethod, float $tendered): array
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn(['order_id' => 77, 'reference' => 'REF77', 'total_paid' => 60.0]);
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 2]);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params = []): array|false {
            if (str_contains($sql, 'FROM catalog_product')) {
                return ['price' => null, 'price_ttc' => ['3' => 30.0, '4' => 20.0][(string) $params[0]] ?? null];
            }
            if (str_contains($sql, 'FROM catalog_combination')) {
                return ['price_impact' => 0, 'price_ttc' => 30.0];
            }

            return false;
        });
        $breakdown = null;
        $conn->method('insert')->willReturnCallback(function (string $table, array $data) use (&$breakdown): int {
            if ($table === 'order_meta') {
                $breakdown = json_decode($data['payment_breakdown'], true, 512, JSON_THROW_ON_ERROR);
            }

            return 1;
        });

        $this->createService(client: $client, conn: $conn, cashSessionService: $cashSession)
            ->createOrder(1, $cartData, $paymentMethod, $tendered);
        $this->assertIsArray($breakdown);

        return $breakdown;
    }

    public function testOrderMetaKeepsProductIdsPaidLineTotalsAndTenderSplitForRefunds(): void
    {
        // 30 with 10% line discount = 27, plus 2 x 20 = 40 -> 67, minus a 7 cart discount = 60.
        $breakdown = $this->captureBreakdown([
            'lines' => [
                ['product_id' => 3, 'id_product_attribute' => 7, 'qty' => 1, 'price' => 30.0, 'discount' => 10, 'name' => 'Camisa M'],
                ['product_id' => 4, 'qty' => 2, 'price' => 20.0, 'name' => 'Calcetines'],
            ],
            'discount' => ['type' => 'amt', 'value' => 7],
            'total' => 60.0,
            'cash_amount' => 25.0,
        ], 'mixed', 25.0);

        $this->assertEquals(60.0, $breakdown['total']);
        $this->assertEquals(['cash' => 25.0, 'card' => 35.0], $breakdown['tender']);
        $this->assertSame(3, $breakdown['lines'][0]['product_id']);
        $this->assertSame(7, $breakdown['lines'][0]['id_product_attribute']);
        $this->assertSame(4, $breakdown['lines'][1]['product_id']);
        $this->assertSame(0, $breakdown['lines'][1]['id_product_attribute']);
        // Paid share of the 60 actually charged: 27/67 and 40/67, rounding absorbed by the last line.
        $this->assertEquals(24.18, $breakdown['lines'][0]['paid_total']);
        $this->assertEquals(35.82, $breakdown['lines'][1]['paid_total']);
        // Existing ticket fields keep their meaning.
        $this->assertEquals(30.0, $breakdown['lines'][0]['line_total']);
        $this->assertSame('Camisa M', $breakdown['lines'][0]['name']);
    }

    public function testFreeLineNeverGetsANegativePaidShare(): void
    {
        // Two 5.00 items and a free gift, minus a 0.99 cart discount = 9.01.
        $breakdown = $this->captureBreakdown([
            'lines' => [
                ['product_id' => 4, 'qty' => 1, 'price' => 20.0, 'discount' => 75],
                ['product_id' => 4, 'qty' => 1, 'price' => 20.0, 'discount' => 75],
                ['product_id' => 4, 'qty' => 1, 'price' => 20.0, 'discount' => 100],
            ],
            'discount' => ['type' => 'amt', 'value' => 0.99],
            'total' => 9.01,
        ], 'cash', 10.0);

        $paid = array_column($breakdown['lines'], 'paid_total');
        $this->assertEqualsWithDelta(9.01, array_sum($paid), 0.0001);
        $this->assertEquals(0, $paid[2]);
        $this->assertGreaterThanOrEqual(0, min($paid));
    }

    public function testOrderMetaTenderForSingleMethodSales(): void
    {
        $cart = ['lines' => [['product_id' => 4, 'qty' => 1, 'price' => 20.0]], 'total' => 20.0];

        $this->assertEquals(['cash' => 20.0, 'card' => 0.0], $this->captureBreakdown($cart, 'cash', 50.0)['tender']);
        $this->assertEquals(['cash' => 0.0, 'card' => 20.0], $this->captureBreakdown($cart, 'card', 20.0)['tender']);
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
