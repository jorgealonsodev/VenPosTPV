<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;

/**
 * Uploads the outbox to PrestaShop (PRD S1-S5).
 *
 * - Order: a row is due only when every earlier row of the same sale is done, so a
 *   sale always reaches PrestaShop before its refunds and exchanges.
 * - One uploader per row: a worker claims a row with a conditional UPDATE
 *   (pending -> processing with its own claim token) and only the holder of the
 *   token may close it. MariaDB 10.4 has no SKIP LOCKED, and no lock is held while
 *   PrestaShop answers. A row left in processing by a dead worker goes back to
 *   pending after STUCK_AFTER_SECONDS; uploading it again is safe because the
 *   module replays an order that already has the ticket code as reference.
 * - Temporary errors (network, 409 lock busy, 5xx, timeouts) wait 1, 2, 5, 15, 30
 *   and then 60 minutes between attempts. Definitive rejections (404, 422) become
 *   an incident for the administrator; the local sale and the cash stay as they are.
 * - Stock is never pushed: creating the order lowers PrestaShop's stock, and the
 *   sale already lowered the local copy.
 */
class OutboxSync
{
    /** Minutes to wait after the 1st, 2nd... failed attempt; the last value repeats. */
    public const BACKOFF_MINUTES = [1, 2, 5, 15, 30, 60];

    /** Linking a created order is retried this many times after a deadlock. */
    private const LINK_TRIES = 3;

