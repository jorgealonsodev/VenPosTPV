<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Service\Session\SessionValue;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Catalog\CatalogPriceResolver;
use VenPosTPV\Service\Catalog\Exception\ProductNotInCatalogException;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Sale\Prorate;
use VenPosTPV\Service\Sale\SaleLineWriter;
use VenPosTPV\Service\Sale\VatRate;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;

/**
 * Books a sale in the local ledger first and uploads it to PrestaShop afterwards
 * (PRD venta-local-primero V1-V4): one local transaction stores the sale with its
 * ticket code, cash movement, local stock and outbox row; the ticket and drawer do
 * not wait for PrestaShop, and OutboxSync uploads the order with the ticket code as
 * its reference. The new items of an exchange are a local sale too (PRD D1). Native
 * coupons still create the PrestaShop order first (PrestaShop must validate them at
 * once, PRD V5); when PrestaShop cannot be reached the coupon is refused
 * (coupon_offline) with nothing booked, and the cart is charged without it. A
 * browser request_id makes a repeated checkout, with or without coupon, return the
 * sale it already created instead of charging it twice. The sale's customer comes
 * from the local customer copy; one created at the till and not uploaded yet makes
 * the sale's upload wait for it (PRD C2).
 *
 * Prices are resolved from the synced catalog on a tax-included basis, because
 * that is what PrestaShop actually charges: `catalog_product.price_ttc` for a
 * plain product and `catalog_combination.price_ttc` (an absolute price, not an
 * impact) when a combination is selected. The tax-excluded base price is only a
 * fallback for rows whose TTC price is still NULL, and a product missing from
 * `catalog_product` fails closed instead of trusting the browser.
 */
class CheckoutService
{
    private const PRICE_TOLERANCE = 0.02;
    private const TOTAL_TOLERANCE = 0.02;

    public function __construct(
        private readonly CartService $cart,
        private readonly PsOrderClient $client,
        private readonly Connection $conn,
        private readonly CashSessionService $cashSessionService,
        private readonly MessageBusInterface $messageBus,
        private readonly PrinterService $printerService,
        private readonly bool $couponCheckoutEnabled = false,
        private readonly ?CatalogPriceResolver $priceResolver = null,
        private readonly ?TicketNumberer $ticketNumberer = null,
        private readonly ?Outbox $outbox = null,
        private readonly ?OutboxSyncTrigger $syncTrigger = null,
        private readonly ?CustomerDirectory $customers = null,
        private readonly ?FiscalRecorder $fiscalRecorder = null,
        private readonly ?SaleLineWriter $saleLineWriter = null,
    ) {
    }

    private function customerDirectory(): CustomerDirectory
    {
        return $this->customers ?? new CustomerDirectory($this->conn);
    }

    /**
     * Server-side total of a cart (catalog prices, discounts, stock), without
     * creating anything: an exchange needs it before the returned part is refunded.
     *
     * @param array<string, mixed> $cartData
     *
     * @throws CheckoutException 422 when the cart cannot be sold as submitted
     */
    public function quoteCart(array $cartData): float
    {
        if (array_key_exists('coupon', $cartData)) {
            throw CheckoutException::psValidationFailed('Coupons cannot be used in an exchange');
        }
        $lines = $cartData['lines'] ?? $cartData['items'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw CheckoutException::psValidationFailed('Cart is empty');
        }
        $pricing = $this->resolveAuthoritativePricing($lines, $cartData['discount'] ?? null, (float) ($cartData['total'] ?? 0));
        $this->validateStock($pricing['lines']);

        return $pricing['total'];
    }

    /**
     * $exchangeCredit is set only by the exchange flow on the server (never from the
     * browser): that part of the total is already paid by returned goods, so only
     * the rest is collected, booked in the drawer and stored in the tender.
     *
     * $requestId is the browser's id of one payment attempt, the same on every retry
     * or offline replay of that attempt: a repeated one returns the sale it already
     * created (`replayed: true`) without a second ticket number, cash movement,
     * stock change or upload.
     *
     * @param array<string, mixed> $cartData
     *
     * @return array<string, mixed> order_id (local), ps_order_id (null until uploaded), reference, synced, total,
     *                              change_due, payment_method, terminal_id, saga_error, stock_warnings, and for an
     *                              exchange payable and exchange_credit
     *
     * @throws CheckoutException on validation errors (422) or when nothing could be stored (code 0, retryable)
     */
    public function createOrder(int $userId, array $cartData, string $paymentMethod, float $amountTendered, float $exchangeCredit = 0.0, ?string $requestId = null): array
    {
        $exchangeCredit = round(max(0.0, $exchangeCredit), 2);
        if ($requestId !== null) {
            if (preg_match(self::REQUEST_ID, $requestId) !== 1) {
                throw CheckoutException::psValidationFailed('request_id must be 8 to 64 letters, digits, "-" or "_"');
            }
            $existing = $this->replayedSale($requestId);
            if ($existing !== null) {
                return $existing;
            }
        }
        if (array_key_exists('coupon', $cartData)) {
            if ($exchangeCredit > 0) {
                throw CheckoutException::psValidationFailed('Coupons cannot be used in an exchange');
            }

            return $this->createCouponOrder($userId, $cartData, $paymentMethod, $amountTendered, $requestId);
        }

        return $this->createLocalOrder($userId, $cartData, $paymentMethod, $amountTendered, $exchangeCredit, $requestId);
    }

    /** A client payment-attempt id: what api.js generates (a UUID) or any similar token. */
    private const REQUEST_ID = '/^[A-Za-z0-9_-]{8,64}$/';

