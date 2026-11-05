<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;

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
        ?Outbox $outbox = null,
        ?OutboxSyncTrigger $syncTrigger = null,
        ?FiscalRecorder $fiscalRecorder = null,
    ): CheckoutService {
        $numberer = $this->createMock(TicketNumberer::class);
        $numberer->method('next')->willReturn('A26000001');

        return new CheckoutService(
            $cart ?? $this->createMock(CartService::class),
            $client ?? $this->createMock(PsOrderClient::class),
            $conn ?? $this->createMock(Connection::class),
            $cashSessionService ?? $this->createMock(CashSessionService::class),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            $printerService ?? $this->createMock(PrinterService::class),
            $couponCheckoutEnabled,
            null,
            $numberer,
            $outbox ?? $this->expectedOutbox ?? $this->createMock(Outbox::class),
            $syncTrigger,
            null,
            $fiscalRecorder ?? $this->createMock(FiscalRecorder::class),
        );
    }

    private ?Outbox $expectedOutbox = null;

    /** The local sale (order_meta.id) the stubbed transaction hands out. */
    private const LOCAL_ORDER_ID = 15;

    /** Runs the local sale transaction on the mocked connection, as DBAL would. */
    private function localTransaction(MockObject $conn): void
    {
        $conn->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation($conn));
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('lastInsertId')->willReturn((string) self::LOCAL_ORDER_ID);
    }

    /** The sale is queued once for upload, with a payload the predicate accepts (it never goes to PrestaShop here). */
    private function expectSaleUpload(callable $predicate): void
    {
        $outbox = $this->createMock(Outbox::class);
        $outbox->expects($this->once())->method('enqueueSale')
            ->with(self::LOCAL_ORDER_ID, $this->callback($predicate))
            ->willReturn('request-id');
        $this->expectedOutbox = $outbox;
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

    public function testOrdinaryCheckoutIgnoresAnExchangeCreditSentByTheBrowser(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchAssociative')->willReturnCallback(static function (string $sql): array|false {
            if (str_contains($sql, 'catalog_product')) return ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12];
            return false;
        });
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('executeStatement')->willReturn(1);
        $breakdown = null;
        $conn->method('insert')->willReturnCallback(function (string $table, array $values) use (&$breakdown): int {
            if ($table === 'order_meta') {
                $breakdown = json_decode($values['payment_breakdown'], true);
            }
            return 1;
        });
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 3]);
        $cashSession->expects($this->once())->method('recordMovementInTransaction')->with(1, 'sale', 23.14, 'Checkout sale', null, self::LOCAL_ORDER_ID);
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');
        $service = $this->createService(cart: $cart, client: $client, conn: $conn, cashSessionService: $cashSession);

        $result = $service->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
            'exchange_credit' => 20.0, 'tender' => ['exchange_credit' => 20.0],
        ], 'cash', 30.0);

        $this->assertSame(['cash' => 23.14, 'card' => 0], $breakdown['tender'], 'the whole total is collected');
        $this->assertSame(6.86, $result['change_due']);
        $this->assertArrayNotHasKey('payable', $result, 'the checkout response keeps its shape');
        $this->assertArrayNotHasKey('exchange_credit', $result);
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

    public function testZeroStockWarnsAndStillSells(): void
    {
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')->willReturn(0);
        $stockUpdates = [];
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$stockUpdates): int {
            $stockUpdates[] = [$sql, $params];

            return 1;
        });
        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn(['session_id' => 12, 'terminal_id' => 3, 'shop_id' => 1, 'user_id' => 1]);

        $result = $this->createService(conn: $conn, cashSessionService: $cashSessionService)->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 10.0],
            ],
            'total' => 10.0,
        ], 'card', 10.0);

        $this->assertSame([['line_index' => 0, 'product_id' => 1, 'id_product_attribute' => 0, 'requested' => 1, 'available' => 0]], $result['stock_warnings']);
        $this->assertSame([['UPDATE catalog_stock SET quantity = quantity - ? WHERE id_product = ? AND id_product_attribute = ?', [1, 1, 0]]], $stockUpdates, 'the local stock may go below zero');
    }

    public function testSuccessfulCheckoutReturnsOrderDetails(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');

        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')
            ->willReturn(10);
        $conn->expects($this->once())
            ->method('insert')
            ->with('order_meta', $this->callback(static fn (array $row): bool => $row['reference'] === 'A26000001' && $row['ps_order_id'] === null && $row['synced'] === 0));
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE catalog_stock SET quantity = quantity - ? WHERE id_product = ? AND id_product_attribute = ?',
                [2, 1, 0]
            )
            ->willReturn(1);

        $cart = $this->createMock(CartService::class);
        $cart->expects($this->once())->method('clear');

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn(['session_id' => 12, 'terminal_id' => 3, 'shop_id' => 1, 'user_id' => 1]);
        $cashSessionService->expects($this->once())->method('recordMovementInTransaction')
            ->with(12, 'sale', 99.99, 'Checkout sale', null, self::LOCAL_ORDER_ID);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())->method('dispatch')
            ->with(new PrintTicketMessage(TicketDataResolver::saleKey(self::LOCAL_ORDER_ID), 3))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $this->expectSaleUpload(static fn (array $payload): bool => $payload['reference'] === 'A26000001' && $payload['products'][0]['quantity'] === 2);
        $trigger = new OutboxSyncTrigger();

        $service = $this->createService(cart: $cart, client: $client, conn: $conn, cashSessionService: $cashSessionService, messageBus: $messageBus, syncTrigger: $trigger);

        $result = $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 2, 'price' => 49.995],
            ],
            'total' => 99.99,
        ], 'cash', 100.0);

        $this->assertSame(self::LOCAL_ORDER_ID, $result['order_id']);
        $this->assertNull($result['ps_order_id']);
        $this->assertSame('A26000001', $result['reference']);
        $this->assertFalse($result['synced']);
        $this->assertSame(99.99, $result['total']);
        $this->assertSame(0.01, $result['change_due']);
        $this->assertSame('cash', $result['payment_method']);
        $this->assertSame([], $result['stock_warnings']);
        $this->assertTrue($trigger->isRequested(), 'an upload attempt follows the response');
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

        $this->localTransaction($conn);
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

    /**
     * Refunds lock the drawer's session before the credit-note counter and the
     * fiscal chain; a cash sale must take the same order, or the two deadlock.
     */
    public function testACashSaleLocksTheSessionBeforeTheFiscalChain(): void
    {
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('executeStatement')->willReturn(1);
        $order = [];
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 12, 'terminal_id' => 3]);
        $cashSession->expects($this->once())->method('lockOpenSessionInTransaction')->with(12)
            ->willReturnCallback(static function () use (&$order): void { $order[] = 'session'; });
        $fiscal = $this->createMock(FiscalRecorder::class);
        $fiscal->expects($this->once())->method('recordSale')
            ->willReturnCallback(static function () use (&$order): void { $order[] = 'fiscal'; });

        $this->createService(conn: $conn, cashSessionService: $cashSession, fiscalRecorder: $fiscal)
            ->createOrder(1, ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]], 'total' => 10.0], 'cash', 10.0);

        $this->assertSame(['session', 'fiscal'], $order);
    }

    public function testACouponCashSaleLocksTheSessionBeforeItsFiscalRecord(): void
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
        $conn->method('fetchAssociative')->willReturnCallback(static fn (string $sql): array|false => str_contains($sql, 'catalog_product')
            ? ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12] : false);
        $conn->method('fetchOne')->willReturnCallback(static fn (string $sql): int|false => str_contains($sql, 'order_meta') ? false : 1);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $conn->method('insert')->willReturn(1);
        $order = [];
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 3]);
        $cashSession->method('lockOpenSessionInTransaction')
            ->willReturnCallback(static function () use (&$order): void { $order[] = 'session'; });
        $fiscal = $this->createMock(FiscalRecorder::class);
        $fiscal->expects($this->once())->method('recordSale')->with($this->anything(), 3, 'CPN-77', 13.14)
            ->willReturnCallback(static function () use (&$order): void { $order[] = 'fiscal'; });

        $this->createService(client: $client, conn: $conn, cashSessionService: $cashSession, couponCheckoutEnabled: true, fiscalRecorder: $fiscal)
            ->createOrder(1, [
                'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 23.14]], 'total' => 23.14,
                'customer' => ['id' => 2],
                'coupon' => ['code' => 'SAVE10', 'preview_cart_id' => 7, 'quote' => 'signed.quote'],
            ], 'cash', 20.0);

        $this->assertSame('session', $order[0], 'the session is locked before the fiscal chain');
        $this->assertContains('fiscal', $order);
    }

    public function testCashSessionClosedDuringTheSaleStoresNothingAndKeepsTheCart(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('executeStatement')->willReturn(1);

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn(['session_id' => 12, 'terminal_id' => 3, 'shop_id' => 1, 'user_id' => 1]);
        $cashSessionService->expects($this->once())->method('recordMovementInTransaction')
            ->willThrowException(new NoOpenCashSessionException());
        $outbox = $this->createMock(Outbox::class);
        $outbox->expects($this->never())->method('enqueueSale');
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->never())->method('clear');
        $printer = $this->createMock(PrinterService::class);
        $printer->expects($this->never())->method('openDrawer');

        $this->expectException(NoOpenCashSessionException::class);
        $this->createService(cart: $cart, client: $client, conn: $conn, cashSessionService: $cashSessionService, printerService: $printer, outbox: $outbox)
            ->createOrder(1, ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 10.0]], 'total' => 10.0], 'cash', 10.0);
    }

    public function testOrderMetaFailureRollsBackTheSaleAndIsRetryable(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willThrowException(new \RuntimeException('DB deadlock'));
        $conn->method('executeStatement')->willReturn(1);
        $outbox = $this->createMock(Outbox::class);
        $outbox->expects($this->never())->method('enqueueSale');
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->never())->method('clear');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        try {
            $this->createService(cart: $cart, client: $client, conn: $conn, messageBus: $messageBus, outbox: $outbox)
                ->createOrder(1, ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 30.0]], 'total' => 30.0], 'cash', 30.0);
            $this->fail('the sale must fail');
        } catch (CheckoutException $e) {
            // Code 0: the controller answers 502 with retry, as nothing was stored.
            $this->assertSame(0, $e->getCode());
            $this->assertStringStartsWith('The sale could not be stored locally', $e->getMessage());
        }
    }

    public function testStockUpdateFailureRollsBackTheSaleAndIsRetryable(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method('createOrder');
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('insert')->willReturn(1);
        $conn->method('executeStatement')->willThrowException(new \RuntimeException('Lock timeout'));
        $outbox = $this->createMock(Outbox::class);
        $outbox->expects($this->never())->method('enqueueSale');
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->never())->method('clear');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        try {
            $this->createService(cart: $cart, client: $client, conn: $conn, messageBus: $messageBus, outbox: $outbox)
                ->createOrder(1, ['lines' => [['product_id' => 1, 'qty' => 1, 'price' => 30.0]], 'total' => 30.0], 'cash', 30.0);
            $this->fail('the sale must fail');
        } catch (CheckoutException $e) {
            // Code 0: the controller answers 502 with retry, as nothing was stored.
            $this->assertSame(0, $e->getCode());
            $this->assertStringStartsWith('The sale could not be stored locally', $e->getMessage());
        }
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

        $this->localTransaction($conn);
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
        $this->localTransaction($conn);
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

        $this->assertNull($result['ps_order_id']);
        $this->assertFalse($result['saga_error']);
        $this->assertSame([[2, 3, 0]], $localStockUpdates);
    }

    /** @return array<string, mixed> decoded order_meta.payment_breakdown */
    private function captureBreakdown(array $cartData, string $paymentMethod, float $tendered): array
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('createOrder')->willReturn(['order_id' => 77, 'reference' => 'REF77', 'total_paid' => 60.0]);
        $cashSession = $this->createMock(CashSessionService::class);
        $cashSession->method('requireOpenSession')->willReturn(['session_id' => 1, 'terminal_id' => 2]);
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
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
        $client->expects($this->never())->method('createOrder');
        $this->expectSaleUpload(function (array $payload): bool {
                return $payload['id_customer'] === 5;
            });

        $conn = $this->createMock(Connection::class);

        $this->localTransaction($conn);
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
        $client->expects($this->never())->method('createOrder');
        $this->expectSaleUpload(function (array $payload): bool {
                return $payload['id_customer'] === 0;
            });

        $conn = $this->createMock(Connection::class);

        $this->localTransaction($conn);
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

        $this->localTransaction($conn);
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
            ->method('recordMovementInTransaction')
            ->with(
                12,
                'sale',
                20.0,
                'Checkout sale',
                null,
                self::LOCAL_ORDER_ID
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

    public function testMixedPaymentCashAboveTotalBooksOnlyThePayable(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $conn = $this->createMock(Connection::class);

        $this->localTransaction($conn);
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
            ->method('recordMovementInTransaction')
            ->with(12, 'sale', 70.0, 'Checkout sale', null, self::LOCAL_ORDER_ID);

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService);

        $service->createOrder(1, [
            'lines' => [
                ['product_id' => 1, 'qty' => 1, 'price' => 70.0],
            ],
            'total' => 70.0,
            'cash_amount' => 1000000,
        ], 'mixed', 1000000.0);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMixedCashAmounts(): iterable
    {
        yield 'negative' => [-20];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMixedCashAmounts')]
    public function testMixedPaymentNegativeCashIsRejected(mixed $cashAmount): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(10);
        $conn->expects($this->never())->method('transactional');

        $cashSessionService = $this->createMock(CashSessionService::class);
        $cashSessionService->method('requireOpenSession')->willReturn([
            'session_id' => 12,
            'terminal_id' => 3,
            'shop_id' => 1,
            'user_id' => 1,
        ]);
        $cashSessionService->expects($this->never())->method('recordMovementInTransaction');

        $service = $this->createService(client: $client, conn: $conn, cashSessionService: $cashSessionService);

        try {
            $service->createOrder(1, [
                'lines' => [
                    ['product_id' => 1, 'qty' => 1, 'price' => 70.0],
                ],
                'total' => 70.0,
                'cash_amount' => $cashAmount,
            ], 'mixed', 70.0);
            $this->fail('A negative or non-finite cash amount must be rejected');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
        }
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

        $this->localTransaction($conn);
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
        $cashSessionService->expects($this->never())->method('recordMovementInTransaction');

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

        $this->localTransaction($conn);
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

        $this->localTransaction($conn);
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

        $this->localTransaction($conn);
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

    /**
     * A line discount lowers the VAT once: 2 x 121.00 (100.00 + 21 % VAT) at 50 %
     * off is 121.00 paid, which carries 21.00 of VAT, not 10.50.
     */
    public function testALineDiscountIsAppliedToTheVatOnlyOnce(): void
    {
        $inserted = [];
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('fetchAssociative')->willReturn(['price' => 121.0, 'price_ttc' => 121.0, 'price_net' => 100.0]);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('insert')->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
            $inserted[$table] = $data;

            return 1;
        });

        $this->createService(conn: $conn)->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 2, 'price' => 121.0, 'discount' => 50]],
            'total' => 121.0,
        ], 'cash', 121.0);

        $breakdown = json_decode($inserted['order_meta']['payment_breakdown'], true);
        $this->assertEquals(242.0, $breakdown['subtotal'], 'the subtotal stays before discounts');
        $this->assertEquals(21.0, $breakdown['tax']);
    }

    public function testATicketDiscountOnTopOfALineDiscountScalesTheVatOnce(): void
    {
        $inserted = [];
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('fetchAssociative')->willReturn(['price' => 121.0, 'price_ttc' => 121.0, 'price_net' => 100.0]);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('insert')->willReturnCallback(function (string $table, array $data) use (&$inserted): int {
            $inserted[$table] = $data;

            return 1;
        });

        // 121.00 at 50 % = 60.50, then a 10 % ticket discount: 54.45 paid, 9.45 of VAT.
        $this->createService(conn: $conn)->createOrder(1, [
            'lines' => [['product_id' => 1, 'qty' => 1, 'price' => 121.0, 'discount' => 50]],
            'discount' => ['type' => 'pct', 'value' => 10],
            'total' => 54.45,
        ], 'cash', 54.45);

        $breakdown = json_decode($inserted['order_meta']['payment_breakdown'], true);
        $this->assertEquals(54.45, $breakdown['total']);
        $this->assertEquals(9.45, $breakdown['tax']);
    }

    public function testOrderMetaRecordsTheExactVatOfEachLine(): void
    {
        $conn = $this->catalogConnection([
            '1' => ['price' => 23.90, 'price_ttc' => 23.14, 'price_net' => 19.12],
        ], 10);

        $inserted = [];
        $conn = $this->createMock(Connection::class);
        $this->localTransaction($conn);
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
        $this->localTransaction($conn);
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
        $client->expects($this->never())->method('createOrder');
        $this->expectSaleUpload(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 9.0;
            });

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
        $client->expects($this->never())->method('createOrder');
        $this->expectSaleUpload(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 23.14;
            });

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
        $client->expects($this->never())->method('createOrder');
        $this->expectSaleUpload(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 9.0;
            });

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
        $client->expects($this->never())->method('createOrder');
        $this->expectSaleUpload(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 25.50;
            });

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
        $client->expects($this->never())->method('createOrder');
        $this->expectSaleUpload(static function (array $payload): bool {
                return (float) $payload['products'][0]['price'] === 11.5;
            });

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
