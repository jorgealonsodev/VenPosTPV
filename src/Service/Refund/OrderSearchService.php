<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Refund\Exception\RefundException;

/**
 * Finds the orders a refund starts from: TPV sales (order_meta) and, when no TPV
 * sale matches a web reference (9 letters) or an order number, online orders read
 * from PrestaShop through the module. Both come back in the same shape (lines with
 * paid_total, refunded_qty, refundable_qty...); online ones carry online=true, the
 * delivery date and no tender, because the TPV never took their money.
 */
class OrderSearchService
{
    /** PrestaShop's default order references: 9 letters. */
    private const WEB_REFERENCE = '/^[A-Za-z]{9}$/';

    /** id, reference, ps_reference and synced: Version00000031 (local-first sales). */
    private const SALE_COLUMNS = 'id, reference, ps_order_id, ps_reference, synced, terminal_id, session_id, user_id, payment_breakdown, refunded_amount, created_at';

    public function __construct(
        private readonly Connection $connection,
        private readonly ?PsOrderLookupClient $lookupClient = null,
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
            // The ticket code is in payment_breakdown; a different PrestaShop reference (PRD S5) in ps_reference.
            $where[] = '(payment_breakdown LIKE ? OR ps_reference = ?)';
            $params[] = '%' . $query['reference'] . '%';
            $params[] = strtoupper($query['reference']);
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

        $sql = 'SELECT ' . self::SALE_COLUMNS . ' FROM order_meta WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 50';

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        if ($rows !== []) {
            return array_map([$this, 'hydrateOrder'], $rows);
        }

        return $this->searchOnline($query);
    }

