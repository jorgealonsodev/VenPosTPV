<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSync;

/**
 * The every-minute part of the shop copy of the fiscal journal (fiscal-journal D6,
 * S8, S9), run by FiscalJournalReconciler after the flush:
 *
 * 1. every journal line without an outbox row gets one (type `journal`, sequence =
 *    seq, the line verbatim in the payload), in seq order; OutboxSync sends them;
 * 2. the shop is asked once for its head of this journal (and of the journal the
 *    database names, when that is another one, FiscalJournalIdentity), and the
 *    answers are stored in `shop-head.json` of every folder that can be written:
 *    FiscalJournalGuard reads them (`behind_shop`, `journal_missing`);
 * 3. with the head: the lines after the shop's contiguous_seq whose row was lost
 *    (a restore of the database) are queued again; when the shop lost lines it had
 *    acknowledged (its own restore: the row after contiguous_seq was done before the
 *    question), those are queued again too. A gap held by a row in incident (409,
 *    a number the module cannot store) resends nothing. The module dedupes, so a
 *    line sent twice is stored once (200);
 * 4. an answer at all means the module has the endpoint: the rows waiting an hour
 *    because it did not (S9) are due at once.
 *
 * A shop that cannot be reached or a module too old changes nothing stored and is
 * never a block. The summary goes to `state.json` under `shop` for Ajustes.
 */
class FiscalJournalShopSync
{
    public const OK = 'ok';
    public const PENDING = 'pending';
    public const INCIDENTS = 'incidents';
    public const UNAVAILABLE = 'unavailable';
    public const UNREACHABLE = 'unreachable';

    /** Rows queued per run at most (the upload sends 100 a minute). */
    public const ENQUEUE_LIMIT = 500;

