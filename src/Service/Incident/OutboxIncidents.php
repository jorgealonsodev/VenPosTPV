<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Incident;

use Doctrine\DBAL\Connection;

/**
 * What the sync indicator and the incidents screen read from the outbox (PRD I1,
 * I3). PrestaShop rejected an incident row for good (404/422), or its answer could
 * not be recorded locally several times in a row: the data is corrected
 * in PrestaShop or in the TPV and the row is retried here; nothing else is changed.
 * A retried row goes back to `pending` and OutboxSync uploads it again, still behind
 * the earlier rows of its sale.
 *
 * An incident PrestaShop will never accept (a deleted product) can be discarded
 * instead: it becomes `discarded`, keeps its error and records who, when and why.
 * OutboxSync only claims pending rows whose earlier rows are done, so what could
 * never upload behind it is discarded with it: the later rows of its sale and the
 * rows that depend_on it, and in turn what waits behind those. Nothing else changes:
 * the local sale, its ticket and the cash stay as they are.
 */
class OutboxIncidents
{
    /** Rows that may still upload, and so may wait behind a discarded one. */
    private const WAITING = "('pending', 'incident')";

    private ?bool $discardReady = null;

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
     * @return array{pending: int, incidents: int, blocked: int} pending also counts the rows being
     *         uploaded now; blocked is the part of pending that waits for an incident (behind it in
     *         its sale, or for a customer in incident) and does not upload until it is retried
     */
    public function counts(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COALESCE(SUM(status IN ('pending', 'processing')), 0) AS pending,
                    COALESCE(SUM(status = 'incident'), 0) AS incidents
             FROM outbox WHERE status IN ('pending', 'processing', 'incident')"
        );
        $blocked = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox w
             WHERE w.status = 'pending' AND (
                 EXISTS (SELECT 1 FROM outbox p WHERE p.order_id = w.order_id AND p.sequence < w.sequence AND p.status = 'incident')
                 OR EXISTS (SELECT 1 FROM outbox d WHERE d.id = w.depends_on AND d.status = 'incident')
                 OR EXISTS (SELECT 1 FROM outbox p JOIN outbox d ON d.id = p.depends_on
                            WHERE p.order_id = w.order_id AND p.sequence < w.sequence AND d.status = 'incident')
             )"
        );

        return ['pending' => (int) ($row['pending'] ?? 0), 'incidents' => (int) ($row['incidents'] ?? 0), 'blocked' => (int) $blocked];
    }

    /**
     * Incident rows, grouped by sale in upload order.
     *
     * @return list<array{id: int, type: string, order_id: int|null, sequence: int, reference: string|null,
     *                    ps_reference: string|null, refund_id: int|null, credit_note_number: string|null, attempts: int,
     *                    last_error: string|null, created_at: string, updated_at: string, waiting_behind: int,
     *                    dependants: int}> dependants: the rows a discard of this one would discard too
     */
    public function list(int $limit = 200): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT o.id, o.type, o.order_id, o.sequence, m.reference, m.ps_reference,
                    (SELECT r.id FROM refund r WHERE r.request_id = o.request_id LIMIT 1) AS refund_id,
                    (SELECT r.credit_note_number FROM refund r WHERE r.request_id = o.request_id LIMIT 1) AS credit_note_number,
                    o.attempts, o.last_error, o.created_at, o.updated_at,
                    (SELECT COUNT(*) FROM outbox w
                     WHERE w.status IN ('pending', 'processing', 'incident') AND (
                         (o.order_id IS NOT NULL AND w.order_id = o.order_id AND w.sequence > o.sequence)
                         -- a sale for a customer created at the till, and what waits behind that sale
                         OR w.depends_on = o.id
                         OR w.order_id IN (SELECT d.order_id FROM outbox d WHERE d.depends_on = o.id AND d.order_id IS NOT NULL)
                     )) AS waiting_behind
             FROM outbox o
             LEFT JOIN order_meta m ON m.id = o.order_id
             WHERE o.status = 'incident'
             ORDER BY o.order_id IS NULL, o.order_id, o.sequence, o.id
             LIMIT " . max(1, min($limit, 1000))
        );

        return array_map(fn (array $row): array => [
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
            'dependants' => \count($this->dependants((int) $row['id'], false)),
        ], $rows);
    }

    /**
     * Discards one incident and what could never upload behind it (see the class
     * comment), all with the same who, when and why. A row that is no longer an
     * incident (retried, uploaded, discarded meanwhile) is left as it is.
     *
     * @return list<int>|null the discarded rows, this one first; null when it was not an incident
     *
     * @throws DiscardNotReady before migration Version00000036OutboxDiscard
     */
    public function discard(int $id, int $userId, ?string $reason): ?array
    {
        if (!$this->discardReady()) {
            throw new DiscardNotReady();
        }
        $now = $this->now();
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;

        return $this->connection->transactional(function () use ($id, $userId, $reason, $now): ?array {
            if ($this->connection->fetchOne("SELECT id FROM outbox WHERE id = ? AND status = 'incident' FOR UPDATE", [$id]) === false) {
                return null;
            }
            $ids = [$id, ...$this->dependants($id, true)];
            $this->connection->executeStatement(
                "UPDATE outbox SET status = 'discarded', discarded_at = ?, discarded_by_user_id = ?, discard_reason = ?,
                        claim_token = NULL, claimed_at = NULL, updated_at = ?
                 WHERE id IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ') AND status IN ' . self::WAITING,
                [$now, $userId, $reason, $now, ...$ids]
            );

            return $ids;
        });
    }

    /**
     * The last discarded rows, newest first, with who discarded them, when and why.
     * Empty before migration Version00000036OutboxDiscard.
     *
     * @return list<array{id: int, type: string, order_id: int|null, reference: string|null, ps_reference: string|null,
     *                    credit_note_number: string|null, last_error: string|null, discarded_at: string|null,
     *                    discarded_by: string|null, discard_reason: string|null}>
     */
    public function discarded(int $limit = 20): array
    {
        if (!$this->discardReady()) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT o.id, o.type, o.order_id, m.reference, m.ps_reference,
                    (SELECT r.credit_note_number FROM refund r WHERE r.request_id = o.request_id LIMIT 1) AS credit_note_number,
                    o.last_error, o.discarded_at, u.name AS discarded_by, o.discard_reason
             FROM outbox o
             LEFT JOIN order_meta m ON m.id = o.order_id
             LEFT JOIN user u ON u.id = o.discarded_by_user_id
             WHERE o.status = 'discarded'
             ORDER BY o.discarded_at DESC, o.id DESC
             LIMIT " . max(1, min($limit, 200))
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'reference' => $row['reference'] !== null ? (string) $row['reference'] : null,
            'ps_reference' => $row['ps_reference'] !== null && $row['ps_reference'] !== '' ? (string) $row['ps_reference'] : null,
            'credit_note_number' => $row['credit_note_number'] !== null ? (string) $row['credit_note_number'] : null,
            'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'discarded_at' => $row['discarded_at'] !== null ? (string) $row['discarded_at'] : null,
            'discarded_by' => $row['discarded_by'] !== null ? (string) $row['discarded_by'] : null,
            'discard_reason' => $row['discard_reason'] !== null ? (string) $row['discard_reason'] : null,
        ], $rows);
    }

    /**
     * What waits behind one row and could never upload without it: the later rows of
     * its sale and the rows that depend_on it, then the same for each of those. Only
     * rows that may still upload (pending, incident) count; in upload order.
     *
     * @return list<int>
     */
    private function dependants(int $id, bool $lock): array
    {
        $found = [];
        $frontier = [$id];
        while ($frontier !== [] && \count($found) < 1000) {
            $marks = implode(', ', array_fill(0, \count($frontier), '?'));
            $next = array_map('intval', $this->connection->fetchFirstColumn(
                "SELECT w.id FROM outbox w
                 WHERE w.status IN " . self::WAITING . " AND (
                     w.depends_on IN ({$marks})
                     OR EXISTS (SELECT 1 FROM outbox p WHERE p.id IN ({$marks}) AND p.order_id IS NOT NULL
                                AND w.order_id = p.order_id AND w.sequence > p.sequence)
                 )
                 ORDER BY w.order_id IS NULL, w.order_id, w.sequence, w.id" . ($lock ? ' FOR UPDATE' : ''),
                [...$frontier, ...$frontier]
            ));
            $frontier = [];
            foreach ($next as $candidate) {
                if ($candidate !== $id && !\in_array($candidate, $found, true)) {
                    $found[] = $candidate;
                    $frontier[] = $candidate;
                }
            }
        }

        return $found;
    }

    /** Whether migration Version00000036OutboxDiscard added the columns a discard records. */
    private function discardReady(): bool
    {
        return $this->discardReady ??= (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox'
               AND COLUMN_NAME IN ('discarded_at', 'discarded_by_user_id', 'discard_reason')"
        ) === 3;
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
            "UPDATE outbox SET status = 'pending', attempts = 0, record_failures = 0, next_attempt_at = ?, claim_token = NULL, claimed_at = NULL, updated_at = ?
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
