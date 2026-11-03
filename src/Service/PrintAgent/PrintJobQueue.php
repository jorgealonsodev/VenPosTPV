<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use VenPosTPV\Service\Print\OrderPrintedMarker;

/**
 * The print queue of each till for its print agent (PRD T0-T9).
 *
 * - A job is created in the same request that prints (T0) with the exact ESC/POS
 *   bytes of today's ticket (T1); a drawer job carries no data, the agent makes the
 *   pulse (T8). Types `ticket`, `drawer`, `label`; destinations `ticket`/`label`.
 * - The agent claims jobs of its own till with one conditional UPDATE in priority
 *   (ticket and drawer before labels, T7) and id order, like the outbox: two agents
 *   or two requests never get the same job. Each claim has a `claim_token` and a
 *   60 s lease (30 s from a long poll); an unconfirmed lease returns the job to the
 *   queue (T4).
 * - Confirming is idempotent. A failed job is retried after 2, 5 and 15 s while it
 *   has not expired (T5). Expiry: drawer 15 s, ticket 2 min, labels 24 h (T6). A
 *   "done" with a valid claim up to 30 s after expiry is accepted for tickets and
 *   labels (they did print); the drawer keeps strict expiry. "done" clears the error
 *   of an earlier failed attempt.
 * - Error texts are cut to 500 characters (E8).
 */
class PrintJobQueue
{
    public const LEASE_SECONDS = 60;
    /**
     * Lease of a claim made by a long poll: a stopped agent's last poll may claim into
     * a dead connection, so its jobs come back sooner (the agent can still extend).
     */
    public const LONG_POLL_LEASE_SECONDS = 30;
    /** A "done" this long after expiry is still accepted for tickets and labels: it did print (T6). */
    public const DONE_GRACE_SECONDS = 30;
    public const MAX_PAYLOAD_BYTES = 1048576;
    public const CLAIM_LIMIT = 5;
    public const TYPES = ['ticket', 'drawer', 'label'];
    public const STATUSES = ['pending', 'claimed', 'done', 'failed', 'expired', 'cancelled'];
    /** @var array<string, int> */
    public const EXPIRY_SECONDS = ['drawer' => 15, 'ticket' => 120, 'label' => 86400];
    /** @var list<int> */
    public const RETRY_DELAYS = [2, 5, 15];
    public const ERROR_MAX_CHARS = 500;
    private const CLAIM_ATTEMPTS = 5;

    /** @var array<string, int> */
    private const PRIORITY = ['ticket' => 0, 'drawer' => 0, 'label' => 10];
    /** Tickets whose confirmation marks the sale printed. */
    private const SALE_SOURCES = ['sale', 'gift', 'reprint'];

    private readonly ClockInterface $clock;

    public function __construct(private readonly Connection $connection, ?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * @param string|null $payload the exact bytes for the printer; null for a drawer
     */
    public function enqueue(int $terminalId, string $type, ?string $payload, string $source, ?string $sourceRef = null, ?int $userId = null): int
    {
        if ($terminalId <= 0) {
            throw new TerminalNotResolvedException();
        }
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown print job type "%s"', $type));
        }
        if ($type === 'drawer') {
            $payload = null;
        } elseif ($payload === null || $payload === '') {
            throw new \InvalidArgumentException('A print job needs its data');
        }
        if ($payload !== null && \strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \LengthException('A print job is limited to 1 MB');
        }

        $now = $this->clock->now();
        $this->connection->insert('print_job', [
            'terminal_id' => $terminalId,
            'type' => $type,
            'target' => $type === 'label' ? 'label' : 'ticket',
            'priority' => self::PRIORITY[$type],
            'status' => 'pending',
            'expires_at' => $now->modify(sprintf('+%d seconds', self::EXPIRY_SECONDS[$type]))->format('Y-m-d H:i:s'),
            'payload' => $payload,
            'payload_size' => $payload === null ? 0 : \strlen($payload),
            'sha256' => $payload === null ? null : hash('sha256', $payload),
            'source' => mb_substr($source, 0, 32),
            'source_ref' => $sourceRef !== null ? mb_substr($sourceRef, 0, 64) : null,
            'created_by_user_id' => $userId,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ], ['payload' => ParameterType::LARGE_OBJECT]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Claims the due jobs of one till for its agent.
     *
     * @return list<array{id: int, type: string, target: string, payload_b64: string, sha256: string, claim_token: string, expires_at: string}>
     */
    public function claim(int $terminalId, int $limit = self::CLAIM_LIMIT, int $leaseSeconds = self::LEASE_SECONDS): array
    {
        // Two polls of the same till may deadlock on the queue index; InnoDB undoes
        // one statement and it is simply tried again.
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->claimOnce($terminalId, $limit, max(1, min(self::LEASE_SECONDS, $leaseSeconds)));
            } catch (DeadlockException|LockWaitTimeoutException $e) {
                if ($attempt >= self::CLAIM_ATTEMPTS) {
                    return [];
                }
                usleep(random_int(5_000, 30_000));
            }
        }
    }

