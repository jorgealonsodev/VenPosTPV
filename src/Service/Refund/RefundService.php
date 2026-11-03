<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Refund\Exception\RefundException;

/**
 * Refunds lines of a TPV sale.
 *
 * Amounts always come from what the sale recorded as paid per line (order_meta
 * paid_total), never from the browser; money goes back by the original tender
 * split; quantities are capped by what earlier refunds already returned. The
 * refund row is written before PrestaShop is called, so a call with an unknown
 * outcome is resumed with the same request_id instead of refunding twice.
 */
class RefundService
{
    public const REASONS = [
        'size',
        'defective',
        'dislike',
        'billing_error',
        'not_as_described',
        'gift_duplicate',
        'late',
        'other',
    ];

    public function __construct(
        private readonly OrderSearchService $orderSearchService,
        private readonly PsRefundClient $client,
        private readonly Connection $conn,
        private readonly CashSessionService $cashSessionService,
    ) {
    }

    /**
     * @param array<int, array{line_index?: mixed, quantity?: mixed, restock?: mixed}>|null $lines null refunds everything still refundable
     *
     * @return array{refund_id: int, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool}
     *
     * @throws RefundException
     */
    public function processRefund(
        int $userId,
        int $psOrderId,
        ?array $lines,
        string $reasonCode,
        string $reasonText = '',
        bool $cardConfirmed = false,
    ): array {
        $reasonText = trim($reasonText);
        if (!\in_array($reasonCode, self::REASONS, true)) {
            throw RefundException::validationFailed('Choose a reason from the list');
        }
        if ($reasonCode === 'other' && $reasonText === '') {
            throw RefundException::validationFailed('Describe the reason when choosing "other"');
        }

        $cashContext = $this->cashSessionService->requireOpenSession($userId);

        // An earlier call with an unknown outcome must settle before anything new.
        $pending = $this->conn->fetchAssociative(
            "SELECT id FROM refund WHERE ps_order_id = ? AND status = 'pending' ORDER BY id LIMIT 1",
            [$psOrderId]
        );
        if ($pending !== false) {
            $this->resumePending((int) $pending['id']);

            throw RefundException::conflict(sprintf(
                'A previous refund of order %d was still pending and has now been completed; review the order before refunding again',
                $psOrderId
            ));
        }

        $order = $this->orderSearchService->getOrder($psOrderId);
        if ($order === null) {
            throw RefundException::orderNotFound($psOrderId);
        }

        $planned = $this->planLines($order, $lines);
        $total = round(array_sum(array_column($planned, 'amount')), 2);
        if ($total <= 0) {
            throw RefundException::validationFailed('Refund total must be greater than 0');
        }

        [$cashAmount, $cardAmount] = $this->splitByTender($order, $total);
        if ($cardAmount > 0 && !$cardConfirmed) {
            throw RefundException::validationFailed(sprintf('Confirm the %.2f card refund on the payment terminal first', $cardAmount));
        }

        $requestId = bin2hex(random_bytes(16));
        $payload = [
            'ps_order_id' => $psOrderId,
            'request_id' => $requestId,
            'refund_type' => $lines === null ? 'full' : 'partial',
            'reason' => $reasonCode === 'other' ? $reasonText : $reasonCode,
            'lines' => array_map(static fn (array $line): array => [
                'id_product' => $line['product_id'],
                'id_product_attribute' => $line['id_product_attribute'],
                'quantity' => $line['quantity'],
                'amount' => $line['amount'],
                'restock' => $line['restock'],
            ], $planned),
        ];

        $refundId = $this->conn->transactional(function () use ($requestId, $psOrderId, $cashContext, $userId, $reasonCode, $reasonText, $total, $cashAmount, $cardAmount, $cardConfirmed, $payload, $planned): int {
            $this->conn->insert('refund', [
                'request_id' => $requestId,
                'status' => 'pending',
                'ps_order_id' => $psOrderId,
                'terminal_id' => (int) ($cashContext['terminal_id'] ?? 1),
                'session_id' => (int) $cashContext['session_id'],
                'user_id' => $userId,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText !== '' ? mb_substr($reasonText, 0, 255) : null,
                'total' => $total,
                'cash_amount' => $cashAmount,
                'card_amount' => $cardAmount,
                'card_confirmed' => $cardConfirmed ? 1 : 0,
                'ps_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            $refundId = (int) $this->conn->lastInsertId();

            foreach ($planned as $line) {
                $this->conn->insert('refund_line', [
                    'refund_id' => $refundId,
                    'line_index' => $line['line_index'],
                    'product_id' => $line['product_id'],
                    'id_product_attribute' => $line['id_product_attribute'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'restock' => $line['restock'] ? 1 : 0,
                ]);
            }

            return $refundId;
        });

        return $this->send($refundId, $payload);
    }

    /**
     * Sends a stored refund to PrestaShop and applies its local effects.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{refund_id: int, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool}
     */
    private function send(int $refundId, array $payload): array
    {
        try {
            $psResponse = $this->client->createRefund($payload);
        } catch (RefundException $e) {
            if ($e->getCode() === 404 || $e->getCode() === 409 || $e->getCode() === 422) {
                // Definitely rejected: nothing happened in the shop.
                $this->conn->update('refund', [
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                ], ['id' => $refundId]);
            }
            // Otherwise the outcome is unknown and the row stays pending for resumePending().

            throw $e;
        }

        return $this->complete($refundId, $psResponse);
    }

    private function resumePending(int $refundId): void
    {
        $payload = json_decode((string) $this->conn->fetchOne('SELECT ps_payload FROM refund WHERE id = ?', [$refundId]), true);
        if (!\is_array($payload)) {
            throw RefundException::validationFailed(sprintf('Pending refund %d cannot be resumed', $refundId));
        }

        $this->send($refundId, $payload);
    }

    /**
     * @param array{order_slip_id: int, refunded_amount: float, refunded_lines: array<int, array<string, mixed>>, restock_failed?: array<int, mixed>} $psResponse
     *
     * @return array{refund_id: int, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool}
     */
    private function complete(int $refundId, array $psResponse): array
    {
        $refund = $this->conn->fetchAssociative('SELECT * FROM refund WHERE id = ?', [$refundId]);
        $slipId = (int) $psResponse['order_slip_id'];
        $cashAmount = round((float) $refund['cash_amount'], 2);
        $total = round((float) $refund['total'], 2);
        $psOrderId = (int) $refund['ps_order_id'];

        $restocked = [];
        foreach ($psResponse['refunded_lines'] as $line) {
            $restocked[(int) ($line['id_product'] ?? 0) . ':' . (int) ($line['id_product_attribute'] ?? 0)] = !empty($line['restocked']);
        }

        $lines = $this->conn->fetchAllAssociative('SELECT * FROM refund_line WHERE refund_id = ? ORDER BY id', [$refundId]);
        $sagaError = false;

        try {
            $this->conn->transactional(function () use ($refund, $refundId, $slipId, $cashAmount, $total, $psOrderId, $lines, $restocked): void {
                $this->conn->update('refund', [
                    'status' => 'done',
                    'ps_order_slip_id' => $slipId,
                    'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ], ['id' => $refundId]);

                if ($cashAmount > 0) {
                    // Positive: the cash report subtracts the refund total from the drawer.
                    $this->conn->insert('movement', [
                        'session_id' => (int) $refund['session_id'],
                        'type' => 'refund',
                        'amount' => $cashAmount,
                        'concept' => sprintf('Devolución pedido #%d', $psOrderId),
                        'ps_order_id' => $psOrderId,
                        'ps_order_slip_id' => $slipId,
                        'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                }

                foreach ($lines as $line) {
                    $key = (int) $line['product_id'] . ':' . (int) $line['id_product_attribute'];
                    $wasRestocked = $restocked[$key] ?? false;
                    $this->conn->update('refund_line', ['restocked' => $wasRestocked ? 1 : 0], ['id' => (int) $line['id']]);
                    if ($wasRestocked) {
                        // Mirror the shop's restock locally until the next catalog sync.
                        $this->conn->executeStatement(
                            'UPDATE catalog_stock SET quantity = quantity + ? WHERE id_product = ? AND id_product_attribute = ?',
                            [(int) $line['quantity'], (int) $line['product_id'], (int) $line['id_product_attribute']]
                        );
                    }
                }

                $this->conn->executeStatement(
                    'UPDATE order_meta SET refunded_amount = refunded_amount + ? WHERE ps_order_id = ?',
                    [$total, $psOrderId]
                );
            });
        } catch (\Throwable $e) {
            // The shop already holds the credit slip: record it for reconciliation.
            $this->logRefundOrphan($psOrderId, $slipId, $refundId, $e);
            $sagaError = true;
        }

        return [
            'refund_id' => $refundId,
            'order_slip_id' => $slipId,
            'refunded_amount' => $total,
            'cash_amount' => $cashAmount,
            'card_amount' => round((float) $refund['card_amount'], 2),
            'lines' => array_map(static fn (array $l): array => [
                'line_index' => $l['line_index'] !== null ? (int) $l['line_index'] : null,
                'product_id' => (int) $l['product_id'],
                'id_product_attribute' => (int) $l['id_product_attribute'],
                'quantity' => (int) $l['quantity'],
                'amount' => round((float) $l['amount'], 2),
            ], $lines),
            'restock_failed' => $psResponse['restock_failed'] ?? [],
            'saga_error' => $sagaError,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>>|null $requested
     *
     * @return array<int, array{line_index: int, product_id: int, id_product_attribute: int, quantity: int, amount: float, restock: bool}>
     */
    private function planLines(array $order, ?array $requested): array
    {
        $orderLines = $order['lines'];

        if ($requested === null) {
            $requested = [];
            foreach ($orderLines as $index => $line) {
                if ((int) ($line['refundable_qty'] ?? 0) > 0) {
                    $requested[] = ['line_index' => $index, 'quantity' => (int) $line['refundable_qty'], 'restock' => true];
                }
            }
            if ($requested === []) {
                throw RefundException::doubleRefundAttempt((int) $order['ps_order_id']);
            }
        }

        if ($requested === []) {
            throw RefundException::validationFailed('Select at least one line');
        }

        $planned = [];
        $seen = [];
        foreach ($requested as $position => $line) {
            $index = filter_var($line['line_index'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($index === false || !isset($orderLines[$index])) {
                throw RefundException::validationFailed(sprintf('Line %d does not belong to this sale', $position));
            }
            if (isset($seen[$index])) {
                throw RefundException::validationFailed(sprintf('Line %d is listed twice', $index));
            }
            $seen[$index] = true;
            if ($quantity === false || $quantity < 1) {
                throw RefundException::validationFailed(sprintf('Quantity for line %d must be at least 1', $index));
            }

            $orderLine = $orderLines[$index];
            $productId = (int) ($orderLine['product_id'] ?? 0);
            if ($productId <= 0 || !isset($orderLine['paid_total'])) {
                throw RefundException::validationFailed('This sale was recorded before refunds were supported and cannot be refunded from the TPV');
            }

            $soldQty = (int) $orderLine['qty'];
            $refundedQty = (int) $orderLine['refunded_qty'];
            if ($quantity > $soldQty - $refundedQty) {
                throw RefundException::validationFailed(sprintf('Only %d unit(s) of line %d are left to refund', max(0, $soldQty - $refundedQty), $index));
            }

            $paidTotal = (float) $orderLine['paid_total'];
            // The last units take whatever is left, so rounding never leaves cents behind.
            $amount = $refundedQty + $quantity === $soldQty
                ? round($paidTotal - (float) $orderLine['refunded_line_amount'], 2)
                : round($paidTotal * $quantity / $soldQty, 2);

            $planned[] = [
                'line_index' => $index,
                'product_id' => $productId,
                'id_product_attribute' => (int) ($orderLine['id_product_attribute'] ?? 0),
                'quantity' => $quantity,
                'amount' => $amount,
                'restock' => ($line['restock'] ?? true) !== false,
            ];
        }

        return $planned;
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array{0: float, 1: float} cash and card amounts
     */
    private function splitByTender(array $order, float $total): array
    {
        $tender = $order['tender'] ?? null;
        $cash = \is_array($tender) ? (float) ($tender['cash'] ?? 0) : 0.0;
        $card = \is_array($tender) ? (float) ($tender['card'] ?? 0) : 0.0;

        if ($cash + $card <= 0) {
            $cash = ($order['payment_method'] ?? '') === 'card' ? 0.0 : 1.0;
            $card = 1.0 - $cash;
        }

        $cashAmount = round($total * $cash / ($cash + $card), 2);

        return [$cashAmount, round($total - $cashAmount, 2)];
    }

    private function logRefundOrphan(int $psOrderId, int $orderSlipId, int $refundId, \Throwable $error): void
    {
        try {
            $this->conn->insert('audit_log', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'terminal_id' => 1,
                'action' => 'refund_orphan',
                'target' => (string) $psOrderId,
                'payload' => json_encode([
                    'refund_id' => $refundId,
                    'order_slip_id' => $orderSlipId,
                    'error' => $error->getMessage(),
                ], JSON_THROW_ON_ERROR),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best-effort logging; do not throw
        }
    }
}
