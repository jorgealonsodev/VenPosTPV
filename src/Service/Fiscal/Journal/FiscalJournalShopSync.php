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
 * 1. the shop is asked once for every journal it holds (PsJournalClient::heads,
 *    whatever journal id this database knows, or none), and the answer replaces
 *    `shop-head.json` of every folder that can be written: FiscalJournalGuard reads
 *    it (`behind_shop`, `journal_missing`). A module that cannot answer for every
 *    journal is asked, as before, for the journal in the folders and the one the
 *    database names;
 * 2. every journal line after the last one queued gets an outbox row (type
 *    `journal`, sequence = seq, the line verbatim in the payload), in seq order;
 *    OutboxSync sends them. The lines the shop holds without a gap (its
 *    contiguous_seq, from this answer or a stored one at most HEAD_FRESH_SECONDS
 *    old) are never queued: after a restore of the database they are not sent again;
 * 3. with the head: the lines after the shop's contiguous_seq that have no row (a
 *    restore of the database) and the lines the shop lost after acknowledging them
 *    (its own restore: done before the question but beyond the shop's last_seq, or
 *    every done one when the line right after contiguous_seq is among them) are
 *    queued again, the oldest first. A gap held by a row in incident (409, a number
 *    the module cannot store) is never queued again by itself: it stays visible and
 *    the lines after it go on. The module dedupes, so a line sent twice is stored
 *    once (200);
 * 4. an answer at all means the module has the endpoint: the rows waiting an hour
 *    because it did not (S9) are due at once.
 *
 * Each run queues at most ENQUEUE_LIMIT lines in each of 2 and 3; what is left is
 * queued by the next runs, since a line queued again is no longer a candidate.
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

    /** A stored shop head this recent stands in for one this run could not get. */
    public const HEAD_FRESH_SECONDS = 600;

    private readonly FiscalJournalIdentity $identity;
    private readonly Outbox $outbox;

    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalLocations $locations,
        private readonly PsJournalClient $client,
        ?FiscalJournalIdentity $identity = null,
        ?Outbox $outbox = null,
        private readonly int $enqueueLimit = self::ENQUEUE_LIMIT,
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
        $named = $this->namedJournal($log);
        $target = $journalId ?? $named;

        // Every journal the shop holds, whatever id this database knows (or none): the guard's evidence.
        $all = $this->askHeads();
        if ($all['status'] === PsJournalClient::OK) {
            // Without a journal here, folders are only made when the shop holds something to compare.
            $this->remember($all, $now, $journalId === null && $all['journals'] !== [], $log);
        }
        if ($target === null) {
            return null;
        }
        $journalSeq = $journalId !== null ? $this->journalSeq() : null;

        $asked = array_values(array_unique(array_filter([$journalId, $named])));
        if ($all['status'] === PsJournalClient::OK) {
            $answers = [];
            foreach ($asked as $id) {
                $answers[$id] = ['status' => PsJournalClient::OK, 'head' => $all['journals'][$id] ?? ['journal_id' => $id, 'last_seq' => 0, 'contiguous_seq' => 0, 'series' => []], 'error' => null];
            }
        } else {
            // A module that cannot answer for every journal: the single-journal head, as before.
            $answers = $this->ask($asked);
            $heads = array_filter(array_map(static fn (array $a): ?array => $a['head'], $answers));
            if ($heads !== []) {
                $this->storeHeads($heads, $asked, $now, $journalId === null, $log);
            }
        }
        $answer = $answers[$target];
        if ($answer['head'] !== null) {
            $this->releaseUnavailable($now);
        }
        $enqueued = 0;
        if ($journalId !== null) {
            $held = $answer['head']['contiguous_seq'] ?? $this->freshStoredContiguousSeq($journalId, $now);
            $enqueued += $this->enqueueAfter($journalId, max($this->queuedSeq($journalId), (int) $held), null, $log);
            if ($answer['head'] !== null) {
                $enqueued += $this->requeue($journalId, $answer['head'], (int) $journalSeq, $now, $log);
            }
        }

        return $this->summary($target, $answer, $journalSeq, $enqueued, $now);
    }

    /**
     * Asks the shop now, storing nothing, for every journal it holds
     * (PsJournalClient::heads): tpv:fiscal:journal:reset and the provisioning step
     * decide with it before they change anything. A shop that cannot answer for
     * every journal (down, an older module, an error) is not OK.
     *
     * @return array{status: string, journals: ?array<string, array<string, mixed>>, series: ?array<string, array<string, mixed>>, error: ?string}
     */
    public function askHeads(): array
    {
        try {
            return $this->client->heads();
        } catch (\Throwable $e) {
            return ['status' => PsJournalClient::ERROR, 'journals' => null, 'series' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Stores an answer for every journal in `shop-head.json` of every folder that can
     * be written, replacing what was stored (the shop is the authority on what it
     * holds), marked `all` so FiscalJournalGuard knows the shop answered for every
     * journal. With $prepare the folders are created first (no journal here yet).
     *
     * @param array{journals: ?array<string, array<string, mixed>>} $answer an OK answer of askHeads()
     */
    public function remember(array $answer, \DateTimeImmutable $now, bool $prepare = false, ?LoggerInterface $log = null): void
    {
        $fetchedAt = $now->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
        $heads = [];
        foreach ($answer['journals'] ?? [] as $id => $head) {
            $heads[strtolower((string) $id)] = ['journal_id' => strtolower((string) $id), 'fetched_at' => $fetchedAt, 'last_seq' => $head['last_seq'], 'contiguous_seq' => $head['contiguous_seq'], 'series' => $head['series']];
        }
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            if ($prepare) {
                $location->prepare();
            }
            if (!$location->isWritable()) {
                continue;
            }
            try {
                $location->writeShopHead(['v' => 1, 'all' => ['fetched_at' => $fetchedAt], 'heads' => (object) $heads]);
            } catch (\Throwable $e) {
                $log?->warning('Fiscal journal shop head not written in {directory}: {message}', ['directory' => $location->directory(), 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param list<string> $journalIds
     *
     * @return array<string, array{status: string, head: ?array, error: ?string}>
     */
    private function ask(array $journalIds): array
    {
        $answers = [];
        foreach ($journalIds as $id) {
            $answers[$id] = $this->client->head($id);
        }

        return $answers;
    }

    private function namedJournal(?LoggerInterface $log): ?string
    {
        try {
            return $this->identity->journalId();
        } catch (\Throwable $e) {
            $log?->warning('Fiscal journal shop copy: the journal id setting cannot be read: {message}', ['message' => $e->getMessage()]);

            return null;
        }
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

    /** The shop's contiguous_seq of this journal in a stored head at most HEAD_FRESH_SECONDS old. */
    private function freshStoredContiguousSeq(string $journalId, \DateTimeImmutable $now): ?int
    {
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            try {
                $head = $location->readShopHead()['heads'][$journalId] ?? null;
            } catch (\Throwable) {
                continue;
            }
            if (!\is_array($head) || !\is_int($head['contiguous_seq'] ?? null) || !\is_string($head['fetched_at'] ?? null)) {
                continue;
            }
            try {
                $age = $now->getTimestamp() - (new \DateTimeImmutable($head['fetched_at']))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            if ($age >= -60 && $age <= self::HEAD_FRESH_SECONDS) {
                return $head['contiguous_seq'];
            }
        }

        return null;
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
        $lines = $this->locations->primary()->linesAfter($after, $this->enqueueLimit);
        $last = $lines !== [] ? (int) $lines[\count($lines) - 1]['seq'] : $after;
        $primaryLast = $this->locations->primary()->lastValidLine();
        $beyond = max($last, \is_array($primaryLast) ? (int) $primaryLast['seq'] : 0, $after);
        foreach ($this->locations->secondary()->linesAfter($beyond, $this->enqueueLimit - \count($lines)) as $line) {
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
     * D6: the lines after the shop's contiguous_seq that have no row (lost in a
     * restore of the database) or that the shop lost after acknowledging them, the
     * oldest first and at most enqueueLimit; the next runs queue the rest. A line
     * whose latest row is pending, processing, in incident or discarded is never a
     * candidate, so an incident gap is never queued again by itself and a line
     * queued again is not queued a second time.
     *
     * @param array{last_seq: int, contiguous_seq: int} $head
     */
    private function requeue(string $journalId, array $head, int $journalSeq, \DateTimeImmutable $askedAt, ?LoggerInterface $log): int
    {
        $from = $head['contiguous_seq'];
        if ($journalSeq <= $from) {
            return 0;
        }
        $prefix = Outbox::journalRequestPrefix($journalId) . '%';
        $asked = self::local($askedAt);
        $next = $this->connection->fetchAssociative(
            "SELECT status, done_at FROM outbox WHERE type = 'journal' AND request_id LIKE ? AND sequence = ? ORDER BY id DESC LIMIT 1",
            [$prefix, $from + 1]
        );
        // The shop lacks the line after contiguous_seq although it was sent: it lost lines.
        $shopLostThem = $next !== false && $next['status'] === Outbox::STATUS_DONE && $next['done_at'] !== null && (string) $next['done_at'] < $asked;

        $only = [];
        foreach ($this->missingSeqs($prefix, $from, $journalSeq) as $seq) {
            $only[$seq] = true;
        }
        // Sent before the question, yet beyond what the shop holds (all of them once it lost the next one).
        foreach ($this->connection->fetchFirstColumn(
            sprintf("SELECT o.sequence FROM outbox o
             WHERE o.type = 'journal' AND o.request_id LIKE ? AND o.sequence > ? AND o.sequence <= ?
               AND o.status = 'done' AND o.done_at < ?
               AND NOT EXISTS (SELECT 1 FROM outbox n WHERE n.type = 'journal' AND n.request_id LIKE ? AND n.sequence = o.sequence AND n.id > o.id)
             ORDER BY o.sequence LIMIT %d", $this->enqueueLimit),
            [$prefix, $shopLostThem ? $from : max($from, $head['last_seq']), $journalSeq, $asked, $prefix]
        ) as $seq) {
            $only[(int) $seq] = true;
        }
        if ($only === []) {
            return 0;
        }
        ksort($only);
        $only = \array_slice($only, 0, $this->enqueueLimit, true);
        $log?->info('Fiscal journal shop copy: {count} lines queued again (the shop holds up to {seq})', ['count' => \count($only), 'seq' => $from]);

        return $this->enqueueAfter($journalId, (int) array_key_first($only) - 1, $only, $log);
    }

    /**
     * The seqs after $from, up to the last one queued and at most enqueueLimit, that
     * have no row at all. Counted first: normally there is none, and nothing more is read.
     *
     * @return list<int>
     */
    private function missingSeqs(string $prefix, int $from, int $journalSeq): array
    {
        $upTo = min($journalSeq, (int) $this->connection->fetchOne(
            "SELECT COALESCE(MAX(sequence), 0) FROM outbox WHERE type = 'journal' AND request_id LIKE ?",
            [$prefix]
        ));
        if ($upTo <= $from) {
            return [];
        }
        $present = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT sequence) FROM outbox WHERE type = 'journal' AND request_id LIKE ? AND sequence > ? AND sequence <= ?",
            [$prefix, $from, $upTo]
        );
        if ($present >= $upTo - $from) {
            return [];
        }
        $have = array_flip(array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT DISTINCT sequence FROM outbox WHERE type = 'journal' AND request_id LIKE ? AND sequence > ? AND sequence <= ?",
            [$prefix, $from, $upTo]
        )));
        $missing = [];
        for ($seq = $from + 1; $seq <= $upTo && \count($missing) < $this->enqueueLimit; ++$seq) {
            if (!isset($have[$seq])) {
                $missing[] = $seq;
            }
        }

        return $missing;
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