    /**
     * @return list<array{id: int, type: string, target: string, payload_b64: string, sha256: string, claim_token: string, expires_at: string}>
     */
    private function claimOnce(int $terminalId, int $limit, int $leaseSeconds): array
    {
        $this->resolveDue($terminalId);

        $now = $this->now();
        $token = bin2hex(random_bytes(16));
        $params = [$token, $this->at($leaseSeconds), $now, $terminalId, $now, $now];
        $sql = "UPDATE print_job SET status = 'claimed', claim_token = ?, lease_until = ?, claimed_at = ?
                WHERE terminal_id = ? AND status = 'pending' AND expires_at > ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
                AND %s ORDER BY priority, id LIMIT %d";
        // Tickets and drawer first (T7); at most one label per poll, so a new
        // ticket never waits behind a batch of labels (AC14).
        $claimed = (int) $this->connection->executeStatement(sprintf($sql, "type <> 'label'", max(1, min(self::CLAIM_LIMIT, $limit))), $params);
        $claimed += (int) $this->connection->executeStatement(sprintf($sql, "type = 'label'", 1), $params);
        if ($claimed === 0) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, type, target, payload, sha256, claim_token, expires_at FROM print_job
             WHERE terminal_id = ? AND claim_token = ? AND status = 'claimed' ORDER BY priority, id",
            [$terminalId, $token]
        );

        $jobs = [];
        foreach ($rows as $row) {
            $payload = \is_resource($row['payload']) ? (string) stream_get_contents($row['payload']) : (string) ($row['payload'] ?? '');
            $jobs[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'target' => (string) $row['target'],
                // A drawer job carries no data (T8): empty, with the hash of nothing.
                'payload_b64' => base64_encode($payload),
                'sha256' => (string) ($row['sha256'] ?? hash('sha256', '')),
                'claim_token' => (string) $row['claim_token'],
                'expires_at' => $this->iso((string) $row['expires_at']),
            ];
        }