    /**
     * The sale an earlier request with this request_id created, answered as it was
     * then (stock warnings are not stored, so none are repeated), or null.
     *
     * @return array<string, mixed>|null
     */
    private function replayedSale(string $requestId): ?array
    {
        $row = $this->conn->fetchAssociative('SELECT id, ps_order_id, reference, terminal_id, payment_breakdown FROM order_meta WHERE request_id = ?', [$requestId]);
        if ($row === false) {
            return null;
        }
        $breakdown = json_decode((string) $row['payment_breakdown'], true);
        $breakdown = is_array($breakdown) ? $breakdown : [];
        // The cart was charged by the first request: the screen starts a new sale.
        $this->cart->clear();

        return [
            'order_id' => (int) $row['id'],
            'ps_order_id' => $row['ps_order_id'] !== null ? (int) $row['ps_order_id'] : null,
            'reference' => (string) ($row['reference'] ?? ($breakdown['reference'] ?? '')),
            'synced' => $row['ps_order_id'] !== null,
            'total' => (float) ($breakdown['total'] ?? 0),
            'change_due' => (float) ($breakdown['change_due'] ?? 0),
            'payment_method' => (string) ($breakdown['payment_method'] ?? ''),
            'terminal_id' => (int) $row['terminal_id'],
            'saga_error' => false,
            'stock_warnings' => [],
            'replayed' => true,
        ];
    }

