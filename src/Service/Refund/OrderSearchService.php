<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Doctrine\DBAL\Connection;

class OrderSearchService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Search orders by ps_order_id, reference, or date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(array $query): array
    {
        $where = ['1=1'];
        $params = [];

        if (isset($query['ps_order_id']) && is_numeric($query['ps_order_id'])) {
            $where[] = 'ps_order_id = ?';
            $params[] = (int) $query['ps_order_id'];
        }

        if (!empty($query['reference']) && \is_string($query['reference'])) {
            $where[] = 'payment_breakdown LIKE ?';
            $params[] = '%' . $query['reference'] . '%';
        }

        if (!empty($query['date_from']) && \is_string($query['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $query['date_from'] . ' 00:00:00';
        }

        if (!empty($query['date_to']) && \is_string($query['date_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $query['date_to'] . ' 23:59:59';
        }

        if (!empty($query['has_refund'])) {
            $where[] = 'refunded_amount > 0';
        }

        $sql = 'SELECT ps_order_id, terminal_id, session_id, user_id, payment_breakdown, refunded_amount, created_at FROM order_meta WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 50';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map([$this, 'hydrateOrder'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrder(int $psOrderId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ps_order_id, terminal_id, session_id, user_id, payment_breakdown, refunded_amount, created_at FROM order_meta WHERE ps_order_id = ?',
            [$psOrderId]
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrateOrder($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrderByReference(string $reference): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ps_order_id, terminal_id, session_id, user_id, payment_breakdown, refunded_amount, created_at FROM order_meta WHERE payment_breakdown LIKE ?',
            ['%"reference":"' . $reference . '"%']
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrateOrder($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrateOrder(array $row): array
    {
        $breakdown = json_decode((string) $row['payment_breakdown'], true);
        if (!\is_array($breakdown)) {
            $breakdown = [];
        }

        $psOrderId = (int) $row['ps_order_id'];
        $lines = \is_array($breakdown['lines'] ?? null) ? array_values($breakdown['lines']) : [];
        $refunded = $lines === [] ? [] : $this->refundedByLine($psOrderId);
        foreach ($lines as $index => $line) {
            $refundedQty = $refunded[$index]['quantity'] ?? 0;
            $lines[$index]['refunded_qty'] = $refundedQty;
            $lines[$index]['refunded_line_amount'] = $refunded[$index]['amount'] ?? 0.0;
            $lines[$index]['refundable_qty'] = max(0, (int) ($line['qty'] ?? 0) - $refundedQty);
        }

        return [
            'ps_order_id' => $psOrderId,
            'terminal_id' => (int) $row['terminal_id'],
            'session_id' => (int) $row['session_id'],
            'user_id' => (int) $row['user_id'],
            'reference' => $breakdown['reference'] ?? '',
            'total' => (float) ($breakdown['total'] ?? 0),
            'payment_method' => $breakdown['payment_method'] ?? '',
            'amount_tendered' => (float) ($breakdown['amount_tendered'] ?? 0),
            'change_due' => (float) ($breakdown['change_due'] ?? 0),
            'lines' => $lines,
            'tender' => \is_array($breakdown['tender'] ?? null) ? $breakdown['tender'] : null,
            'pending_refund' => $lines === [] ? null : $this->pendingRefund($psOrderId),
            'open_exchange' => $lines === [] ? null : $this->openExchange($psOrderId),
            'refunded_amount' => (float) $row['refunded_amount'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Quantities and amounts already refunded (or reserved by a refund whose
     * outcome is still unknown) per sale line index.
     *
     * @return array<int, array{quantity: int, amount: float}>
     */
    private function refundedByLine(int $psOrderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT rl.line_index, SUM(rl.quantity) AS quantity, SUM(rl.amount) AS amount
             FROM refund_line rl
             JOIN refund r ON r.id = rl.refund_id
             WHERE r.ps_order_id = ? AND r.status IN ('pending', 'done') AND rl.line_index IS NOT NULL
             GROUP BY rl.line_index",
            [$psOrderId]
        );

        $result = [];
        foreach ($rows as $row) {
            if (!isset($row['line_index'])) {
                continue;
            }
            $result[(int) $row['line_index']] = [
                'quantity' => (int) $row['quantity'],
                'amount' => round((float) $row['amount'], 2),
            ];
        }

        return $result;
    }

    /**
     * An exchange of this sale still waiting to be completed: its return's outcome
     * is unknown (pending) or its credit is not spent yet (credit_open).
     *
     * @return array{id: int, status: string, credit_applied: float, new_total: float, balance: float, error: ?string}|null
     */
    private function openExchange(int $psOrderId): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT id, status, credit_applied, new_total, balance, error_message FROM exchange WHERE ps_order_id = ? AND status IN ('pending', 'credit_open') ORDER BY id LIMIT 1",
                [$psOrderId]
            );
        } catch (\Throwable) {
            // Database not migrated to exchanges yet: there can be none.
            return null;
        }

        if (!\is_array($row) || !isset($row['id'])) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'credit_applied' => round((float) $row['credit_applied'], 2),
            'new_total' => round((float) $row['new_total'], 2),
            'balance' => round((float) $row['balance'], 2),
            'error' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
        ];
    }

    /**
     * A refund whose PrestaShop outcome is unknown blocks new refunds of the sale
     * until the cashier retries it.
     *
     * @return array{id: int, total: float}|null
     */
    private function pendingRefund(int $psOrderId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, total FROM refund WHERE ps_order_id = ? AND status = 'pending' ORDER BY id LIMIT 1",
            [$psOrderId]
        );

        if (!\is_array($row) || !isset($row['id'])) {
            return null;
        }

        return ['id' => (int) $row['id'], 'total' => round((float) $row['total'], 2)];
    }
}
