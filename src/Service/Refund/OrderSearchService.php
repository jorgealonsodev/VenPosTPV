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

        return [
            'ps_order_id' => (int) $row['ps_order_id'],
            'terminal_id' => (int) $row['terminal_id'],
            'session_id' => (int) $row['session_id'],
            'user_id' => (int) $row['user_id'],
            'reference' => $breakdown['reference'] ?? '',
            'total' => (float) ($breakdown['total'] ?? 0),
            'payment_method' => $breakdown['payment_method'] ?? '',
            'amount_tendered' => (float) ($breakdown['amount_tendered'] ?? 0),
            'change_due' => (float) ($breakdown['change_due'] ?? 0),
            'lines' => $breakdown['lines'] ?? [],
            'refunded_amount' => (float) $row['refunded_amount'],
            'created_at' => $row['created_at'],
        ];
    }
}
