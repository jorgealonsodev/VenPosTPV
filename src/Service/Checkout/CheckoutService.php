<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use VenPosTPV\Message\PrintTicketMessage;
use VenPosTPV\Service\Cart\CartService;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Sync\SyncLogRepository;

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

    public function __construct(
        private readonly CartService $cart,
        private readonly PsOrderClient $client,
        private readonly Connection $conn,
        private readonly CashSessionService $cashSessionService,
        private readonly MessageBusInterface $messageBus,
        private readonly PrinterService $printerService,
        private readonly PrestaShopApiClient $psApiClient,
        private readonly SyncLogRepository $syncLogRepo,
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
                    'tax' => round($total - $subtotal, 2),
                    'lines' => $breakdownLines,
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
     * Decrements local catalog_stock AND pushes updated quantity to PrestaShop (outbound sync).
     * PS push is fire-and-forget — failure is logged but does NOT block checkout.
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

                // Push updated quantity to PrestaShop (outbound) — fire-and-forget
                $row = $this->conn->fetchAssociative($existsQuery, $existsParams);
                if ($row !== false && !empty($row['ps_stock_id'])) {
                    $psStockId = (int) $row['ps_stock_id'];
                    $newQty = (int) $row['quantity'];
                    try {
                        $this->psApiClient->updateStockAvailable($psStockId, $newQty);
                        $this->syncLogRepo->upsert('stock', 'outbound', new \DateTimeImmutable(), 1);
                    } catch (\Throwable $e) {
                        // Log failure but do NOT block checkout
                        $this->syncLogRepo->upsert('stock_push_error', 'outbound', new \DateTimeImmutable(), 0);
                    }
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