    /** A claim older than this belongs to a worker that died. */
    public const STUCK_AFTER_SECONDS = 300;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly Connection $connection,
        private readonly PsOrderClient $client,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Uploads up to $limit due rows.
     *
     * @return array{uploaded: int, retried: int, incidents: int, recovered: int}
     */
    public function run(int $limit = 100): array
    {
        $counts = ['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => $this->recoverStuck()];

        for ($i = 0; $i < $limit; ++$i) {
            $row = $this->claimNext();
            if ($row === null) {
                break;
            }
            $outcome = $this->process($row);
            if ($outcome !== null) {
                ++$counts[$outcome];
            }
        }

        return $counts;
    }

    private function now(): string
    {
        return ($this->clock)()->format('Y-m-d H:i:s');
    }

    private function recoverStuck(): int
    {
        $now = ($this->clock)();

        return (int) $this->connection->executeStatement(
            "UPDATE outbox SET status = 'pending', claim_token = NULL, claimed_at = NULL, next_attempt_at = ?, updated_at = ?,
                 last_error = 'The upload was interrupted; it is tried again'
             WHERE status = 'processing' AND claimed_at < ?",
            [$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $now->modify(sprintf('-%d seconds', self::STUCK_AFTER_SECONDS))->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Claims the oldest due row whose earlier rows are all done.
     *
     * @return array<string, mixed>|null the claimed row, with its claim_token
     */
    private function claimNext(): ?array
    {
        $now = $this->now();
        $candidates = $this->connection->fetchFirstColumn(
            "SELECT o.id FROM outbox o
             WHERE o.status = 'pending' AND o.next_attempt_at <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM outbox p WHERE p.order_id = o.order_id AND p.sequence < o.sequence AND p.status <> 'done'
               )
             ORDER BY o.id LIMIT 10",
            [$now]
        );

        foreach ($candidates as $id) {
            $token = bin2hex(random_bytes(16));
            // Only one worker can move the row out of pending.
            $claimed = $this->connection->executeStatement(
                "UPDATE outbox SET status = 'processing', claim_token = ?, claimed_at = ?, attempts = attempts + 1, updated_at = ?
                 WHERE id = ? AND status = 'pending' AND next_attempt_at <= ?",
                [$token, $now, $now, (int) $id, $now]
            );
            if ($claimed === 1) {
                $row = $this->connection->fetchAssociative('SELECT * FROM outbox WHERE id = ? AND claim_token = ?', [(int) $id, $token]);
                if ($row !== false) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return 'uploaded'|'retried'|'incidents'|null null when the claim was lost
     */
    private function process(array $row): ?string
    {
        if ($row['type'] !== Outbox::TYPE_SALE) {
            return $this->incident($row, sprintf("Uploading '%s' operations is not supported yet", (string) $row['type']));
        }

        $payload = json_decode((string) $row['payload'], true);
        if (!\is_array($payload)) {
            return $this->incident($row, 'The stored upload is not valid JSON');
        }

        try {
            $order = $this->client->createReferencedOrder($payload);
        } catch (CheckoutException $e) {
            return $e->getCode() === 422 ? $this->incident($row, $e->getMessage()) : $this->retry($row, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->incident($row, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->retry($row, $e->getMessage());
        }

        return $this->link($row, $order);
    }

    /**
     * Links the created order to the local sale, retrying a deadlock between
     * concurrent workers (nothing was written then).
     *
     * @param array<string, mixed> $row
     * @param array{order_id: int, reference: string, total_paid: float, replayed: bool} $order
     *
     * @return 'uploaded'|'retried'|'incidents'|null
     */
    private function link(array $row, array $order): ?string
    {
        for ($try = 1; ; ++$try) {
            try {
                return $this->completeSale($row, $order) ? 'uploaded' : null;
            } catch (RetryableException $e) {
                if ($try < self::LINK_TRIES) {
                    usleep(random_int(5000, 50000));
                    continue;
                }

                // The order exists in PrestaShop: the next attempt replays and links it.
                return $this->retry($row, 'Linking the PrestaShop order failed: ' . $e->getMessage());
            } catch (\Throwable $e) {
                // E.g. the PrestaShop id is already linked to another local sale.
                $this->logger?->error('Outbox row {id}: PrestaShop order {order} could not be linked: {message}', ['id' => $row['id'], 'order' => $order['order_id'], 'message' => $e->getMessage()]);

                return $this->incident($row, sprintf('PrestaShop order %d could not be linked to the sale: %s', $order['order_id'], $e->getMessage()));
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array{order_id: int, reference: string, total_paid: float, replayed: bool} $order
     *
     * @return bool false when another worker took the row over in the meantime
     */
    private function completeSale(array $row, array $order): bool
    {
        $orderId = (int) $row['order_id'];
        $now = $this->now();

        return $this->connection->transactional(function () use ($row, $order, $orderId, $now): bool {
            $done = $this->connection->executeStatement(
                "UPDATE outbox SET status = 'done', claim_token = NULL, last_error = NULL, done_at = ?, updated_at = ?
                 WHERE id = ? AND claim_token = ?",
                [$now, $now, (int) $row['id'], (string) $row['claim_token']]
            );
            if ($done !== 1) {
                return false;
            }

            $reference = (string) $this->connection->fetchOne('SELECT reference FROM order_meta WHERE id = ? FOR UPDATE', [$orderId]);
            // PRD S5: a different PrestaShop reference is kept, so the union is never lost.
            $psReference = $order['reference'] !== $reference ? mb_substr($order['reference'], 0, 9) : null;
            $this->connection->executeStatement(
                'UPDATE order_meta SET ps_order_id = ?, ps_reference = ?, synced = 1 WHERE id = ?',
                [$order['order_id'], $psReference, $orderId]
            );
            // By primary key: an UPDATE through the order_id index takes gap locks that
            // make concurrent workers deadlock.
            foreach ($this->connection->fetchFirstColumn('SELECT id FROM movement WHERE order_id = ? AND ps_order_id IS NULL', [$orderId]) as $movementId) {
                $this->connection->executeStatement(
                    'UPDATE movement SET ps_order_id = ? WHERE id = ? AND ps_order_id IS NULL',
                    [$order['order_id'], (int) $movementId]
                );
            }

            return true;
        });
    }

    /** @param array<string, mixed> $row @return 'retried'|null */
    private function retry(array $row, string $error): ?string
    {
        $attempts = max(1, (int) $row['attempts']);
        $minutes = self::BACKOFF_MINUTES[min($attempts, \count(self::BACKOFF_MINUTES)) - 1];
        $now = ($this->clock)();
        $released = $this->connection->executeStatement(
            "UPDATE outbox SET status = 'pending', claim_token = NULL, claimed_at = NULL, next_attempt_at = ?, last_error = ?, updated_at = ?
             WHERE id = ? AND claim_token = ?",
            [$now->modify(sprintf('+%d minutes', $minutes))->format('Y-m-d H:i:s'), mb_substr($error, 0, 1000), $now->format('Y-m-d H:i:s'), (int) $row['id'], (string) $row['claim_token']]
        );

        return $released === 1 ? 'retried' : null;
    }

    /** @param array<string, mixed> $row @return 'incidents'|null */
    private function incident(array $row, string $error): ?string
    {
        $this->logger?->warning('Outbox row {id} needs the administrator: {error}', ['id' => $row['id'], 'error' => $error]);
        $now = $this->now();
        $closed = $this->connection->executeStatement(
            "UPDATE outbox SET status = 'incident', claim_token = NULL, last_error = ?, updated_at = ?
             WHERE id = ? AND claim_token = ?",
            [mb_substr($error, 0, 1000), $now, (int) $row['id'], (string) $row['claim_token']]
        );

        return $closed === 1 ? 'incidents' : null;
    }
}