    private readonly FiscalJournalIdentity $identity;
    private readonly Outbox $outbox;

    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalLocations $locations,
        private readonly PsJournalClient $client,
        ?FiscalJournalIdentity $identity = null,
        ?Outbox $outbox = null,
    ) {
        $this->identity = $identity ?? new FiscalJournalIdentity($connection);
        $this->outbox = $outbox ?? new Outbox($connection);
    }

    /**
     * @return array<string, mixed>|null the shop summary; null when there is no journal and the database names none
     */
    public function run(\DateTimeImmutable $now, ?LoggerInterface $log = null): ?array
    {
        $journalId = $this->journalInFolders();
        try {
            $named = $this->identity->journalId();
        } catch (\Throwable $e) {
            $log?->warning('Fiscal journal shop copy: the journal id setting cannot be read: {message}', ['message' => $e->getMessage()]);
            $named = null;
        }
        $target = $journalId ?? $named;
        if ($target === null) {
            return null;
        }
        $journalSeq = $journalId !== null ? $this->journalSeq() : null;

        $enqueued = 0;
        if ($journalId !== null) {
            $enqueued += $this->enqueueAfter($journalId, $this->queuedSeq($journalId), null, $log);
        }

        $asked = array_values(array_unique(array_filter([$journalId, $named])));
        $answers = [];
        foreach ($asked as $id) {
            $answers[$id] = $this->client->head($id);
        }
        $answer = $answers[$target];
        $heads = array_filter(array_map(static fn (array $a): ?array => $a['head'], $answers));
        if ($heads !== []) {
            $this->storeHeads($heads, $asked, $now, $journalId === null, $log);
            $this->releaseUnavailable($now);
        }
        if ($journalId !== null && $answer['head'] !== null) {
            $enqueued += $this->requeue($journalId, $answer['head'], (int) $journalSeq, $now, $log);
        }

        return $this->summary($target, $answer, $journalSeq, $enqueued, $now);
    }

    private function journalInFolders(): ?string
    {
        $id = $this->locations->primary()->journalId() ?? $this->locations->secondary()->journalId();

        return $id !== null ? strtolower($id) : null;
    }

    /** The seq of the journal's last valid line, in either folder (0 when empty). */
    private function journalSeq(): int
    {
        $seq = 0;
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            $line = $location->lastValidLine();
            if (\is_array($line)) {
                $seq = max($seq, (int) $line['seq']);
            }
        }

        return $seq;
    }

    private function queuedSeq(string $journalId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COALESCE(MAX(sequence), 0) FROM outbox WHERE type = 'journal' AND request_id LIKE ?",
            [Outbox::journalRequestPrefix($journalId) . '%']
        );
    }

    /**
     * The valid lines after $after (primary first, then what the secondary holds
     * beyond it), at most ENQUEUE_LIMIT.
     *
     * @return list<array<string, mixed>>
     */
    private function linesAfter(int $after): array
    {
        $lines = $this->locations->primary()->linesAfter($after, self::ENQUEUE_LIMIT);
        $last = $lines !== [] ? (int) $lines[\count($lines) - 1]['seq'] : $after;
        $primaryLast = $this->locations->primary()->lastValidLine();
        $beyond = max($last, \is_array($primaryLast) ? (int) $primaryLast['seq'] : 0, $after);
        foreach ($this->locations->secondary()->linesAfter($beyond, self::ENQUEUE_LIMIT - \count($lines)) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Queues the lines after $after, all of them or only the seqs in $only.
     *
     * @param array<int, true>|null $only
     */
    private function enqueueAfter(string $journalId, int $after, ?array $only, ?LoggerInterface $log): int
    {
        try {
            $lines = array_values(array_filter(
                $this->linesAfter($after),
                static fn (array $line): bool => $only === null || isset($only[(int) $line['seq']])
            ));
            if ($lines === []) {
                return 0;
            }
            $this->connection->transactional(function () use ($journalId, $lines): void {
                foreach ($lines as $line) {
                    $this->outbox->enqueueJournal($journalId, $line);
                }
            });

            return \count($lines);
        } catch (\Throwable $e) {
            $log?->warning('Fiscal journal shop copy: lines not queued: {message}', ['message' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * D6: the lines after the shop's contiguous_seq whose row was lost, or that the
     * shop lost after acknowledging them.
     *
     * @param array{contiguous_seq: int} $head
     */
    private function requeue(string $journalId, array $head, int $journalSeq, \DateTimeImmutable $askedAt, ?LoggerInterface $log): int
    {
        $from = $head['contiguous_seq'];
        $to = min($journalSeq, $from + self::ENQUEUE_LIMIT);
        if ($to <= $from) {
            return 0;
        }
        /** @var array<int, array{status: string, done_at: ?string}> $latest the latest row of each seq */
        $latest = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT sequence, status, done_at FROM outbox WHERE type = 'journal' AND request_id LIKE ? AND sequence > ? AND sequence <= ? ORDER BY id",
            [Outbox::journalRequestPrefix($journalId) . '%', $from, $to]
        ) as $row) {
            $latest[(int) $row['sequence']] = ['status' => (string) $row['status'], 'done_at' => $row['done_at'] !== null ? (string) $row['done_at'] : null];
        }
        $asked = self::local($askedAt);
        $next = $latest[$from + 1] ?? null;
        $shopLostThem = $next !== null && $next['status'] === Outbox::STATUS_DONE && $next['done_at'] !== null && $next['done_at'] < $asked;

        $only = [];
        for ($seq = $from + 1; $seq <= $to; ++$seq) {
            $row = $latest[$seq] ?? null;
            if ($row === null || ($shopLostThem && $row['status'] === Outbox::STATUS_DONE)) {
                $only[$seq] = true;
            }
        }
        if ($only === []) {
            return 0;
        }
        $log?->info('Fiscal journal shop copy: {count} lines queued again (the shop holds up to {seq})', ['count' => \count($only), 'seq' => $from]);

        return $this->enqueueAfter($journalId, $from, $only, $log);
    }

    /**
     * @param array<string, array<string, mixed>> $heads the answers by journal id
     * @param list<string> $asked the journals asked for: an older answer for one of them is kept, any other dropped
     */
    private function storeHeads(array $heads, array $asked, \DateTimeImmutable $now, bool $prepare, ?LoggerInterface $log): void
    {
        $fetchedAt = $now->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            if ($prepare) {
                $location->prepare();
            }
            if (!$location->isWritable()) {
                continue;
            }
            $stored = $location->readShopHead();
            $merged = [];
            foreach (\is_array($stored['heads'] ?? null) ? $stored['heads'] : [] as $id => $head) {
                if (\in_array(strtolower((string) $id), $asked, true)) {
                    $merged[strtolower((string) $id)] = $head;
                }
            }
            foreach ($heads as $id => $head) {
                $merged[$id] = ['journal_id' => $id, 'fetched_at' => $fetchedAt, 'last_seq' => $head['last_seq'], 'contiguous_seq' => $head['contiguous_seq'], 'series' => $head['series']];
            }
            try {
                $location->writeShopHead(['v' => 1, 'heads' => $merged]);
            } catch (\Throwable $e) {
                $log?->warning('Fiscal journal shop head not written in {directory}: {message}', ['directory' => $location->directory(), 'message' => $e->getMessage()]);
            }
        }
    }

    /** The module answered: the rows waiting for it (S9) are due now. */
    private function releaseUnavailable(\DateTimeImmutable $now): void
    {
        $local = self::local($now);
        $this->connection->executeStatement(
            "UPDATE outbox SET next_attempt_at = ?, updated_at = ? WHERE type = 'journal' AND status = 'pending' AND last_error = ? AND next_attempt_at > ?",
            [$local, $local, OutboxSync::JOURNAL_MODULE_UNAVAILABLE, $local]
        );
    }

    /**
     * @param array{status: string, head: ?array, error: ?string} $answer
     *
     * @return array<string, mixed>
     */
    private function summary(string $journalId, array $answer, ?int $journalSeq, int $enqueued, \DateTimeImmutable $now): array
    {
        $prefix = Outbox::journalRequestPrefix($journalId) . '%';
        $counts = $this->connection->fetchAssociative(
            "SELECT COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN status = 'incident' THEN 1 ELSE 0 END), 0) AS incidents
             FROM outbox WHERE type = 'journal' AND request_id LIKE ?",
            [$prefix]
        ) ?: [];
        $pending = (int) ($counts['pending'] ?? 0);
        $incidents = (int) ($counts['incidents'] ?? 0);
        $head = $answer['head'];
        $last = null;
        foreach ($head['series'] ?? [] as $entry) {
            if ($last === null || $entry['seq'] > $last['seq']) {
                $last = ['number' => $entry['number'], 'seq' => $entry['seq']];
            }
        }

        return [
            'status' => match (true) {
                $answer['status'] === PsJournalClient::UNAVAILABLE => self::UNAVAILABLE,
                $head === null => self::UNREACHABLE,
                $incidents > 0 => self::INCIDENTS,
                $pending > 0 => self::PENDING,
                default => self::OK,
            },
            'checked_at' => $now->format(\DATE_ATOM),
            'journal_id' => $journalId,
            'journal_seq' => $journalSeq,
            'last_seq' => $head['last_seq'] ?? null,
            'contiguous_seq' => $head['contiguous_seq'] ?? null,
            'last_document' => $last,
            'pending' => $pending,
            'incidents' => $incidents,
            'enqueued' => $enqueued,
            'error' => $head === null ? $answer['error'] : null,
        ];
    }

    /** A time as the outbox stores it (the PHP zone, as OutboxSync writes it). */
    private static function local(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
