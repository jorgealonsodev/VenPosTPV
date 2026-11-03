<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\PsRefundClient;
use VenPosTPV\Service\Refund\PsRestockClient;

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
 * - Refunds (type refund, PRD D2) wait behind their sale; their PrestaShop order id
 *   is read from the sale when they are sent, because a sale refunded before its
 *   upload has none yet. The module replays a refund or restock by its request_id,
 *   so sending one again after an unknown outcome never refunds twice. Restocks of
 *   refunds without a ticket (type restock) belong to no sale and wait for nothing.
 * - Customers created at the till (type customer, PRD C2) are uploaded through the
 *   webservice before the sales that use them: such a sale depends_on the
 *   customer's row and is not even claimed until that row is done, so a rejected
 *   customer (incident) keeps its sales waiting without spending their attempts.
 *   The sale is sent with the PrestaShop customer id read when it is sent. A
 *   customer is looked up by email before it is created, so a retry after an
 *   unknown outcome links the customer instead of creating another one. When that
 *   PrestaShop customer is already linked to another local customer (two tills
 *   created the same email), the row being uploaded is merged into that one: its
 *   waiting sales are pointed at it and the duplicate becomes inactive (merged_into).
 * - Recording PrestaShop's answer locally can fail too (a database error): it is
 *   retried with the same backoff, and after MAX_RECORD_FAILURES failures in a row
 *   the row becomes an incident instead of waiting silently for ever.
 */
class OutboxSync
{
    /** Minutes to wait after the 1st, 2nd... failed attempt; the last value repeats. */
    public const BACKOFF_MINUTES = [1, 2, 5, 15, 30, 60];

    /** Linking a created order is retried this many times after a deadlock. */
    private const LINK_TRIES = 3;

