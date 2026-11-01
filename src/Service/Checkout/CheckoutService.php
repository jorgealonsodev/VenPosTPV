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

class CheckoutService
{
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
        $total = (float) ($cartData['total'] ?? 0);
        $customer = $cartData['customer'] ?? null;

        if (empty($lines)) {
            throw CheckoutException::psValidationFailed('Cart is empty');
        }

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