        return $jobs;
    }

    /**
     * The agent's result for a job it claimed. Returns the job's status afterwards:
     * `done`, `failed`, `pending` when a failure will be retried (T5), or `expired`
     * when the agent reports `failed` with error_code `job_expired`.
     *
     * @throws PrintJobException 404 not this till's job, 409 claim_stale, 410 job_expired
     */
    public function complete(int $terminalId, int $jobId, string $claimToken, string $status, ?string $errorCode = null, ?string $error = null): string
    {
        if (!\in_array($status, ['done', 'failed'], true)) {
            throw new \InvalidArgumentException('status must be done or failed');
        }

        return $this->connection->transactional(function () use ($terminalId, $jobId, $claimToken, $status, $errorCode, $error): string {
            $job = $this->lockedJob($terminalId, $jobId);
            $tokenMatches = $job['claim_token'] !== null && hash_equals((string) $job['claim_token'], $claimToken);

            // A repeated confirmation answers what the first one did.
            if (\in_array($job['status'], ['done', 'failed', 'pending'], true) && $tokenMatches) {
                return (string) $job['status'];
            }
            // The agent did not print a job it found expired (T6): it reports it as
            // failed with error_code job_expired, which is accepted, not refused.
            $agentSaysExpired = $status === 'failed' && $errorCode === 'job_expired';
            $now = $this->now();
            // A ticket or label confirmed "done" with its claim shortly after expiry did
            // print (T6); the drawer keeps strict expiry.
            $lateDone = $status === 'done' && $tokenMatches && $job['type'] !== 'drawer'
                && $this->at(-self::DONE_GRACE_SECONDS) <= (string) $job['expires_at'];
            if ($job['status'] === 'expired' && $lateDone) {
                $this->markDone($job, $now);

                return 'done';
            }
            if ($job['status'] === 'expired') {
                if ($agentSaysExpired) {
                    return 'expired';
                }
                throw PrintJobException::expired();
            }
            if ($job['status'] !== 'claimed' || !$tokenMatches) {
                throw PrintJobException::claimStale();
            }
            if ($agentSaysExpired || ((string) $job['expires_at'] <= $now && !$lateDone)) {
                $this->expireJob($jobId);
                if ($agentSaysExpired) {
                    return 'expired';
                }
                throw PrintJobException::expired();
            }

            if ($status === 'done') {
                $this->markDone($job, $now);

                return 'done';
            }

            $attempts = (int) $job['attempts'] + 1;
            $delay = self::RETRY_DELAYS[$attempts - 1] ?? null;
            $retryAt = $delay !== null ? $this->at($delay) : null;
            $code = self::errorCode($errorCode);
            $text = self::errorText($error);
            if ($retryAt !== null && $retryAt < (string) $job['expires_at']) {
                // Keeps the claim token so a repeated "failed" answers "pending".
                $this->connection->executeStatement(
                    "UPDATE print_job SET status = 'pending', attempts = ?, next_attempt_at = ?, lease_until = NULL, error_code = ?, error = ? WHERE id = ?",
                    [$attempts, $retryAt, $code, $text, $jobId]
                );

                return 'pending';
            }

            $this->connection->executeStatement(
                "UPDATE print_job SET status = 'failed', attempts = ?, finished_at = ?, lease_until = NULL, next_attempt_at = NULL, error_code = ?, error = ? WHERE id = ?",
                [$attempts, $now, $code, $text, $jobId]
            );
            $this->afterFinished($job, 'failed');

            return 'failed';
        });
    }

    /**
     * More time for a long batch (T4). Returns the new lease end.
     *
     * @throws PrintJobException
     */
    public function extend(int $terminalId, int $jobId, string $claimToken): \DateTimeImmutable
    {
        return $this->connection->transactional(function () use ($terminalId, $jobId, $claimToken): \DateTimeImmutable {
            $job = $this->lockedJob($terminalId, $jobId);
            if ($job['status'] === 'expired') {
                throw PrintJobException::expired();
            }
            if ($job['status'] !== 'claimed' || $job['claim_token'] === null || !hash_equals((string) $job['claim_token'], $claimToken)) {
                throw PrintJobException::claimStale();
            }
            if ((string) $job['expires_at'] <= $this->now()) {
                $this->expireJob($jobId);
                throw PrintJobException::expired();
            }

            $lease = $this->clock->now()->modify(sprintf('+%d seconds', self::LEASE_SECONDS));
            $this->connection->executeStatement('UPDATE print_job SET lease_until = ? WHERE id = ?', [$lease->format('Y-m-d H:i:s'), $jobId]);

            return $lease;
        });
    }

    /**
     * Expires what passed its expiry (T6) and returns unconfirmed leases to the
     * queue (T4), for one till or all of them. Rows are found with a plain read and
     * changed by id, so concurrent polls lock only the rows that really change.
     */
    public function resolveDue(?int $terminalId = null): void
    {
        $now = $this->now();
        $till = $terminalId !== null ? ' AND terminal_id = ?' : '';
        $tillParams = $terminalId !== null ? [$terminalId] : [];

        $expired = $this->ids("SELECT id FROM print_job WHERE status IN ('pending', 'claimed') AND expires_at <= ?" . $till, array_merge([$now], $tillParams));
        if ($expired !== []) {
            $in = implode(',', $expired);
            $this->connection->executeStatement(
                "UPDATE print_job SET status = 'expired', finished_at = ?, lease_until = NULL WHERE id IN ($in) AND status IN ('pending', 'claimed') AND expires_at <= ?",
                [$now, $now]
            );
            $this->connection->executeStatement(
                "UPDATE drawer_audit SET result = 'expired', updated_at = ? WHERE job_id IN ($in) AND result = 'queued'",
                [$now]
            );
        }

        $stale = $this->ids("SELECT id FROM print_job WHERE status = 'claimed' AND lease_until <= ? AND expires_at > ?" . $till, array_merge([$now, $now], $tillParams));
        if ($stale !== []) {
            $this->connection->executeStatement(
                sprintf("UPDATE print_job SET status = 'pending', claim_token = NULL, lease_until = NULL WHERE id IN (%s) AND status = 'claimed' AND lease_until <= ?", implode(',', $stale)),
                [$now]
            );
        }
    }

    /**
     * One job without its data, for the POS (GET /api/print-jobs/{id}).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $jobId): ?array
    {
        $terminal = $this->connection->fetchOne('SELECT terminal_id FROM print_job WHERE id = ?', [$jobId]);
        if ($terminal === false) {
            return null;
        }
        $this->resolveDue((int) $terminal);

        $row = $this->connection->fetchAssociative(
            'SELECT pj.id, pj.terminal_id, t.name AS terminal_name, pj.type, pj.target, pj.source, pj.status, pj.attempts, pj.error_code, pj.error,
                    pj.created_at, pj.claimed_at, pj.finished_at, pj.expires_at
             FROM print_job pj LEFT JOIN terminal t ON t.id = pj.terminal_id WHERE pj.id = ?',
            [$jobId]
        );

        return $row === false ? null : $this->present($row);
    }

    /**
     * The history of the last 30 days (S4), newest first, by id cursor.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: int|null}
     */
    public function history(?int $terminalId, ?string $status, ?int $cursor, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $where = ['pj.created_at >= ?'];
        $params = [$this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')];
        if ($terminalId !== null) {
            $where[] = 'pj.terminal_id = ?';
            $params[] = $terminalId;
        }
        if ($status !== null) {
            $where[] = 'pj.status = ?';
            $params[] = $status;
        }
        if ($cursor !== null) {
            $where[] = 'pj.id < ?';
            $params[] = $cursor;
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT pj.id, pj.terminal_id, t.name AS terminal_name, pj.type, pj.target, pj.source, pj.status, pj.attempts, pj.error_code, pj.error,
                        pj.created_at, pj.claimed_at, pj.finished_at, pj.expires_at
                 FROM print_job pj LEFT JOIN terminal t ON t.id = pj.terminal_id
                 WHERE %s ORDER BY pj.id DESC LIMIT %d',
                implode(' AND ', $where),
                $limit + 1
            ),
            $params
        );

        $more = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $items = array_map(fn (array $row): array => $this->present($row), $rows);

        return ['items' => $items, 'next_cursor' => $more && $items !== [] ? (int) $items[\count($items) - 1]['id'] : null];
    }

    public static function errorText(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }
        $error = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error));
        if ($error === '') {
            return null;
        }

        return mb_substr($error, 0, self::ERROR_MAX_CHARS);
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<int>
     */
    private function ids(string $sql, array $params): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn($sql, $params));
    }

    private static function errorCode(?string $code): ?string
    {
        if ($code === null || preg_match('/\A[a-z0-9_]{1,64}\z/', $code) !== 1) {
            return $code === null ? null : 'unknown';
        }

        return $code;
    }

    /** @return array<string, mixed> */
    private function lockedJob(int $terminalId, int $jobId): array
    {
        // The till is part of the key: another till's job is "not found" (E4).
        $job = $this->connection->fetchAssociative(
            'SELECT id, type, status, claim_token, expires_at, attempts, source, source_ref FROM print_job WHERE id = ? AND terminal_id = ? FOR UPDATE',
            [$jobId, $terminalId]
        );
        if ($job === false) {
            throw PrintJobException::notFound();
        }

        return $job;
    }

    /**
     * Done: delivered (T3). An earlier failed attempt's error no longer applies.
     *
     * @param array<string, mixed> $job
     */
    private function markDone(array $job, string $now): void
    {
        $this->connection->executeStatement(
            "UPDATE print_job SET status = 'done', finished_at = ?, lease_until = NULL, next_attempt_at = NULL, error_code = NULL, error = NULL WHERE id = ?",
            [$now, (int) $job['id']]
        );
        $this->afterFinished($job, 'done');
    }

    private function expireJob(int $jobId): void
    {
        $now = $this->now();
        $this->connection->executeStatement(
            "UPDATE print_job SET status = 'expired', finished_at = ?, lease_until = NULL WHERE id = ?",
            [$now, $jobId]
        );
        $this->connection->executeStatement(
            "UPDATE drawer_audit SET result = 'expired', updated_at = ? WHERE job_id = ? AND result = 'queued'",
            [$now, $jobId]
        );
    }

    /** @param array<string, mixed> $job */
    private function afterFinished(array $job, string $status): void
    {
        if ($job['type'] === 'drawer') {
            $this->connection->executeStatement(
                'UPDATE drawer_audit SET result = ?, updated_at = ? WHERE job_id = ?',
                [$status, $this->now(), (int) $job['id']]
            );

            return;
        }
        if ($status === 'done' && $job['type'] === 'ticket' && \in_array($job['source'], self::SALE_SOURCES, true)
            && is_numeric($job['source_ref']) && (int) $job['source_ref'] !== 0) {
            OrderPrintedMarker::mark($this->connection, (int) $job['source_ref']);
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'terminal_id' => (int) $row['terminal_id'],
            'terminal_name' => $row['terminal_name'] !== null ? (string) $row['terminal_name'] : null,
            'type' => (string) $row['type'],
            'target' => (string) $row['target'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'error_code' => $row['error_code'] !== null ? (string) $row['error_code'] : null,
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'created_at' => $this->iso((string) $row['created_at']),
            'claimed_at' => $row['claimed_at'] !== null ? $this->iso((string) $row['claimed_at']) : null,
            'finished_at' => $row['finished_at'] !== null ? $this->iso((string) $row['finished_at']) : null,
            'expires_at' => $this->iso((string) $row['expires_at']),
        ];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function at(int $seconds): string
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', $seconds))->format('Y-m-d H:i:s');
    }

    private function iso(string $dateTime): string
    {
        return (new \DateTimeImmutable($dateTime, $this->clock->now()->getTimezone()))->format(\DATE_ATOM);
    }
}