    /** Consecutive failures recording PrestaShop's answer before the row is an incident. */
    public const MAX_RECORD_FAILURES = 5;

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
        private readonly ?PsRefundClient $refundClient = null,
        private readonly ?PsRestockClient $restockClient = null,
        private readonly ?PrestaShopApiClient $customerClient = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Manual "sync now": pending rows waiting out their backoff become due at once.
     * Rows being processed or in incident are left alone.
     */
    public function makePendingDue(): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE outbox SET next_attempt_at = ? WHERE status = 'pending' AND next_attempt_at > ?",
            [$this->now(), $this->now()]
        );
    }

    /**
     * Uploads up to $limit due rows.
     *
     * @return array{uploaded: int, retried: int, incidents: int, recovered: int}
     */
    public function run(int $limit = 100): array
    {
        $counts = ['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => $this->recoverStuck()];
        $counts['incidents'] += $this->blockBehindDiscarded();

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
    public const BEHIND_DISCARDED_ERROR = 'Depende de una operación descartada';

    /**
     * A pending row behind a discarded one (an earlier row of its sale, or the row it
     * depends_on) can never upload: PrestaShop never got what it builds on. Instead of
     * waiting forever it becomes an incident, which the administrator can discard too.
     * Rows queued after a discard (a new refund of that sale, a new sale for that
     * customer) reach this; the discard itself already takes the rows waiting then.
     *
     * @return int rows moved to incident
     */
    private function blockBehindDiscarded(): int
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT o.id FROM outbox o
             WHERE o.status = 'pending'
               AND (EXISTS (SELECT 1 FROM outbox p WHERE p.order_id = o.order_id AND p.sequence < o.sequence AND p.status = ?)
                    OR EXISTS (SELECT 1 FROM outbox d WHERE d.id = o.depends_on AND d.status = ?))",
            [Outbox::STATUS_DISCARDED, Outbox::STATUS_DISCARDED]
        );

        $moved = 0;
        foreach ($ids as $id) {
            // Conditional: a row another worker already claimed or changed is left alone.
            $changed = $this->connection->executeStatement(
                "UPDATE outbox SET status = 'incident', last_error = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
                [self::BEHIND_DISCARDED_ERROR, $this->now(), (int) $id]
            );
            if ($changed === 1) {
                $this->logger?->warning('Outbox row {id} waits for a discarded row', ['id' => $id]);
                ++$moved;
            }
        }

        return $moved;
    }

    private function claimNext(): ?array
    {
        $now = $this->now();
        $candidates = $this->connection->fetchFirstColumn(
            "SELECT o.id FROM outbox o
             WHERE o.status = 'pending' AND o.next_attempt_at <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM outbox p WHERE p.order_id = o.order_id AND p.sequence < o.sequence AND p.status <> 'done'
               )
               AND (o.depends_on IS NULL OR EXISTS (SELECT 1 FROM outbox d WHERE d.id = o.depends_on AND d.status = 'done'))
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
        $payload = json_decode((string) $row['payload'], true);
        if (!\is_array($payload)) {
            return $this->incident($row, 'The stored upload is not valid JSON');
        }

        return match ($row['type']) {
            Outbox::TYPE_SALE => $this->processSale($row, $payload),
            Outbox::TYPE_REFUND => $this->processRefund($row, $payload),
            Outbox::TYPE_RESTOCK => $this->processRestock($row, $payload),
            Outbox::TYPE_CUSTOMER => $this->processCustomer($row, $payload),
            default => $this->incident($row, sprintf("Uploading '%s' operations is not supported", (string) $row['type'])),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     *
     * @return 'uploaded'|'retried'|'incidents'|null
     */
    private function processSale(array $row, array $payload): ?string
    {
        if (isset($payload['_customer_local_id'])) {
            // A customer created at the till: its PrestaShop id is known only now.
            // A duplicate merged meanwhile sells to the customer it was merged into.
            $psCustomerId = $this->connection->fetchOne(
                'SELECT COALESCE(m.ps_customer_id, c.ps_customer_id) FROM customer c LEFT JOIN customer m ON m.id = c.merged_into WHERE c.id = ?',
                [(int) $payload['_customer_local_id']]
            );
            if ($psCustomerId === false) {
                return $this->incident($row, sprintf('The customer %d of this sale does not exist', (int) $payload['_customer_local_id']));
            }
            if ($psCustomerId === null) {
                // Not reachable while the customer's own row is not done; kept safe anyway.
                return $this->retry($row, 'The customer of this sale is not in PrestaShop yet');
            }
            unset($payload['_customer_local_id']);
            $payload['id_customer'] = (int) $psCustomerId;
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
                return $this->recordingFailed($row, 'Linking the PrestaShop order failed: ' . $e->getMessage());
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
                "UPDATE outbox SET status = 'done', claim_token = NULL, last_error = NULL, record_failures = 0, done_at = ?, updated_at = ?
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

    /**
     * The credit slip of a refund of a TPV sale, sent once the sale is in PrestaShop.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload the module's refund request, plus `_local` (never sent)
     *
     * @return 'uploaded'|'retried'|'incidents'|null
     */
    private function processRefund(array $row, array $payload): ?string
    {
        if ($this->refundClient === null) {
            return $this->retry($row, 'The PrestaShop refund client is not configured');
        }
        $local = \is_array($payload['_local'] ?? null) ? $payload['_local'] : [];
        unset($payload['_local']);

        $psOrderId = $this->connection->fetchOne('SELECT ps_order_id FROM order_meta WHERE id = ?', [(int) $row['order_id']]);
        if ($psOrderId === false) {
            return $this->incident($row, sprintf('The sale %d of this refund does not exist', (int) $row['order_id']));
        }
        if ($psOrderId === null) {
            // Not reachable while the sale's own row is not done; kept safe anyway.
            return $this->retry($row, 'The sale of this refund is not in PrestaShop yet');
        }
        // The module request exactly as a refund always sent it (JSON storage turns 40.0 into 40).
        $payload = ['ps_order_id' => (int) $psOrderId] + $payload;
        $payload['lines'] = array_map(static fn (array $line): array => [
            'id_product' => (int) ($line['id_product'] ?? 0),
            'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
            'quantity' => (int) ($line['quantity'] ?? 0),
            'amount' => (float) ($line['amount'] ?? 0),
            'restock' => !empty($line['restock']),
        ], \is_array($payload['lines'] ?? null) ? array_values(array_filter($payload['lines'], 'is_array')) : []);

        try {
            $slip = $this->refundClient->createRefund($payload);
        } catch (RefundException $e) {
            // 404/422: definitely rejected. 409 (order busy) and the rest: outcome unknown.
            return \in_array($e->getCode(), [404, 422], true) ? $this->incident($row, $e->getMessage()) : $this->retry($row, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->retry($row, $e->getMessage());
        }

        return $this->closeWith($row, function () use ($local, $slip, $psOrderId): void {
            $refundId = (int) ($local['refund_id'] ?? 0);
            $slipId = $slip['order_slip_id'];
            $this->connection->executeStatement(
                'UPDATE refund SET ps_order_slip_id = ?, ps_order_id = COALESCE(ps_order_id, ?) WHERE id = ?',
                [$slipId, (int) $psOrderId, $refundId]
            );
            foreach ($slip['refunded_lines'] as $line) {
                // What PrestaShop really put back on sale; the local stock was raised at once.
                $this->connection->executeStatement(
                    'UPDATE refund_line SET restocked = ? WHERE refund_id = ? AND product_id = ? AND id_product_attribute = ?',
                    [!empty($line['restocked']) ? 1 : 0, $refundId, (int) ($line['id_product'] ?? 0), (int) ($line['id_product_attribute'] ?? 0)]
                );
            }
            if (isset($local['movement_id']) && (int) $local['movement_id'] > 0) {
                $this->connection->executeStatement(
                    'UPDATE movement SET ps_order_slip_id = ?, ps_order_id = COALESCE(ps_order_id, ?) WHERE id = ?',
                    [$slipId, (int) $psOrderId, (int) $local['movement_id']]
                );
            }
        });
    }

    /**
     * The restock of a refund without a ticket.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload {request_id, lines, _local}
     *
     * @return 'uploaded'|'retried'|'incidents'|null
     */
    private function processRestock(array $row, array $payload): ?string
    {
        if ($this->restockClient === null) {
            return $this->retry($row, 'The PrestaShop restock client is not configured');
        }
        $local = \is_array($payload['_local'] ?? null) ? $payload['_local'] : [];

        try {
            $restock = $this->restockClient->restock([
                'request_id' => (string) ($payload['request_id'] ?? $row['request_id']),
                'lines' => \is_array($payload['lines'] ?? null) ? $payload['lines'] : [],
            ]);
        } catch (RefundException $e) {
            return \in_array($e->getCode(), [404, 422], true) ? $this->incident($row, $e->getMessage()) : $this->retry($row, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->retry($row, $e->getMessage());
        }

        return $this->closeWith($row, function () use ($local, $restock): void {
            foreach ($restock['restocked_lines'] as $line) {
                $this->connection->executeStatement(
                    'UPDATE refund_line SET restocked = ? WHERE refund_id = ? AND product_id = ? AND id_product_attribute = ?',
                    [!empty($line['restocked']) ? 1 : 0, (int) ($local['refund_id'] ?? 0), (int) ($line['id_product'] ?? 0), (int) ($line['id_product_attribute'] ?? 0)]
                );
            }
        });
    }

    /**
     * A customer created at the till: linked to the PrestaShop customer with its
     * email when there is one, created through the webservice otherwise.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload {firstname, lastname, email, _local: {customer_id}}
     *
     * @return 'uploaded'|'retried'|'incidents'|null
     */
    private function processCustomer(array $row, array $payload): ?string
    {
        if ($this->customerClient === null) {
            return $this->retry($row, 'The PrestaShop customer client is not configured');
        }
        $localId = (int) (\is_array($payload['_local'] ?? null) ? ($payload['_local']['customer_id'] ?? 0) : 0);
        $email = (string) ($payload['email'] ?? '');

        try {
            $psCustomerId = $this->customerClient->findCustomerIdByEmail($email)
                ?? $this->customerClient->createCustomer((string) ($payload['firstname'] ?? ''), (string) ($payload['lastname'] ?? ''), $email);
        } catch (PsApiException $e) {
            // 400/422: PrestaShop refused the data; 401/403/404/405: the webservice key
            // cannot create customers. The administrator fixes it and retries.
            return \in_array($e->getCode(), [400, 401, 403, 404, 405, 422], true) ? $this->incident($row, $e->getMessage()) : $this->retry($row, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->retry($row, $e->getMessage());
        }

        return $this->closeWith($row, function () use ($row, $localId, $psCustomerId): void {
            // The copy the customer sync may have brought meanwhile is the same person.
            $this->connection->executeStatement(
                'DELETE FROM customer WHERE ps_customer_id = ? AND id <> ? AND outbox_id IS NULL',
                [$psCustomerId, $localId]
            );
            $now = $this->now();
            // Another customer created at a till already has this PrestaShop customer.
            $survivor = $this->connection->fetchAssociative(
                'SELECT id, outbox_id FROM customer WHERE ps_customer_id = ? AND id <> ? FOR UPDATE',
                [$psCustomerId, $localId]
            );
            if ($survivor !== false) {
                $this->mergeCustomer($localId, (int) $row['id'], (int) $survivor['id'], $survivor['outbox_id'] !== null ? (int) $survivor['outbox_id'] : null, $now);

                return;
            }
            $this->connection->executeStatement(
                'UPDATE customer SET ps_customer_id = ?, synced_at = ?, updated_at = ? WHERE id = ?',
                [$psCustomerId, $now, $now, $localId]
            );
        });
    }

    /**
     * Merges a customer created at the till into the local customer that already has
     * its PrestaShop customer: the sales still waiting for the duplicate's upload
     * wait for (and are sent with) the survivor, and the duplicate stays inactive,
     * pointing at the survivor, for carts and parked tickets that still name it.
     */
    private function mergeCustomer(int $duplicateId, int $duplicateOutboxId, int $survivorId, ?int $survivorOutboxId, string $now): void
    {
        $this->logger?->notice('Customer {duplicate} has the PrestaShop customer of {survivor}: merged', ['duplicate' => $duplicateId, 'survivor' => $survivorId]);
        foreach ($this->connection->fetchFirstColumn("SELECT id FROM outbox WHERE depends_on = ? AND status <> 'done'", [$duplicateOutboxId]) as $dependantId) {
            $this->connection->executeStatement(
                "UPDATE outbox SET depends_on = ?,
                     payload = IF(JSON_EXTRACT(payload, '$._customer_local_id') = ?, JSON_SET(payload, '$._customer_local_id', ?), payload),
                     updated_at = ?
                 WHERE id = ?",
                [$survivorOutboxId, $duplicateId, $survivorId, $now, (int) $dependantId],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER]
            );
        }
        $this->connection->executeStatement(
            'UPDATE customer SET merged_into = ?, updated_at = ? WHERE merged_into = ?',
            [$survivorId, $now, $duplicateId]
        );
        $this->connection->executeStatement(
            'UPDATE customer SET active = 0, merged_into = ?, synced_at = ?, updated_at = ? WHERE id = ?',
            [$survivorId, $now, $now, $duplicateId]
        );
    }

    /**
     * Marks a claimed row done and records what PrestaShop answered, in one
     * transaction; only the holder of the claim token may close it.
     *
     * @param array<string, mixed> $row
     * @param callable(): void $record
     *
     * @return 'uploaded'|'retried'|null
     */
    private function closeWith(array $row, callable $record): ?string
    {
        $now = $this->now();
        try {
            $closed = $this->connection->transactional(function () use ($row, $record, $now): bool {
                $done = $this->connection->executeStatement(
                    "UPDATE outbox SET status = 'done', claim_token = NULL, last_error = NULL, record_failures = 0, done_at = ?, updated_at = ?
                     WHERE id = ? AND claim_token = ?",
                    [$now, $now, (int) $row['id'], (string) $row['claim_token']]
                );
                if ($done !== 1) {
                    return false;
                }
                $record();

                return true;
            });
        } catch (\Throwable $e) {
            // PrestaShop already has it: the next attempt is replayed by request_id.
            return $this->recordingFailed($row, 'Recording the PrestaShop answer failed: ' . $e->getMessage());
        }

        return $closed ? 'uploaded' : null;
    }

    /**
     * PrestaShop answered but the answer could not be stored here. Retried like a
     * temporary error, until it failed MAX_RECORD_FAILURES times in a row: then the
     * administrator sees it on the incidents screen.
     *
     * @param array<string, mixed> $row
     *
     * @return 'retried'|'incidents'|null
     */
    private function recordingFailed(array $row, string $error): ?string
    {
        $this->connection->executeStatement(
            'UPDATE outbox SET record_failures = record_failures + 1 WHERE id = ? AND claim_token = ?',
            [(int) $row['id'], (string) $row['claim_token']]
        );
        $failures = (int) $this->connection->fetchOne('SELECT record_failures FROM outbox WHERE id = ? AND claim_token = ?', [(int) $row['id'], (string) $row['claim_token']]);
        if ($failures >= self::MAX_RECORD_FAILURES) {
            return $this->incident($row, sprintf('Failed %d times in a row. %s', $failures, $error));
        }

        return $this->retry($row, $error, true);
    }

    /**
     * @param array<string, mixed> $row
     * @param bool $recording a failure recording PrestaShop's answer, which keeps counting;
     *                        any other failure starts that count again
     *
     * @return 'retried'|null
     */
    private function retry(array $row, string $error, bool $recording = false): ?string
    {
        $attempts = max(1, (int) $row['attempts']);
        $minutes = self::BACKOFF_MINUTES[min($attempts, \count(self::BACKOFF_MINUTES)) - 1];
        $now = ($this->clock)();
        $released = $this->connection->executeStatement(
            "UPDATE outbox SET status = 'pending', claim_token = NULL, claimed_at = NULL, next_attempt_at = ?, last_error = ?, updated_at = ?,
                 record_failures = IF(?, record_failures, 0)
             WHERE id = ? AND claim_token = ?",
            [$now->modify(sprintf('+%d minutes', $minutes))->format('Y-m-d H:i:s'), mb_substr($error, 0, 1000), $now->format('Y-m-d H:i:s'), $recording ? 1 : 0, (int) $row['id'], (string) $row['claim_token']]
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
