<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Incident;

use Doctrine\DBAL\Connection;

/**
 * What the sync indicator and the incidents screen read from the outbox (PRD I1,
 * I3). PrestaShop rejected an incident row for good (404/422): the data is corrected
 * in PrestaShop or in the TPV and the row is retried here; nothing else is changed.
 * A retried row goes back to `pending` and OutboxSync uploads it again, still behind
 * the earlier rows of its sale.
 */
class OutboxIncidents
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly Connection $connection,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * @return array{pending: int, incidents: int} pending also counts the rows being uploaded now
     */
    public function counts(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COALESCE(SUM(status IN ('pending', 'processing')), 0) AS pending,
                    COALESCE(SUM(status = 'incident'), 0) AS incidents
             FROM outbox WHERE status IN ('pending', 'processing', 'incident')"
        );

        return ['pending' => (int) ($row['pending'] ?? 0), 'incidents' => (int) ($row['incidents'] ?? 0)];
    }

    /**
     * Incident rows, grouped by sale in upload order.
     *
     * @return list<array{id: int, type: string, order_id: int|null, sequence: int, reference: string|null,
     *                    ps_reference: string|null, refund_id: int|null, credit_note_number: string|null, attempts: int,
     *                    last_error: string|null, created_at: string, updated_at: string, waiting_behind: int}>
     */
    public function list(int $limit = 200): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT o.id, o.type, o.order_id, o.sequence, m.reference, m.ps_reference,
                    (SELECT r.id FROM refund r WHERE r.request_id = o.request_id LIMIT 1) AS refund_id,
                    (SELECT r.credit_note_number FROM refund r WHERE r.request_id = o.request_id LIMIT 1) AS credit_note_number,
                    o.attempts, o.last_error, o.created_at, o.updated_at,
                    (SELECT COUNT(*) FROM outbox w
                     WHERE o.order_id IS NOT NULL AND w.order_id = o.order_id AND w.sequence > o.sequence AND w.status <> 'done') AS waiting_behind
             FROM outbox o
             LEFT JOIN order_meta m ON m.id = o.order_id
             WHERE o.status = 'incident'
             ORDER BY o.order_id IS NULL, o.order_id, o.sequence, o.id
             LIMIT " . max(1, min($limit, 1000))
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'sequence' => (int) $row['sequence'],
            'reference' => $row['reference'] !== null ? (string) $row['reference'] : null,
            'ps_reference' => $row['ps_reference'] !== null && $row['ps_reference'] !== '' ? (string) $row['ps_reference'] : null,
            'refund_id' => $row['refund_id'] !== null ? (int) $row['refund_id'] : null,
            'credit_note_number' => $row['credit_note_number'] !== null ? (string) $row['credit_note_number'] : null,
            'attempts' => (int) $row['attempts'],
            'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'waiting_behind' => (int) $row['waiting_behind'],
        ], $rows);
    }

    /**
     * Puts one incident back in the queue, due now and with its attempts reset. A row
     * that is no longer an incident (retried meanwhile, uploaded) is left as it is.
     *
     * @return bool whether the row was an incident and is pending now
     */
    public function retry(int $id): bool
    {
        $now = $this->now();

        return $this->connection->executeStatement(
            "UPDATE outbox SET status = 'pending', attempts = 0, next_attempt_at = ?, claim_token = NULL, claimed_at = NULL, updated_at = ?
             WHERE id = ? AND status = 'incident'",
            [$now, $now, $id]
        ) === 1;
    }

    /**
     * Retries every incident of one sale (the sale and its refunds). OutboxSync still
     * uploads them in sequence order: a row waits until the earlier ones are done.
     *
     * @return list<int> the rows put back in the queue, in upload order
     */
    public function retryOrder(int $orderId): array
    {
        return $this->connection->transactional(function () use ($orderId): array {
            $ids = array_map('intval', $this->connection->fetchFirstColumn(
                "SELECT id FROM outbox WHERE order_id = ? AND status = 'incident' ORDER BY sequence FOR UPDATE",
                [$orderId]
            ));

            return array_values(array_filter($ids, fn (int $id): bool => $this->retry($id)));
        });
    }

    private function now(): string
    {
        return ($this->clock)()->format('Y-m-d H:i:s');
    }
}