    /**
     * The local-first sale (PRD V1, V2, V4), also the new items of an exchange. The
     * ticket number is taken inside the transaction, so a rollback gives it back and
     * the series has no gaps.
     *
     * @param array<string, mixed> $cartData
     *
     * @return array<string, mixed>
     */
    private function createLocalOrder(int $userId, array $cartData, string $paymentMethod, float $amountTendered, float $exchangeCredit = 0.0, ?string $requestId = null): array
    {
        $lines = $cartData['lines'] ?? $cartData['items'] ?? [];
        if (empty($lines) || !is_array($lines)) {
            throw CheckoutException::psValidationFailed('Cart is empty');
        }

        $pricing = $this->resolveAuthoritativePricing($lines, $cartData['discount'] ?? null, (float) ($cartData['total'] ?? 0));
        $lines = $pricing['lines'];
        $total = $pricing['total'];
        $stockWarnings = $this->validateStock($lines);

        $customer = $this->customerDirectory()->resolveForSale($cartData['customer'] ?? null);
        $payload = $this->orderPayload($lines, $customer, $paymentMethod, $amountTendered);
        $cashContext = $this->cashSessionService->requireOpenSession($userId);
        // What the customer still pays; the whole total without an exchange credit.
        $payable = round(max(0.0, $total - $exchangeCredit), 2);
        $cashImpactAmount = $this->resolveCashImpactAmount($paymentMethod, $payable, $amountTendered, $cartData);
        $changeDue = max(0.0, round($amountTendered - $payable, 2));
        $tender = [
            'cash' => $cashImpactAmount,
            'card' => round(max(0.0, $payable - $cashImpactAmount), 2),
        ];
        if ($exchangeCredit > 0) {
            $tender['exchange_credit'] = round($total - $payable, 2);
        }
        $terminalId = (int) ($cashContext['terminal_id'] ?? 1);
        $sessionId = (int) ($cashContext['session_id'] ?? 0);
        $date = new \DateTimeImmutable();
        $numberer = $this->ticketNumberer ?? new TicketNumberer($this->conn);
        $outbox = $this->outbox ?? new Outbox($this->conn);

        try {
            $numberer->prepareYear($terminalId, $date);
            [$orderId, $reference] = $this->conn->transactional(function () use ($numberer, $outbox, $terminalId, $sessionId, $date, $userId, $total, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, $tender, $cashImpactAmount, $payload, $requestId, $customer): array {
                if ($cashImpactAmount > 0) {
                    // Lock order shared with refunds: session, number counter, fiscal
                    // chain. Taking the session after the chain deadlocks with a refund.
                    $this->cashSessionService->lockOpenSessionInTransaction($sessionId);
                }
                $reference = $numberer->next($terminalId, $date);
                $orderId = $this->insertOrderMeta(null, $userId, $reference, $total, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, null, $tender, false, $requestId);
                if ($cashImpactAmount > 0) {
                    $this->cashSessionService->recordMovementInTransaction($sessionId, 'sale', $cashImpactAmount, 'Checkout sale', null, $orderId);
                }
                $this->decrementStock($lines);
                $outbox->enqueueSale($orderId, $payload + ['reference' => $reference], $customer['depends_on']);

                return [$orderId, $reference];
            });
        } catch (\DomainException|\OverflowException $e) {
            // No ticket letter, or the letter used up its numbers this year.
            throw CheckoutException::ticketNumberUnavailable($e->getMessage(), $e);
        } catch (NoOpenCashSessionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Everything rolled back. A concurrent request with the same request_id
            // that won the unique key already stored the sale: answer that one.
            $existing = $requestId !== null ? $this->replayedSale($requestId) : null;
            if ($existing !== null) {
                return $existing;
            }
            if ($e instanceof CheckoutException && $e->getCode() === 422) {
                throw $e;
            }
            // The sale does not exist and can be charged again.
            throw CheckoutException::localSaveFailed($e);
        }

        $print = $this->printForAgent($cashContext, $terminalId, TicketDataResolver::saleKey($orderId));
        if ($print === null) {
            try {
                $this->messageBus->dispatch(new PrintTicketMessage(TicketDataResolver::saleKey($orderId), $terminalId));
            } catch (\Throwable $e) {
                // Print dispatch failure must not block checkout
            }
        }
        if ($paymentMethod === 'cash' || $paymentMethod === 'mixed') {
            $print = $this->openDrawerForSale($print, $cashContext, $terminalId, $userId, $orderId, $reference);
        }
        // Upload attempt once the response is sent; the minute task covers the rest.
        $this->syncTrigger?->request();

        $result = [
            'order_id' => $orderId,
            'ps_order_id' => null,
            'reference' => $reference,
            'synced' => false,
            'total' => $total,
            'change_due' => $changeDue,
            'payment_method' => $paymentMethod,
            'terminal_id' => $terminalId,
            'saga_error' => false,
            'stock_warnings' => $stockWarnings,
        ] + ($print ?? []);
        if ($exchangeCredit > 0) {
            // The exchange's new items never came from the sale screen cart.
            return $result + ['payable' => $payable, 'exchange_credit' => $tender['exchange_credit']];
        }
        $this->cart->clear();

        return $result + ($requestId !== null ? ['replayed' => false] : []);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array{ps_customer_id: int, local_id: int|null, depends_on: int|null} $customer
     *
     * @return array<string, mixed>
     */
    private function orderPayload(array $lines, array $customer, string $paymentMethod, float $amountTendered): array
    {
        $products = [];
        foreach ($lines as $line) {
            $products[] = [
                'id_product' => (int) ($line['product_id'] ?? $line['id'] ?? 0),
                'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                'quantity' => (int) ($line['qty'] ?? $line['quantity'] ?? 1),
                'price' => (float) ($line['price'] ?? 0),
            ];
        }

        return [
            'products' => $products,
            'id_customer' => $customer['ps_customer_id'],
            'id_currency' => 1,
            'id_shop' => (SessionValue::int('shop_id') ?? 1),
            'payment_method' => $paymentMethod,
            'amount_tendered' => $amountTendered,
            'notes' => '',
        ] + ($customer['local_id'] !== null ? ['_customer_local_id' => $customer['local_id']] : []);
    }

    /**
     * Previews a native coupon without creating either a native order or local
     * accounting records. This remains disabled until the compatible module is
     * deployed, because an older module treats an unknown operation as an order.
     *
     * @param array<string, mixed> $cartData
     * @return array<string, mixed>
     */
    public function previewCoupon(int $userId, array $cartData, string $couponCode): array
    {
        $this->assertCouponCheckoutEnabled();
        $this->cashSessionService->requireOpenSession($userId);
        $this->assertNoManualDiscountWithCoupon($cartData);

        $code = trim($couponCode);
        if ($code === '') {
            throw CheckoutException::psValidationFailed('Coupon code is required');
        }

        $lines = $cartData['lines'] ?? $cartData['items'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw CheckoutException::psValidationFailed('Cart is empty');
        }
        $pricing = $this->resolveAuthoritativePricing($lines, null, (float) ($cartData['total'] ?? 0));
        $this->validateStock($pricing['lines']);
        $payload = $this->couponPayload($pricing['lines'], $cartData, 'TPV Connect');
        $payload['operation'] = 'coupon_preview';
        $payload['coupon_code'] = $code;

        $preview = $this->client->previewCoupon($payload);
        if (!$this->sameTotal($pricing['total'], (float) $preview['before_discount_total'])
            || !$this->sameTotal((float) $preview['before_discount_total'] - (float) $preview['discount_amount'], (float) $preview['total_paid'])) {
            throw CheckoutException::psValidationFailed('Coupon preview total does not match the local catalog');
        }

        return $preview;
    }

    /**
     * Coupon tender is derived from the server-verified quote. Browser amounts,
     * discounts, codes, and quote fields are never used as an accounting basis.
     *
     * @param array<string, mixed> $cartData
     * A request_id is stored with the sale, so a repeated attempt is answered by
     * createOrder from the stored sale without asking PrestaShop again.
     *
     * @return array<string, mixed> ps_order_id, reference, total, change_due, payment_method, terminal_id, saga_error
     *
     * @throws CheckoutException coupon_offline (nothing booked) when PrestaShop cannot be reached
     */
    private function createCouponOrder(int $userId, array $cartData, string $paymentMethod, float $amountTendered, ?string $requestId = null): array
    {
        $this->assertCouponCheckoutEnabled();
        $this->assertNoManualDiscountWithCoupon($cartData);
        $coupon = $cartData['coupon'] ?? null;
        if (!is_array($coupon) || !is_string($coupon['code'] ?? null) || trim($coupon['code']) === ''
            || !is_int($coupon['preview_cart_id'] ?? null) || !is_string($coupon['quote'] ?? null)) {
            throw CheckoutException::psValidationFailed('Coupon binding is invalid; validate the coupon again');
        }

        $lines = $cartData['lines'] ?? $cartData['items'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw CheckoutException::psValidationFailed('Cart is empty');
        }
        $pricing = $this->resolveAuthoritativePricing($lines, null, (float) ($cartData['total'] ?? 0));
        $lines = $pricing['lines'];
        $this->validateStock($lines);
        $cashContext = $this->cashSessionService->requireOpenSession($userId);

        $payload = $this->couponPayload($lines, $cartData, $paymentMethod);
        $quote = $this->client->verifyCouponQuote($coupon['quote']);
        $this->assertCouponQuoteMatchesLocal($quote, $coupon, $payload, $pricing['total']);
        // The signed module quote resolves anonymous customer 0 to the native
        // configured anonymous customer. Bind creation to that positive identity
        // only after all local context/product/total checks have passed.
        if ((int) $payload['id_customer'] === 0) {
            $payload['id_customer'] = (int) $quote['customer_id'];
        }
        $payable = round((float) $quote['total_paid'], 2);
        [$cashCollected, $cardCollected, $changeDue] = $this->resolveCouponTender($paymentMethod, $amountTendered, $cartData, $payable);

        $payload['operation'] = 'order';
        $payload['amount_tendered'] = $payable;
        $payload['coupon'] = [
            'code' => $quote['coupon_code'],
            'preview_cart_id' => $quote['cart_id'],
            'quote' => $coupon['quote'],
            'expected_total_paid' => $payable,
        ];

        $psResponse = $this->client->createCouponOrder($payload);
        if (!$this->sameTotal((float) $psResponse['total_paid'], $payable)) {
            throw CheckoutException::couponReconciliationRequired('Native coupon order total differs from the verified quote');
        }

        $psOrderId = (int) $psResponse['order_id'];
        $reference = (string) $psResponse['reference'];
        $sagaError = false;
        $alreadyRecorded = false;
        try {
            $alreadyRecorded = $this->withCouponOrderLock($psOrderId, function () use ($psOrderId, $userId, $reference, $payable, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, $quote, $cashCollected, $cardCollected, $requestId): bool {
                // CashSessionService and this service share the configured TPV
                // DBAL connection. Its public mutation helper correctly rejects
                // nested transactions, so keep these three local writes on the
                // same connection and transaction here.
                return $this->conn->transactional(function () use ($psOrderId, $userId, $reference, $payable, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, $quote, $cashCollected, $cardCollected, $requestId): bool {
                    if ($this->conn->fetchOne('SELECT 1 FROM order_meta WHERE ps_order_id = ?', [$psOrderId]) !== false) {
                        return true;
                    }
                    if ($cashCollected > 0) {
                        // Same lock order as refunds: session before the fiscal chain.
                        $this->lockCouponSession($cashContext);
                    }
                    $this->insertOrderMeta($psOrderId, $userId, $reference, $payable, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, [
                        'gross_total' => round((float) $quote['before_discount_total'], 2),
                        'discount_amount' => round((float) $quote['discount_amount'], 2),
                        'payable' => $payable,
                        'cash_collected' => $cashCollected,
                        'card_collected' => $cardCollected,
                    ], ['cash' => $cashCollected, 'card' => $cardCollected], true, $requestId);
                    if ($cashCollected > 0) {
                        $this->insertCouponCashMovement($cashContext, $userId, $cashCollected, $psOrderId);
                    }
                    $this->decrementStock($lines);

                    return false;
                });
            });
        } catch (\Throwable $e) {
            $sagaError = true;
        }

        if ($sagaError) {
            // Do not include the bearer coupon code or quote in this best-effort log.
            $this->logCheckoutOrphan($psOrderId, $reference, $paymentMethod, $payable, ['coupon_checkout' => true]);
        }

        $terminalId = (int) ($cashContext['terminal_id'] ?? 1);
        $print = null;
        if (!$sagaError && !$alreadyRecorded) {
            $print = $this->printForAgent($cashContext, $terminalId, $psOrderId);
            if ($print === null) {
                try {
                    $this->messageBus->dispatch(new PrintTicketMessage($psOrderId, $terminalId));
                } catch (\Throwable $e) {
                    // Print dispatch failure must not block checkout.
                }
            }
            if ($cashCollected > 0) {
                $print = $this->openDrawerForSale($print, $cashContext, $terminalId, $userId, null, $reference);
            }
        }
        if (!$sagaError) {
            $this->cart->clear();
        }

        $orderId = $sagaError ? false : $this->conn->fetchOne('SELECT id FROM order_meta WHERE ps_order_id = ?', [$psOrderId]);

        return [
            'order_id' => is_numeric($orderId) ? (int) $orderId : null,
            'ps_order_id' => $psOrderId,
            'reference' => $reference,
            'synced' => true,
            'total' => $payable,
            'change_due' => $changeDue,
            'payment_method' => $paymentMethod,
            'terminal_id' => $terminalId,
            'saga_error' => $sagaError,
        ] + ($print ?? []) + ($requestId !== null ? ['replayed' => $alreadyRecorded] : []);
    }

    /**
     * PRD T0, T9: for a till that prints through its print agent the ticket job is
     * created in this request (never through Messenger) and never on till 1 by
     * default. Null for the other printers: the ticket is dispatched as before.
     *
     * @param array<string, mixed> $cashContext
     *
     * @return array<string, mixed>|null print_status 'queued' with print_job_id, or 'failed' with print_error
     */
    private function printForAgent(array $cashContext, int $terminalId, int $orderKey): ?array
    {
        $resolved = isset($cashContext['terminal_id']) && (int) $cashContext['terminal_id'] > 0;
        try {
            if (!$this->printerService->usesAgent($resolved ? $terminalId : null)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        if (!$resolved) {
            return ['print_status' => 'failed', 'print_error' => 'terminal_not_resolved'];
        }

        try {
            $this->printerService->printTicket($orderKey, $terminalId);

            return ['print_status' => 'queued', 'print_job_id' => $this->printerService->queuedJobId('ticket')];
        } catch (\Throwable) {
            // The sale is stored: the screen offers the PDF (C1, C2).
            return ['print_status' => 'failed', 'print_error' => 'print_job_failed'];
        }
    }

    /**
     * Opens the drawer for a cash sale (T11: recorded with the sale); with the print
     * agent the response says whether its job was queued.
     *
     * @param array<string, mixed>|null $print
     * @param array<string, mixed> $cashContext
     *
     * @return array<string, mixed>|null
     */
    private function openDrawerForSale(?array $print, array $cashContext, int $terminalId, int $userId, ?int $orderId, string $reference): ?array
    {
        if ($print !== null && ($print['print_error'] ?? null) === 'terminal_not_resolved') {
            return $print + ['drawer_status' => 'failed'];
        }

        try {
            $this->printerService->openDrawer($terminalId, 'sale', $userId, $orderId, $reference);
            if ($print !== null) {
                return $print + ['drawer_status' => 'queued', 'drawer_job_id' => $this->printerService->queuedJobId('drawer')];
            }
        } catch (\Throwable $e) {
            // Drawer failure must not block checkout
            if ($print !== null) {
                return $print + ['drawer_status' => 'failed'];
            }
        }

        return $print;
    }

    private function assertCouponCheckoutEnabled(): void
    {
        if (!$this->couponCheckoutEnabled) {
            throw CheckoutException::couponUnavailable('coupon_unavailable: deploy the compatible TPV Connect module and explicitly enable coupon checkout');
        }
    }

    /** @param array<string, mixed> $cartData */
    private function assertNoManualDiscountWithCoupon(array $cartData): void
    {
        $discount = $cartData['discount'] ?? null;
        if (is_array($discount) && (float) ($discount['value'] ?? 0) > 0) {
            throw CheckoutException::psValidationFailed('Remove the manual discount before applying a native coupon');
        }
        foreach (($cartData['lines'] ?? $cartData['items'] ?? []) as $line) {
            if (is_array($line) && (float) ($line['discount'] ?? 0) > 0) {
                throw CheckoutException::psValidationFailed('Remove the manual discount before applying a native coupon');
            }
        }
    }

    /** @param array<int, array<string,mixed>> $lines @param array<string,mixed> $cartData @return array<string,mixed> */
    private function couponPayload(array $lines, array $cartData, string $paymentMethod): array
    {
        $products = [];
        foreach ($lines as $line) {
            $products[] = [
                'id_product' => (int) ($line['product_id'] ?? $line['id'] ?? 0),
                'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                'quantity' => (int) ($line['qty'] ?? $line['quantity'] ?? 1),
                'price' => (float) ($line['price'] ?? 0),
            ];
        }
        $customer = $this->customerDirectory()->resolveForSale($cartData['customer'] ?? null);
        if ($customer['local_id'] !== null) {
            // PrestaShop validates the coupon for a customer it must already know.
            throw CheckoutException::psValidationFailed('El cliente aún no está en PrestaShop: no se puede aplicar el cupón. Cobra sin él o espera a que se suba.');
        }
        $idCustomer = $customer['ps_customer_id'];

        return [
            'products' => $products,
            'id_customer' => $idCustomer,
            'id_currency' => 1,
            'id_shop' => (SessionValue::int('shop_id') ?? 1),
            'payment_method' => $paymentMethod,
            'notes' => '',
        ];
    }

    /** @param array<string,mixed> $quote @param array<string,mixed> $coupon @param array<string,mixed> $payload */
    private function assertCouponQuoteMatchesLocal(array $quote, array $coupon, array $payload, float $grossTotal): void
    {
        $requestedCustomerId = (int) $payload['id_customer'];
        $quotedCustomerId = (int) $quote['customer_id'];
        $customerMatches = $requestedCustomerId > 0
            ? $quotedCustomerId === $requestedCustomerId
            : $requestedCustomerId === 0 && $quotedCustomerId > 0;
        if ((int) $coupon['preview_cart_id'] !== (int) $quote['cart_id']
            || !hash_equals((string) $quote['coupon_code'], trim((string) $coupon['code']))
            || !$customerMatches
            || (int) $quote['shop_id'] !== (int) $payload['id_shop']
            || (int) $quote['currency_id'] !== (int) $payload['id_currency']
            || !$this->sameTotal($grossTotal, (float) $quote['before_discount_total'])
            || !$this->sameTotal((float) $quote['before_discount_total'] - (float) $quote['discount_amount'], (float) $quote['total_paid'])
            || $this->couponProductFingerprint($payload['products']) !== $this->couponProductFingerprint($quote['products'])) {
            throw CheckoutException::psValidationFailed('Coupon quote does not match the current cart; validate it again');
        }
    }

    /** @param array<int,mixed> $products */
    private function couponProductFingerprint(array $products): array
    {
        $fingerprint = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                return [];
            }
            $fingerprint[] = [
                'id_product' => (int) ($product['id_product'] ?? 0),
                'id_product_attribute' => (int) ($product['id_product_attribute'] ?? 0),
                'quantity' => (int) ($product['quantity'] ?? 0),
            ];
        }
        usort($fingerprint, static fn (array $left, array $right): int => $left <=> $right);

        return $fingerprint;
    }

    /** @param array<string,mixed> $cartData @return array{0: float, 1: float, 2: float} */
    private function resolveCouponTender(string $paymentMethod, float $amountTendered, array $cartData, float $payable): array
    {
        if ($paymentMethod === 'cash') {
            if ($amountTendered + self::TOTAL_TOLERANCE < $payable) {
                throw CheckoutException::psValidationFailed('Cash tender does not cover the coupon payable amount');
            }
            return [$payable, 0.0, round(max(0.0, $amountTendered - $payable), 2)];
        }
        if ($paymentMethod === 'card') {
            return [0.0, $payable, 0.0];
        }
        if ($paymentMethod === 'mixed') {
            $cash = round(max(0.0, (float) ($cartData['cash_amount'] ?? 0)), 2);
            if ($cash > $payable + self::TOTAL_TOLERANCE) {
                throw CheckoutException::psValidationFailed('Coupon cash tender exceeds the payable amount');
            }
            return [$cash, round($payable - $cash, 2), 0.0];
        }

        throw CheckoutException::psValidationFailed('Coupon checkout requires cash, card, or mixed tender');
    }

    private function sameTotal(float $left, float $right): bool
    {
        return abs(round($left, 2) - round($right, 2)) <= self::TOTAL_TOLERANCE;
    }

    /** @template T @param callable():T $operation @return T */
    private function withCouponOrderLock(int $psOrderId, callable $operation): mixed
    {
        $lockName = 'venpostpv_coupon_order_' . $psOrderId;
        $acquired = false;
        try {
            $acquired = (int) $this->conn->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName]) === 1;
            if (!$acquired) {
                throw new \RuntimeException('Coupon local accounting lock is unavailable');
            }
            return $operation();
        } finally {
            if ($acquired) {
                $this->conn->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    /**
     * Insert the coupon's cash portion inside the checkout transaction.
     *
     * CashSessionService::addMovement() owns its own transaction and explicitly
     * rejects nesting. The service uses the same TPV Connection alias, so this
     * narrow equivalent preserves its session-open invariant while allowing the
     * metadata, movement, and stock write to commit or roll back together.
     *
     * @param array<string,mixed> $cashContext
     */
    /** @param array<string, mixed> $cashContext */
    private function lockCouponSession(array $cashContext): void
    {
        try {
            $this->cashSessionService->lockOpenSessionInTransaction((int) ($cashContext['session_id'] ?? 0));
        } catch (NoOpenCashSessionException $e) {
            throw new \RuntimeException('Cash session closed during coupon checkout', 0, $e);
        }
    }

    private function insertCouponCashMovement(array $cashContext, int $userId, float $amount, int $psOrderId): void
    {
        $sessionId = (int) ($cashContext['session_id'] ?? 0);
        $this->lockCouponSession($cashContext);
        $this->conn->insert('movement', [
            'session_id' => $sessionId,
            'type' => 'sale',
            'amount' => round($amount, 2),
            'concept' => 'Coupon checkout sale',
            'ps_order_id' => $psOrderId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Resolves the authoritative unit price and total for the submitted lines,
     * using the tax-included catalog price as the source of truth.
     *
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, total: float}
     *
     * @throws CheckoutException
     */
    private function resolveAuthoritativePricing(array $lines, mixed $discount, float $submittedTotal): array
    {
        $priced = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $productId = (int) ($line['product_id'] ?? $line['id'] ?? 0);
            $combinationId = (int) ($line['id_product_attribute'] ?? 0);
            $qty = max(1, (int) ($line['qty'] ?? $line['quantity'] ?? 1));
            $submittedPrice = (float) ($line['price'] ?? 0);
            $lineDiscount = max(0.0, min(100.0, (float) ($line['discount'] ?? 0)));

            $expectedUnitPrice = $this->resolveCatalogUnitPrice($productId, $combinationId);
            if ($expectedUnitPrice !== null && abs($expectedUnitPrice - $submittedPrice) > self::PRICE_TOLERANCE) {
                throw CheckoutException::psValidationFailed(sprintf(
                    'Price mismatch for product %d%s (expected %.2f, received %.2f)',
                    $productId,
                    $combinationId > 0 ? ' combination ' . $combinationId : '',
                    $expectedUnitPrice,
                    round($submittedPrice, 2)
                ));
            }

            // null only when the catalog row exists without a usable price; the
            // submitted price is then kept so an incomplete sync cannot block the sale.
            $unitPrice = $expectedUnitPrice ?? $submittedPrice;
            $line['price'] = $unitPrice;
            $line['price_net'] = $expectedUnitPrice !== null
                ? $this->resolveCatalogNetPrice($productId, $combinationId)
                : null;
            $line['qty'] = $qty;
            $priced[] = $line;

            $subtotal += $unitPrice * $qty * (1 - $lineDiscount / 100);
        }

        if ($priced === []) {
            throw CheckoutException::psValidationFailed('Cart is empty');
        }

        $subtotal = round($subtotal, 2);
        $total = round(max(0.0, $subtotal - $this->resolveDiscountAmount($discount, $subtotal)), 2);

        if (abs($total - round($submittedTotal, 2)) > self::TOTAL_TOLERANCE) {
            throw CheckoutException::psValidationFailed(sprintf(
                'Total mismatch: expected %.2f, received %.2f',
                $total,
                round($submittedTotal, 2)
            ));
        }

        return ['lines' => $priced, 'subtotal' => $subtotal, 'total' => $total];
    }

    /**
     * Returns the authoritative unit price from the synced catalog (see
     * CatalogPriceResolver); null only when the product row exists without a usable price.
     *
     * @throws CheckoutException when the product has no row in `catalog_product`,
     *                           so the submitted price cannot be validated
     */
    private function resolveCatalogUnitPrice(int $productId, int $combinationId): ?float
    {
        try {
            return ($this->priceResolver ?? new CatalogPriceResolver($this->conn))->unitPrice($productId, $combinationId);
        } catch (ProductNotInCatalogException $e) {
            // Fail closed: an unknown product must never fall back to the browser price.
            throw CheckoutException::psValidationFailed($e->getMessage());
        }
    }

    /**
     * Returns the tax-excluded price with reductions applied, so the VAT actually
     * charged is `price - price_net`. It is intentionally NOT `catalog_product.price`,
     * which is the raw Web Service base price and ignores reductions.
     *
     * Null when the sync could not resolve it; the caller then falls back to an
     * estimated rate instead of reporting a meaningless difference.
     */
    private function resolveCatalogNetPrice(int $productId, int $combinationId): ?float
    {
        if ($productId <= 0) {
            return null;
        }

        if ($combinationId > 0) {
            $combination = $this->conn->fetchAssociative(
                'SELECT price_net FROM catalog_combination WHERE id = ? AND id_product = ?',
                [$combinationId, $productId]
            );
            if ($combination !== false && isset($combination['price_net']) && is_numeric($combination['price_net'])) {
                return round((float) $combination['price_net'], 2);
            }
        }

        $product = $this->conn->fetchAssociative('SELECT price_net FROM catalog_product WHERE id = ?', [$productId]);
        if ($product === false || !isset($product['price_net']) || !is_numeric($product['price_net'])) {
            return null;
        }

        return round((float) $product['price_net'], 2);
    }

    /**
     * @throws CheckoutException
     */
    private function resolveDiscountAmount(mixed $discount, float $subtotal): float
    {
        if (!is_array($discount) || !isset($discount['value'])) {
            return 0.0;
        }

        $value = (float) $discount['value'];
        if (!is_finite($value) || $value < 0) {
            throw CheckoutException::psValidationFailed('Invalid discount: value must be zero or positive');
        }

        if (($discount['type'] ?? 'pct') === 'amt') {
            return round(min($value, $subtotal), 2);
        }

        if ($value > 100) {
            throw CheckoutException::psValidationFailed('Invalid discount: percentage must be between 0 and 100');
        }

        return round($subtotal * $value / 100, 2);
    }

    /**
     * Stock 0 or less is "Avisar y permitir" (PRD V4): the sale goes through and the
     * cashier is warned about each line the local stock does not cover.
     *
     * @param array<int, array<string, mixed>> $lines
     *
     * @return list<array{line_index: int, product_id: int, id_product_attribute: int, requested: int, available: int}>
     *
     * @throws CheckoutException for a line without a valid product
     */
    private function validateStock(array $lines): array
    {
        $warnings = [];
        foreach (array_values($lines) as $index => $line) {
            $productId = (int) ($line['product_id'] ?? $line['id'] ?? 0);
            $combinationId = (int) ($line['id_product_attribute'] ?? 0);
            $qty = (int) ($line['qty'] ?? $line['quantity'] ?? 1);

            if ($productId <= 0) {
                throw CheckoutException::psValidationFailed('Invalid product in cart');
            }

            if ($combinationId > 0) {
                $stock = $this->conn->fetchOne(
                    'SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ?',
                    [$productId, $combinationId]
                );
            } else {
                $stock = $this->conn->fetchOne(
                    'SELECT quantity FROM catalog_stock WHERE id_product = ? AND id_product_attribute = 0',
                    [$productId]
                );
            }

            if ($stock === false) {
                // Product/combination not in catalog_stock yet — allow (sync may be pending)
                continue;
            }

            if ((int) $stock < $qty) {
                $warnings[] = [
                    'line_index' => $index,
                    'product_id' => $productId,
                    'id_product_attribute' => $combinationId,
                    'requested' => $qty,
                    'available' => (int) $stock,
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @throws CheckoutException
     */
    private function insertOrderMeta(
        ?int $psOrderId,
        int $userId,
        string $reference,
        float $total,
        string $paymentMethod,
        float $amountTendered,
        float $changeDue,
        array $cashContext,
        array $lines,
        ?array $coupon = null,
        array $tender = ['cash' => 0.0, 'card' => 0.0],
        bool $synced = true,
        ?string $requestId = null,
    ): int {
        try {
            $breakdownLines = [];
            $netTotals = [];
            foreach ($lines as $line) {
                $qty = (int) ($line['qty'] ?? $line['quantity'] ?? 1);
                $price = (float) ($line['price'] ?? 0);
                $lineDiscount = max(0.0, min(100.0, (float) ($line['discount'] ?? 0)));
                $netTotals[] = $qty * $price * (1 - $lineDiscount / 100);
                $breakdownLines[] = [
                    'name' => (string) ($line['name'] ?? 'Producto'),
                    'product_id' => (int) ($line['product_id'] ?? $line['id'] ?? 0),
                    'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                    'qty' => $qty,
                    'price' => $price,
                    'line_total' => round($qty * $price, 2),
                ];
            }

            // What the customer actually paid per line: line discounts, then the cart
            // discount or coupon spread proportionally. Refunds return exactly this.
            foreach (Prorate::shares($netTotals, $total) as $index => $paid) {
                $breakdownLines[$index]['paid_total'] = $paid;
            }

            $subtotal = 0.0;
            foreach ($breakdownLines as $bl) {
                $subtotal += $bl['line_total'];
            }

            // One sale_line per ticket line with its VAT (reports, full invoice). The
            // sale VAT is the ticket's VAT box: what was paid at each rate, rounded once
            // per rate (factura-completa.md, F5), so ticket, fiscal record and invoice agree.
            $saleLines = [];
            $paidByRate = [];
            foreach ($breakdownLines as $index => $breakdownLine) {
                $saleLines[] = $breakdownLine + [
                    'price_net' => $lines[$index]['price_net'] ?? null,
                    'discount' => max(0.0, min(100.0, (float) ($lines[$index]['discount'] ?? 0))),
                ];
                $rate = VatRate::fromPrices((float) $breakdownLine['price'], isset($lines[$index]['price_net']) && is_numeric($lines[$index]['price_net']) ? (float) $lines[$index]['price_net'] : null)['rate'];
                $key = number_format($rate, 2, '.', '');
                $paidByRate[$key] = ($paidByRate[$key] ?? 0.0) + (float) $breakdownLine['paid_total'];
            }
            $tax = round(array_sum(array_column(VatRate::summary($paidByRate), 'vat')), 2);
            $issuedAt = new \DateTimeImmutable();

            $this->conn->insert('order_meta', [
                // The ticket code; a PrestaShop reference longer than the column stays in payment_breakdown only.
                'reference' => strlen($reference) <= 9 && $reference !== '' ? $reference : null,
                'ps_order_id' => $psOrderId,
                'terminal_id' => (int) ($cashContext['terminal_id'] ?? 1),
                'session_id' => (int) ($cashContext['session_id'] ?? 0),
                'user_id' => $userId,
                'payment_breakdown' => json_encode([
                    'reference' => $reference,
                    'total' => $total,
                    'payment_method' => $paymentMethod,
                    'amount_tendered' => $amountTendered,
                    'change_due' => $changeDue,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'lines' => $breakdownLines,
                    // exchange_credit: the part of the total paid with returned goods.
                    'tender' => [
                        'cash' => round((float) ($tender['cash'] ?? 0), 2),
                        'card' => round((float) ($tender['card'] ?? 0), 2),
                    ] + (isset($tender['exchange_credit']) ? ['exchange_credit' => round((float) $tender['exchange_credit'], 2)] : []),
                    // Deliberately excludes bearer coupon code and quote.
                    'coupon' => $coupon,
                ], JSON_THROW_ON_ERROR),
                'printed' => 0,
                'synced' => $synced ? 1 : 0,
                'created_at' => $issuedAt->format('Y-m-d H:i:s'),
                // Unique: a repeated payment attempt returns this sale (V32).
            ] + ($requestId !== null ? ['request_id' => $requestId] : []));
            $orderId = (int) $this->conn->lastInsertId();

            ($this->saleLineWriter ?? new SaleLineWriter($this->conn))->write(
                $orderId,
                $issuedAt,
                (int) ($cashContext['terminal_id'] ?? 1),
                (int) ($cashContext['session_id'] ?? 0),
                $userId,
                $saleLines,
            );

            // The chained fiscal record of the ticket, in the same transaction (VERI*FACTU).
            ($this->fiscalRecorder ?? new FiscalRecorder($this->conn))
                ->recordSale($orderId, (int) ($cashContext['terminal_id'] ?? 1), $reference, $total, $tax, $issuedAt);

            return $orderId;
        } catch (\Throwable $e) {
            throw CheckoutException::orderMetaFailed($psOrderId ?? 0, $reference, $e);
        }
    }

    /**
     * Decrements the local catalog_stock copy so the POS shows the sale at once. It
     * may go below zero: stock 0 or less warns and still sells (PRD V4).
     *
     * Nothing is sent to PrestaShop: creating the native order already lowers its
     * stock_available (OrderDetail::updateProductQuantityInStock). Pushing the
     * quantity again would count the sale twice, or overwrite online sales with a
     * stale local count. The next catalog sync reconciles the local copy.
     *
     * @param array<int, array<string, mixed>> $lines
     *
     * @throws CheckoutException
     */
    private function decrementStock(array $lines): void
    {
        try {
            foreach ($lines as $line) {
                $productId = (int) ($line['product_id'] ?? $line['id'] ?? 0);
                $combinationId = (int) ($line['id_product_attribute'] ?? 0);
                $qty = (int) ($line['qty'] ?? $line['quantity'] ?? 1);

                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                // A product or combination not tracked in catalog_stock updates nothing.
                $this->conn->executeStatement(
                    'UPDATE catalog_stock SET quantity = quantity - ? WHERE id_product = ? AND id_product_attribute = ?',
                    [$qty, $productId, $combinationId]
                );
            }
        } catch (\Throwable $e) {
            throw CheckoutException::stockUpdateFailed(0, $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logCheckoutOrphan(
        int $psOrderId,
        string $reference,
        string $paymentMethod,
        float $total,
        array $payload,
    ): void {
        try {
            $this->conn->insert('audit_log', [
                'user_id' => SessionValue::int('user_id'),
                'terminal_id' => 1,
                'action' => 'checkout_orphan',
                'target' => (string) $psOrderId,
                'payload' => json_encode([
                    'reference' => $reference,
                    'payment_method' => $paymentMethod,
                    'total' => $total,
                    'payload' => $payload,
                ], JSON_THROW_ON_ERROR),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Best-effort logging; do not throw
        }
    }

    /** @param array<string,mixed> $cartData */
    private function resolveCashImpactAmount(string $paymentMethod, float $total, float $amountTendered, array $cartData): float
    {
        if ($paymentMethod === 'cash') {
            return round(max(0.0, $total), 2);
        }

        if ($paymentMethod === 'mixed') {
            $cashAmount = isset($cartData['cash_amount']) ? (float) $cartData['cash_amount'] : 0.0;
            if (!is_finite($cashAmount) || $cashAmount < 0) {
                throw CheckoutException::psValidationFailed('cash_amount must be a finite amount of zero or more');
            }

            // Cash above the total is change handed back: the drawer keeps only what was charged.
            return round(min($cashAmount, max(0.0, $total)), 2);
        }

        return 0.0;
    }
}
