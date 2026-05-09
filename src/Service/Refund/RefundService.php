<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Refund\Exception\RefundException;

class RefundService
{
    public function __construct(
        private readonly OrderSearchService $orderSearchService,
        private readonly PsRefundClient $client,
        private readonly Connection $conn,
        private readonly CashSessionService $cashSessionService,
    ) {
    }

    /**
     * Process a refund for an existing order.
     *
     * @param array<int, array<string, mixed>>|null $lines If null, full refund. Otherwise partial refund with selected lines.
     *
     * @return array{order_slip_id: int, refunded_amount: float, movement_id: int}
     *
     * @throws RefundException
     */
    public function processRefund(int $userId, int $psOrderId, ?array $lines, string $reason = ''): array
    {
        $order = $this->orderSearchService->getOrder($psOrderId);
        if ($order === null) {
            throw RefundException::orderNotFound($psOrderId);
        }

        $total = (float) $order['total'];
        $refundedAmount = (float) $order['refunded_amount'];

        if ($refundedAmount >= $total) {
            throw RefundException::doubleRefundAttempt($psOrderId);
        }

        $cashContext = $this->cashSessionService->requireOpenSession($userId);

        $refundLines = $this->buildRefundLines($order, $lines);
        $refundTotal = $this->calculateRefundTotal($refundLines);

        if ($refundTotal <= 0) {
            throw RefundException::validationFailed('Refund total must be greater than 0');
        }

        $remainingRefundable = round($total - $refundedAmount, 2);
        if ($refundTotal > $remainingRefundable) {
            throw RefundException::validationFailed(
                sprintf('Refund amount exceeds remaining refundable amount (%.2f)', $remainingRefundable)
            );
        }

        $payload = [
            'ps_order_id' => $psOrderId,
            'lines' => $refundLines,
            'refund_type' => $lines === null ? 'full' : 'partial',
            'reason' => $reason,
        ];

        $psResponse = $this->client->createRefund($payload);
        $orderSlipId = (int) $psResponse['order_slip_id'];
        $psRefundedAmount = (float) $psResponse['refunded_amount'];

        $cashAmount = $this->resolveCashImpactAmount($order, $refundLines);

        $sagaError = false;
        try {
            if ($cashAmount > 0 || $cashAmount === 0.0) {
                $this->cashSessionService->addMovement(
                    $userId,
                    'refund',
                    -abs($cashAmount),
                    sprintf('Devolución pedido #%d', $psOrderId),
                    $psOrderId
                );
            }

            $this->incrementStock($refundLines);
            $this->updateRefundedAmount($psOrderId, $psRefundedAmount);
        } catch (RefundException $e) {
            $this->logRefundOrphan($psOrderId, $orderSlipId, $payload);
            $sagaError = true;
        } catch (\Throwable $e) {
            $this->logRefundOrphan($psOrderId, $orderSlipId, $payload);
            $sagaError = true;
        }

        $movementId = (int) $this->conn->fetchOne(
            'SELECT id FROM movement WHERE ps_order_id = ? AND type = ? ORDER BY id DESC LIMIT 1',
            [$psOrderId, 'refund']
        );

        return [
            'order_slip_id' => $orderSlipId,
            'refunded_amount' => $psRefundedAmount,
            'movement_id' => $movementId,
            'saga_error' => $sagaError,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>>|null $lines
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRefundLines(array $order, ?array $lines): array
    {
        if ($lines === null) {
            // Full refund: use all original lines
            $result = [];
            foreach ($order['lines'] as $line) {
                $result[] = [
                    'id_product' => (int) ($line['product_id'] ?? $line['id_product'] ?? 0),
                    'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                    'quantity' => (int) ($line['qty'] ?? $line['quantity'] ?? 1),
                    'amount' => (float) ($line['line_total'] ?? $line['amount'] ?? 0),
                ];
            }

            return $result;
        }

        // Partial refund: map selected lines
        $result = [];
        foreach ($lines as $line) {
            $result[] = [
                'id_product' => (int) ($line['id_product'] ?? $line['product_id'] ?? 0),
                'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                'quantity' => (int) ($line['quantity'] ?? $line['qty'] ?? 1),
                'amount' => (float) ($line['amount'] ?? $line['line_total'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $refundLines
     */
    private function calculateRefundTotal(array $refundLines): float
    {
        $total = 0.0;
        foreach ($refundLines as $line) {
            $total += (float) ($line['amount'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $refundLines
     */
    private function resolveCashImpactAmount(array $order, array $refundLines): float
    {
        $paymentMethod = (string) ($order['payment_method'] ?? '');
        $refundTotal = $this->calculateRefundTotal($refundLines);

        if ($paymentMethod === 'cash') {
            return round(max(0.0, $refundTotal), 2);
        }

        if ($paymentMethod === 'mixed') {
            // For mixed payments, we need to calculate the cash portion proportionally
            // Use the same ratio as original payment
            $amountTendered = (float) ($order['amount_tendered'] ?? 0);
            $originalTotal = (float) ($order['total'] ?? 0);
            if ($originalTotal > 0 && $amountTendered > 0) {
                $cashRatio = $amountTendered / $originalTotal;

                return round(max(0.0, $refundTotal * $cashRatio), 2);
            }

            return 0.0;
        }

        return 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $refundLines
     */
    private function incrementStock(array $refundLines): void
    {
        try {
            foreach ($refundLines as $line) {
                $productId = (int) ($line['id_product'] ?? 0);
                $qty = (int) ($line['quantity'] ?? 1);

                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                $this->conn->executeStatement(
                    'UPDATE catalog_stock SET quantity = quantity + ? WHERE id_product = ?',
                    [$qty, $productId]
                );
            }
        } catch (\Throwable $e) {
            throw RefundException::validationFailed('Stock increment failed: ' . $e->getMessage());
        }
    }

    private function updateRefundedAmount(int $psOrderId, float $amount): void
    {
        try {
            $this->conn->executeStatement(
                'UPDATE order_meta SET refunded_amount = refunded_amount + ? WHERE ps_order_id = ?',
                [round($amount, 2), $psOrderId]
            );
        } catch (\Throwable $e) {
            throw RefundException::validationFailed('Failed to update refunded amount: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logRefundOrphan(int $psOrderId, int $orderSlipId, array $payload): void
    {
        try {
            $this->conn->insert('audit_log', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'terminal_id' => 1,
                'action' => 'refund_orphan',
                'target' => (string) $psOrderId,
                'payload' => json_encode([
                    'order_slip_id' => $orderSlipId,
                    'payload' => $payload,
                ], JSON_THROW_ON_ERROR),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Best-effort logging; do not throw
        }
    }
}
