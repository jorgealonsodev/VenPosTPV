<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Print\PrinterService;

/**
 * Books a sale against PrestaShop and the local ledger.
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

    /** Used only when the sync could not resolve a tax-excluded price for any line. */
    private const ASSUMED_TAX_RATE = 0.21;

    public function __construct(
        private readonly CartService $cart,
        private readonly PsOrderClient $client,
        private readonly Connection $conn,
        private readonly CashSessionService $cashSessionService,
        private readonly MessageBusInterface $messageBus,
        private readonly PrinterService $printerService,
        private readonly bool $couponCheckoutEnabled = false,
    ) {
    }

    /**
     * @param array<string, mixed> $cartData
     *
     * @return array{ps_order_id: int, reference: string, total: float, change_due: float, payment_method: string, terminal_id: int}
     *
     * @throws CheckoutException on validation or pre-PS errors
     */
    public function createOrder(int $userId, array $cartData, string $paymentMethod, float $amountTendered): array
    {
        if (array_key_exists('coupon', $cartData)) {
            return $this->createCouponOrder($userId, $cartData, $paymentMethod, $amountTendered);
        }

        $lines = $cartData['lines'] ?? $cartData['items'] ?? [];
        $submittedTotal = (float) ($cartData['total'] ?? 0);
        $customer = $cartData['customer'] ?? null;

        if (empty($lines)) {
            throw CheckoutException::psValidationFailed('Cart is empty');
        }

        // Prices and totals arrive from the browser: resolve them against the synced catalog.
        $pricing = $this->resolveAuthoritativePricing($lines, $cartData['discount'] ?? null, $submittedTotal);
        $lines = $pricing['lines'];
        $total = $pricing['total'];

        // Validate stock for each line
        $this->validateStock($lines);

        // Build PS payload
        $products = [];
        foreach ($lines as $line) {
            $products[] = [
                'id_product' => (int) ($line['product_id'] ?? $line['id'] ?? 0),
                'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                'quantity' => (int) ($line['qty'] ?? $line['quantity'] ?? 1),
                'price' => (float) ($line['price'] ?? 0),
            ];
        }

        $idCustomer = 0;
        if ($customer !== null && isset($customer['id']) && \is_int($customer['id']) && $customer['id'] > 0) {
            $idCustomer = $customer['id'];
        }

        $payload = [
            'products' => $products,
            'id_customer' => $idCustomer,
            'id_currency' => 1,
            'id_shop' => (int) ($_SESSION['shop_id'] ?? 1),
            'payment_method' => $paymentMethod,
            'amount_tendered' => $amountTendered,
            'notes' => '',
        ];

        $cashContext = $this->cashSessionService->requireOpenSession($userId);
        $cashImpactAmount = $this->resolveCashImpactAmount($paymentMethod, $total, $amountTendered, $cartData);

        $psResponse = $this->client->createOrder($payload);

        $psOrderId = (int) $psResponse['order_id'];
        $reference = (string) $psResponse['reference'];
        $changeDue = max(0.0, round($amountTendered - $total, 2));

        // Once PrestaShop creates the order, local follow-up failures must not invite a duplicate retry.
        $sagaError = false;
        try {
            $this->insertOrderMeta($psOrderId, $userId, $reference, $total, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines);
        } catch (\Throwable $e) {
            $sagaError = true;
        }

        if ($cashImpactAmount > 0) {
            try {
                // addMovement rechecks the session, which may have closed after the PS order was created.
                $this->cashSessionService->addMovement($userId, 'sale', $cashImpactAmount, 'Checkout sale', $psOrderId);
            } catch (\Throwable $e) {
                $sagaError = true;
            }
        }

        try {
            $this->decrementStock($lines);
        } catch (\Throwable $e) {
            $sagaError = true;
        }

        if ($sagaError) {
            $this->logCheckoutOrphan($psOrderId, $reference, $paymentMethod, $total, $payload);
        }

        // Dispatch async print ticket
        $terminalId = (int) ($cashContext['terminal_id'] ?? 1);
        try {
            $this->messageBus->dispatch(new PrintTicketMessage($psOrderId, $terminalId));
        } catch (\Throwable $e) {
            // Print dispatch failure must not block checkout
        }

        // Open cash drawer for cash or mixed payments
        if ($paymentMethod === 'cash' || $paymentMethod === 'mixed') {
            try {
                $this->printerService->openDrawer($terminalId);
            } catch (\Throwable $e) {
                // Drawer failure must not block checkout
            }
        }

        // Clear cart
        $this->cart->clear();

        return [
            'ps_order_id' => $psOrderId,
            'reference' => $reference,
            'total' => $total,
            'change_due' => $changeDue,
            'payment_method' => $paymentMethod,
            'terminal_id' => (int) ($cashContext['terminal_id'] ?? 1),
            'saga_error' => $sagaError,
        ];
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
     * @return array{ps_order_id: int, reference: string, total: float, change_due: float, payment_method: string, terminal_id: int, saga_error: bool}
     */
    private function createCouponOrder(int $userId, array $cartData, string $paymentMethod, float $amountTendered): array
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
            $alreadyRecorded = $this->withCouponOrderLock($psOrderId, function () use ($psOrderId, $userId, $reference, $payable, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, $quote, $cashCollected, $cardCollected): bool {
                // CashSessionService and this service share the configured TPV
                // DBAL connection. Its public mutation helper correctly rejects
                // nested transactions, so keep these three local writes on the
                // same connection and transaction here.
                return $this->conn->transactional(function () use ($psOrderId, $userId, $reference, $payable, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, $quote, $cashCollected, $cardCollected): bool {
                    if ($this->conn->fetchOne('SELECT 1 FROM order_meta WHERE ps_order_id = ?', [$psOrderId]) !== false) {
                        return true;
                    }
                    $this->insertOrderMeta($psOrderId, $userId, $reference, $payable, $paymentMethod, $amountTendered, $changeDue, $cashContext, $lines, [
                        'gross_total' => round((float) $quote['before_discount_total'], 2),
                        'discount_amount' => round((float) $quote['discount_amount'], 2),
                        'payable' => $payable,
                        'cash_collected' => $cashCollected,
                        'card_collected' => $cardCollected,
                    ]);
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
        if (!$sagaError && !$alreadyRecorded) {
            try {
                $this->messageBus->dispatch(new PrintTicketMessage($psOrderId, $terminalId));
            } catch (\Throwable $e) {
                // Print dispatch failure must not block checkout.
            }
            if ($cashCollected > 0) {
                try {
                    $this->printerService->openDrawer($terminalId);
                } catch (\Throwable $e) {
                    // Drawer failure must not block checkout.
                }
            }
        }
        if (!$sagaError) {
            $this->cart->clear();
        }

        return [
            'ps_order_id' => $psOrderId,
            'reference' => $reference,
            'total' => $payable,
            'change_due' => $changeDue,
            'payment_method' => $paymentMethod,
            'terminal_id' => $terminalId,
            'saga_error' => $sagaError,
        ];
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
        $customer = $cartData['customer'] ?? null;
        $idCustomer = is_array($customer) && isset($customer['id']) && is_int($customer['id']) && $customer['id'] > 0 ? $customer['id'] : 0;

        return [
            'products' => $products,
            'id_customer' => $idCustomer,
            'id_currency' => 1,
            'id_shop' => (int) ($_SESSION['shop_id'] ?? 1),
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
    private function insertCouponCashMovement(array $cashContext, int $userId, float $amount, int $psOrderId): void
    {
        $sessionId = (int) ($cashContext['session_id'] ?? 0);
        if ($sessionId < 1 || $this->conn->fetchOne(
            'SELECT id FROM session WHERE id = ? AND closed_at IS NULL FOR UPDATE',
            [$sessionId]
        ) === false) {
            throw new \RuntimeException('Cash session closed during coupon checkout');
        }
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
     * Returns the authoritative unit price from the synced catalog.
     *
     * The tax-included price wins because it is what PrestaShop charges and books:
     * `catalog_product.price_ttc` for a plain product, and
     * `catalog_combination.price_ttc` (absolute, it already includes the product
     * price and the impact) when a combination is selected. The previous behaviour
     * (base `price` + `price_impact`) is kept only while the TTC value is NULL, so
     * an un-synced price still allows the sale.
     *
     * Returns null only when the product row exists without a usable price.
     *
     * @throws CheckoutException when the product has no row in `catalog_product`,
     *                           so the submitted price cannot be validated
     */
    private function resolveCatalogUnitPrice(int $productId, int $combinationId): ?float
    {
        if ($productId <= 0) {
            // Not a catalog lookup; validateStock() rejects this line with a 422.
            return null;
        }

        $product = $this->conn->fetchAssociative('SELECT price, price_ttc FROM catalog_product WHERE id = ?', [$productId]);
        if ($product === false) {
            // Fail closed: an unknown product must never fall back to the browser price.
            throw CheckoutException::psValidationFailed(sprintf(
                'Product %d is not in the synced catalog; the price cannot be validated',
                $productId
            ));
        }

        $basePrice = isset($product['price']) ? (float) $product['price'] : null;
        $productTtcPrice = isset($product['price_ttc']) && is_numeric($product['price_ttc'])
            ? (float) $product['price_ttc']
            : null;

        if ($combinationId <= 0) {
            if ($productTtcPrice !== null) {
                return round($productTtcPrice, 2);
            }

            return $basePrice !== null ? round($basePrice, 2) : null;
        }

        $combination = $this->conn->fetchAssociative(
            'SELECT price_impact, price_ttc FROM catalog_combination WHERE id = ? AND id_product = ?',
            [$combinationId, $productId]
        );

        if ($combination !== false && isset($combination['price_ttc']) && is_numeric($combination['price_ttc'])) {
            // Absolute TTC price of the combination, not an impact.
            return round((float) $combination['price_ttc'], 2);
        }

        if ($basePrice === null) {
            return null;
        }

        $impact = $combination !== false && isset($combination['price_impact'])
            ? (float) $combination['price_impact']
            : 0.0;

        return round($basePrice + $impact, 2);
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
     * @param array<int, array<string, mixed>> $lines
     *
     * @throws CheckoutException
     */
    private function validateStock(array $lines): void
    {
        foreach ($lines as $line) {
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
                throw CheckoutException::psValidationFailed(
                    sprintf('Insufficient stock for product %d (requested %d, available %d)', $productId, $qty, (int) $stock)
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @throws CheckoutException
     */
    private function insertOrderMeta(
        int $psOrderId,
        int $userId,
        string $reference,
        float $total,
        string $paymentMethod,
        float $amountTendered,
        float $changeDue,
        array $cashContext,
        array $lines,
        ?array $coupon = null,
    ): void {
        try {
            $breakdownLines = [];
            foreach ($lines as $line) {
                $qty = (int) ($line['qty'] ?? $line['quantity'] ?? 1);
                $price = (float) ($line['price'] ?? 0);
                $breakdownLines[] = [
                    'name' => (string) ($line['name'] ?? 'Producto'),
                    'qty' => $qty,
                    'price' => $price,
                    'line_total' => round($qty * $price, 2),
                ];
            }

            $subtotal = 0.0;
            foreach ($breakdownLines as $bl) {
                $subtotal += $bl['line_total'];
            }

            $tax = $this->resolveVatAmount($lines, $subtotal, $total);

            $this->conn->insert('order_meta', [
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
                    // Deliberately excludes bearer coupon code and quote.
                    'coupon' => $coupon,
                ], JSON_THROW_ON_ERROR),
                'printed' => 0,
                'synced' => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            throw CheckoutException::orderMetaFailed($psOrderId, $reference, $e);
        }
    }

    /**
     * Sums the VAT each line actually carries, `price - price_net`, and scales it by
     * the discount applied to the whole sale, because VAT is owed on the amount the
     * customer pays.
     *
     * The previous formula, `total - subtotal`, is meaningless now that both values
     * are tax included: it returned zero or a negative number.
     *
     * When the sync could not resolve a net price for any line, it falls back to the
     * standard rate the point of sale has always assumed, and never to a negative
     * amount.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function resolveVatAmount(array $lines, float $subtotal, float $total): float
    {
        $vatSum = 0.0;
        $linesWithNet = 0;

        foreach ($lines as $line) {
            if (!isset($line['price_net']) || !is_numeric($line['price_net'])) {
                continue;
            }

            $qty = (int) ($line['qty'] ?? $line['quantity'] ?? 1);
            $price = (float) ($line['price'] ?? 0);
            $net = (float) $line['price_net'];
            $lineDiscount = max(0.0, min(100.0, (float) ($line['discount'] ?? 0)));

            $vatSum += ($price - $net) * $qty * (1 - $lineDiscount / 100);
            ++$linesWithNet;
        }

        if ($linesWithNet === 0) {
            return round($total * self::ASSUMED_TAX_RATE / (1 + self::ASSUMED_TAX_RATE), 2);
        }

        $discountFactor = $subtotal > 0 ? $total / $subtotal : 1.0;

        return round(max(0.0, $vatSum * $discountFactor), 2);
    }

    /**
     * Decrements the local catalog_stock copy so the POS shows the sale at once.
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

                if ($combinationId > 0) {
                    $affected = $this->conn->executeStatement(
                        'UPDATE catalog_stock SET quantity = quantity - ? WHERE id_product = ? AND id_product_attribute = ? AND quantity >= ?',
                        [$qty, $productId, $combinationId, $qty]
                    );
                    $existsQuery = 'SELECT quantity, ps_stock_id FROM catalog_stock WHERE id_product = ? AND id_product_attribute = ?';
                    $existsParams = [$productId, $combinationId];
                } else {
                    $affected = $this->conn->executeStatement(
                        'UPDATE catalog_stock SET quantity = quantity - ? WHERE id_product = ? AND id_product_attribute = 0 AND quantity >= ?',
                        [$qty, $productId, $qty]
                    );
                    $existsQuery = 'SELECT quantity, ps_stock_id FROM catalog_stock WHERE id_product = ? AND id_product_attribute = 0';
                    $existsParams = [$productId];
                }

                if ($affected === 0) {
                    $row = $this->conn->fetchAssociative($existsQuery, $existsParams);

                    if ($row === false) {
                        // Product/combination not tracked in catalog_stock — skip silently
                        continue;
                    }

                    throw new \RuntimeException(sprintf('Insufficient stock for product %d', $productId));
                }
            }
        } catch (CheckoutException $e) {
            throw $e;
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
                'user_id' => $_SESSION['user_id'] ?? null,
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

            return round(max(0.0, $cashAmount), 2);
        }

        return 0.0;
    }
}