    /**
     * An online order as the refund needs it, or null when the shop has no such
     * order. Never a TPV sale: those are read with getOrder().
     *
     * @return array<string, mixed>|null
     *
     * @throws RefundException when the shop cannot be asked
     */
    public function getOnlineOrder(int $psOrderId): ?array
    {
        if ($this->lookupClient === null) {
            return null;
        }
        foreach ($this->lookupClient->lookup(['id_order' => $psOrderId]) as $order) {
            if ((int) ($order['id_order'] ?? 0) === $psOrderId) {
                return $this->hydrateOnlineOrder($order);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchOnline(array $query): array
    {
        if ($this->lookupClient === null || !empty($query['date_from']) || !empty($query['date_to']) || !empty($query['has_refund'])) {
            return [];
        }
        if (isset($query['ps_order_id']) && is_numeric($query['ps_order_id']) && (int) $query['ps_order_id'] > 0) {
            $lookup = ['id_order' => (int) $query['ps_order_id']];
        } elseif (\is_string($query['reference'] ?? null) && preg_match(self::WEB_REFERENCE, $query['reference']) === 1) {
            $lookup = ['reference' => strtoupper($query['reference'])];
        } else {
            return [];
        }

        $orders = [];
        foreach ($this->lookupClient->lookup($lookup) as $order) {
            $id = (int) ($order['id_order'] ?? 0);
            // A TPV sale is always answered from order_meta, never as an online order.
            if ($id > 0 && $this->connection->fetchOne('SELECT 1 FROM order_meta WHERE ps_order_id = ?', [$id]) === false) {
                $orders[] = $this->hydrateOnlineOrder($order);
            }
        }

        return $orders;
    }

    /**
     * Refunded quantities are the larger of what the shop's credit slips say and
     * what this TPV refunded (or reserved with a pending refund); the amount already
     * refunded is exact for this TPV's refunds and prorated for slips made elsewhere,
     * so the last units take the remainder of the paid total.
     *
     * @param array<string, mixed> $order as the module's order lookup returns it
     *
     * @return array<string, mixed>
     */
    private function hydrateOnlineOrder(array $order): array
    {
        $psOrderId = (int) $order['id_order'];
        $local = $this->refundedByLine($psOrderId, null);

        $lines = [];
        foreach (array_values(\is_array($order['lines'] ?? null) ? $order['lines'] : []) as $index => $line) {
            $qty = max(0, (int) ($line['quantity'] ?? 0));
            $paid = round((float) ($line['paid_total'] ?? 0), 2);
            $slipped = max(0, $qty - (int) ($line['refundable_quantity'] ?? 0));
            $localQty = $local[$index]['quantity'] ?? 0;
            $refundedQty = min($qty, max($slipped, $localQty));
            $elsewhere = max(0, $slipped - $localQty);
            $refundedAmount = round(($local[$index]['amount'] ?? 0.0) + ($qty > 0 ? round($paid * $elsewhere / $qty, 2) : 0.0), 2);

            $lines[] = [
                'name' => (string) ($line['name'] ?? 'Producto'),
                'product_id' => (int) ($line['product_id'] ?? 0),
                'id_product_attribute' => (int) ($line['product_attribute_id'] ?? 0),
                'id_order_detail' => (int) ($line['id_order_detail'] ?? 0),
                'qty' => $qty,
                'price' => round((float) ($line['unit_price_tax_incl'] ?? 0), 2),
                'paid_total' => $paid,
                'refunded_qty' => $refundedQty,
                'refunded_line_amount' => $refundedAmount,
                'refundable_qty' => max(0, $qty - $refundedQty),
            ];
        }

        $state = \is_array($order['current_state'] ?? null) ? $order['current_state'] : [];

        return [
            'ps_order_id' => $psOrderId,
            'online' => true,
            'reference' => (string) ($order['reference'] ?? ''),
            'customer_name' => (string) ($order['customer_name'] ?? ''),
            'created_by_tpv' => !empty($order['created_by_tpv']),
            'state' => (string) ($state['name'] ?? ''),
            'delivered_at' => isset($order['delivered_at']) && \is_string($order['delivered_at']) && $order['delivered_at'] !== '' ? $order['delivered_at'] : null,
            'total' => round(array_sum(array_column($lines, 'paid_total')), 2),
            'payment_method' => 'online',
            'lines' => $lines,
            'tender' => null,
            'pending_refund' => $this->pendingRefund($psOrderId, null),
            'open_exchange' => $this->openExchange($psOrderId, null),
            'refunded_amount' => round((float) $this->connection->fetchOne("SELECT COALESCE(SUM(total), 0) FROM refund WHERE ps_order_id = ? AND status = 'done'", [$psOrderId]), 2),
            'created_at' => (string) ($order['date_add'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrder(int $psOrderId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::SALE_COLUMNS . ' FROM order_meta WHERE ps_order_id = ?',
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
            'SELECT ' . self::SALE_COLUMNS . ' FROM order_meta WHERE payment_breakdown LIKE ?',
            ['%"reference":"' . $reference . '"%']
        );
        if ($row === false) {
            $row = $this->connection->fetchAssociative('SELECT ' . self::SALE_COLUMNS . ' FROM order_meta WHERE ps_reference = ?', [$reference]);
        }

        if ($row === false) {
            return null;
        }

        return $this->hydrateOrder($row);
    }

    /**
     * A TPV sale by its local id (order_meta.id), uploaded to PrestaShop or not.
     *
     * @return array<string, mixed>|null
     */
    public function getOrderById(int $orderId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::SALE_COLUMNS . ' FROM order_meta WHERE id = ?', [$orderId]);

        return $row === false ? null : $this->hydrateOrder($row);
    }

    /**
     * The local id of a TPV sale named by its local id or its ticket code (also a
     * different PrestaShop reference, PRD S5), null when no TPV sale matches.
     */
    public function findSaleId(int $orderId, string $reference): ?int
    {
        if ($orderId > 0) {
            $id = $this->connection->fetchOne('SELECT id FROM order_meta WHERE id = ?', [$orderId]);
        } elseif (trim($reference) !== '') {
            $reference = strtoupper(trim($reference));
            $id = $this->connection->fetchOne('SELECT id FROM order_meta WHERE reference = ? OR ps_reference = ? ORDER BY id LIMIT 1', [$reference, $reference]);
        } else {
            return null;
        }

        return $id === false ? null : (int) $id;
    }

    /**
     * A sale not uploaded yet has ps_order_id null and synced false; its order_id
     * (local key) and reference (ticket code) identify it.
     *
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

        $psOrderId = isset($row['ps_order_id']) ? (int) $row['ps_order_id'] : null;
        $orderId = isset($row['id']) ? (int) $row['id'] : null;
        $lines = \is_array($breakdown['lines'] ?? null) ? array_values($breakdown['lines']) : [];
        // Refunds belong to the local sale (V32), also before it is uploaded.
        $refunded = $lines === [] ? [] : $this->refundedByLine($psOrderId, $orderId);
        foreach ($lines as $index => $line) {
            $refundedQty = $refunded[$index]['quantity'] ?? 0;
            $lines[$index]['refunded_qty'] = $refundedQty;
            $lines[$index]['refunded_line_amount'] = $refunded[$index]['amount'] ?? 0.0;
            $lines[$index]['refundable_qty'] = max(0, (int) ($line['qty'] ?? 0) - $refundedQty);
        }

        return [
            'order_id' => isset($row['id']) ? (int) $row['id'] : null,
            'ps_order_id' => $psOrderId,
            'synced' => $psOrderId !== null,
            'ps_reference' => isset($row['ps_reference']) && $row['ps_reference'] !== '' ? (string) $row['ps_reference'] : null,
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
            'pending_refund' => $lines === [] ? null : $this->pendingRefund($psOrderId, $orderId),
            'open_exchange' => $lines === [] ? null : $this->openExchange($psOrderId, $orderId),
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
    private function refundedByLine(?int $psOrderId, ?int $orderId): array
    {
        [$scope, $params] = self::scope('r.', $psOrderId, $orderId);
        if ($scope === null) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT rl.line_index, SUM(rl.quantity) AS quantity, SUM(rl.amount) AS amount
             FROM refund_line rl
             JOIN refund r ON r.id = rl.refund_id
             WHERE " . $scope . " AND r.status IN ('pending', 'done') AND rl.line_index IS NOT NULL
             GROUP BY rl.line_index",
            $params
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
    private function openExchange(?int $psOrderId, ?int $orderId): ?array
    {
        [$scope, $params] = self::scope('', $psOrderId, $orderId);
        if ($scope === null) {
            return null;
        }
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT id, status, credit_applied, new_total, balance, error_message FROM exchange WHERE " . $scope . " AND status IN ('pending', 'credit_open') ORDER BY id LIMIT 1",
                $params
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
    private function pendingRefund(?int $psOrderId, ?int $orderId): ?array
    {
        [$scope, $params] = self::scope('', $psOrderId, $orderId);
        if ($scope === null) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            "SELECT id, total FROM refund WHERE " . $scope . " AND status = 'pending' ORDER BY id LIMIT 1",
            $params
        );

        if (!\is_array($row) || !isset($row['id'])) {
            return null;
        }

        return ['id' => (int) $row['id'], 'total' => round((float) $row['total'], 2)];
    }

    /**
     * Rows (refunds, exchanges) of a TPV sale: by its local id, and by its PrestaShop
     * id for rows written before they knew the local sale; an online order only has
     * its PrestaShop id.
     *
     * @return array{0: ?string, 1: array<int, int>}
     */
    private static function scope(string $alias, ?int $psOrderId, ?int $orderId): array
    {
        if ($orderId !== null && $psOrderId !== null) {
            return ['(' . $alias . 'order_id = ? OR ' . $alias . 'ps_order_id = ?)', [$orderId, $psOrderId]];
        }
        if ($orderId !== null) {
            return [$alias . 'order_id = ?', [$orderId]];
        }
        if ($psOrderId !== null) {
            return [$alias . 'ps_order_id = ?', [$psOrderId]];
        }

        return [null, []];
    }
}
