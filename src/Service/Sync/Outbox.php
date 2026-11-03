<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;

/**
 * The uploads to PrestaShop still to do (PRD S1, S2). A row is written in the same
 * transaction as the local operation it uploads, so an operation is never stored
 * without its upload, nor uploaded without being stored. Rows of one sale upload in
 * `sequence` order: 1 is the sale itself, its refunds and exchanges come after.
 */
class Outbox
{
    public const TYPE_SALE = 'sale';

    /** The credit slip and restock of a refund of a TPV sale, behind its sale (PRD D2). */
    public const TYPE_REFUND = 'refund';

    /** The restock of a refund without a ticket: it belongs to no sale. */
    public const TYPE_RESTOCK = 'restock';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_INCIDENT = 'incident';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Queues the upload of a new sale. A new sale is always the first operation of
     * its order, so its sequence is 1 without reading the table (a locking read of
     * a missing range would make concurrent sales deadlock on the index gap).
     *
     * @param array<string, mixed> $payload the module's order request, with the ticket code as `reference`
     *
     * @return string the request_id
     *
     * @throws \LogicException outside the sale transaction
     */
    public function enqueueSale(int $orderId, array $payload): string
    {
        return $this->insert(self::TYPE_SALE, $orderId, 1, $payload);
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

    /** @param array<string, mixed> $payload */
    private function insert(string $type, ?int $orderId, int $sequence, array $payload, ?string $requestId = null): string
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
        ]);

        return $requestId;
    }
}
