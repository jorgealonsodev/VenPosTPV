<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;

/**
 * The uploads to PrestaShop still to do (PRD S1, S2). A row is written in the same
 * transaction as the local operation it uploads, so an operation is never stored
 * without its upload, nor uploaded without being stored. Rows of one sale upload in
 * `sequence` order: 1 is the sale itself, its refunds and exchanges come after.
 * A row may also wait for one other row (`depends_on`): a sale for a customer
 * created at the till waits for that customer's upload (PRD C2).
 */
class Outbox
{
    public const TYPE_SALE = 'sale';

    /** The credit slip and restock of a refund of a TPV sale, behind its sale (PRD D2). */
    public const TYPE_REFUND = 'refund';

    /** The restock of a refund without a ticket: it belongs to no sale. */
    public const TYPE_RESTOCK = 'restock';

    /**
     * A customer created at the till (PRD C2). It belongs to no sale; every sale for
     * that customer waits for it (depends_on) and is sent with its PrestaShop id.
     */
    public const TYPE_CUSTOMER = 'customer';

    /**
     * One line of the fiscal journal for the shop copy (fiscal-journal D6, S8). It
     * belongs to no sale: `sequence` is the line's seq, and OutboxSync sends the
     * lines of the journal in that order. The payload carries the line exactly as
     * the journal file holds it, so sending never reads the journal.
     */
    public const TYPE_JOURNAL = 'journal';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_INCIDENT = 'incident';

    /**
     * Given up for good by an administrator (PRD I3): never uploaded, and nothing
     * that waits for it can upload either.
     */
    public const STATUS_DISCARDED = 'discarded';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Queues the upload of a new sale. A new sale is always the first operation of
     * its order, so its sequence is 1 without reading the table (a locking read of
     * a missing range would make concurrent sales deadlock on the index gap).
     *
     * @param array<string, mixed> $payload the module's order request, with the ticket code as `reference`
     * @param int|null $dependsOn the outbox row (a new customer's upload) that must be done first
     *
     * @return string the request_id
     *
     * @throws \LogicException outside the sale transaction
     */
    public function enqueueSale(int $orderId, array $payload, ?int $dependsOn = null): string
    {
        return $this->insert(self::TYPE_SALE, $orderId, 1, $payload, null, $dependsOn);
    }

    /**
     * Queues the upload of a customer created at the till; it waits for nothing.
     *
     * @param array<string, mixed> $payload firstname, lastname, email
     *
     * @return int the outbox row id, which the customer's sales depend on
     */
    public function enqueueCustomer(int $customerId, array $payload): int
    {
        $this->insert(self::TYPE_CUSTOMER, null, 1, $payload + ['_local' => ['customer_id' => $customerId]]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Queues an operation that follows an existing sale (refund, exchange). The
     * caller must hold the sale's order_meta row lock, which serializes the
     * sequence numbers of one order.
     *
     * @param array<string, mixed> $payload
     * @param string|null $requestId the operation's own idempotency key, so the module
     *                               replays it by the same request_id; a new one when null
     */
    public function enqueueAfter(string $type, int $orderId, array $payload, ?string $requestId = null): string
    {
        $sequence = 1 + (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM outbox WHERE order_id = ?', [$orderId]);

        return $this->insert($type, $orderId, $sequence, $payload, $requestId);
    }

    /**
     * Queues an operation that belongs to no sale (the restock of a refund without a
     * ticket): it waits for nothing.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueueStandalone(string $type, array $payload, ?string $requestId = null): string
    {
        return $this->insert($type, null, 1, $payload, $requestId);
    }

    /**
     * Queues one journal line for the shop copy (the caller holds a transaction).
     *
     * @param array<string, mixed> $line a valid journal line (seq, fiscal_record, line_hash)
     *
     * @return string the request_id, which starts with journalRequestPrefix($journalId)
     */
    public function enqueueJournal(string $journalId, array $line): string
    {
        $seq = (int) $line['seq'];
        $record = \is_array($line['fiscal_record'] ?? null) ? $line['fiscal_record'] : [];

        return $this->insert(self::TYPE_JOURNAL, null, $seq, [
            'journal_id' => strtolower($journalId),
            'seq' => $seq,
            'number' => (string) ($record['number'] ?? ''),
            'invoice_type' => (string) ($record['invoice_type'] ?? ''),
            'hash' => (string) ($record['hash'] ?? ''),
            'line_hash' => (string) $line['line_hash'],
            'line' => rtrim(JournalLocation::encode($line), "\n"),
        ], self::journalRequestPrefix($journalId) . $seq . '-' . bin2hex(random_bytes(6)));
    }

    /**
     * The start of the request_id of every row of one journal, so its rows are found
     * by index (a line can be queued again after a restore, with a new request_id).
     */
    public static function journalRequestPrefix(string $journalId): string
    {
        return 'journal-' . substr(str_replace('-', '', strtolower($journalId)), 0, 16) . '-';
    }

    /** @param array<string, mixed> $payload */
    private function insert(string $type, ?int $orderId, int $sequence, array $payload, ?string $requestId = null, ?int $dependsOn = null): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Outbox rows must be written inside the transaction of their operation');
        }

        $requestId ??= bin2hex(random_bytes(16));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('outbox', [
            'type' => $type,
            'order_id' => $orderId,
            'sequence' => $sequence,
            'request_id' => $requestId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ] + ($dependsOn !== null ? ['depends_on' => $dependsOn] : []));

        return $requestId;
    }
}
