<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use VenPosTPV\Service\Fiscal\FiscalRecorder;

/**
 * Appends the committed fiscal documents to the fiscal journal (fiscal-journal D3.3):
 * under `journal.lock`, the torn tail is repaired (D4), `head.json` is checked
 * against the files, the lines left in the secondary location are merged back
 * (D3.4), and every fiscal_record after the journal's last document is written, in
 * id order (the chain lock is held from insert to commit, so ids follow the chain),
 * as one D2 line each. Only committed rows are read, so the journal is never ahead
 * of the database.
 *
 * Any failure of the primary (its lock cannot be opened, a file cannot be opened,
 * written or synced) sends the remaining lines to the secondary with the same
 * numbering; when neither can be written, nothing is written and the documents wait
 * in the database for the next flush (S2). Nothing here throws for those: the
 * result says what happened.
 *
 * Damage is never repaired from the database (T1b): a complete last line that fails
 * its checks, files holding fewer lines than `head.json` records, or a primary that
 * lost its `journal.id` give a DAMAGED or JOURNAL_MISSING result and nothing is
 * written. Only createJournal() makes a journal.
 *
 * Conditions that last (no folder writable, damage, a record that cannot be read)
 * are logged at warning level the first time in a process and at debug level after
 * that; the every-minute job decides what is reported (IncidentLogBuffer).
 */
class FiscalJournalWriter
{
    private const BATCH = 200;

    private readonly FiscalDocumentSnapshot $snapshot;

    /** @var array<string, true> conditions this process already logged at warning level */
    private array $warned = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalLocations $locations,
        private readonly FiscalJournalIntents $intents,
        private readonly ?LoggerInterface $logger = null,
        ?FiscalDocumentSnapshot $snapshot = null,
    ) {
        $this->snapshot = $snapshot ?? new FiscalDocumentSnapshot($connection, $logger);
    }

    /**
     * Writes the journal identity (`journal.id`) to every location that can be
     * written. The provisioning step (D11) and tpv:fiscal:journal:reset decide when a
     * journal is created (FiscalJournalStarter).
     *
     * @param int|null $backfillUntil the documents up to this fiscal_record id were issued
     *                                before the journal: their lines are marked `backfill`
     *                                whichever flush writes them
     *
     * @throws \RuntimeException when no location can be written
     */
    public function createJournal(?string $journalId = null, ?\DateTimeImmutable $at = null, ?int $backfillUntil = null): string
    {
        $journalId ??= self::uuid();
        $at ??= new \DateTimeImmutable('now', new \DateTimeZone(FiscalRecorder::TIME_ZONE));
        $written = false;
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            if ($location->prepare()) {
                $location->writeJournalId($journalId, $at, $backfillUntil);
                $written = true;
            }
        }
        if (!$written) {
            throw new \RuntimeException('Neither fiscal journal folder can be written');
        }

        return $journalId;
    }

    /**
     * The flush after the response of a request that issued documents (D3.2): this
     * process's intents are resolved against the database, then the journal is
     * brought up to date (at most $limit lines, waiting at most $lockWaitSeconds for
     * the lock, when given).
     */
    public function flushAfterIssue(?int $limit = null, ?float $lockWaitSeconds = null): FiscalJournalFlushResult
    {
        $this->intents->resolveOwn($this->connection);
        $result = $this->flush(false, $limit, $lockWaitSeconds);
        if ($result->status === FiscalJournalFlushResult::OK) {
            $this->intents->reset();
        }

        return $result;
    }

    /**
     * @param bool $backfill marks the lines as documents issued before the journal existed (D11)
     * @param int|null $limit at most this many lines (null: every missing document)
     * @param float|null $lockWaitSeconds how long to wait for journal.lock (null: as long as it takes)
     */
    public function flush(bool $backfill = false, ?int $limit = null, ?float $lockWaitSeconds = null): FiscalJournalFlushResult
    {
        $primary = $this->locations->primary();
        $secondary = $this->locations->secondary();
        $primaryId = $primary->journalId();
        $journalId = $primaryId ?? $secondary->journalId();
        if ($journalId === null) {
            return new FiscalJournalFlushResult(FiscalJournalFlushResult::NO_JOURNAL);
        }
        if ($primaryId === null) {
            $this->warn('journal_missing', 'Fiscal journal: {primary} has lost its journal.id while {secondary} still has one; it is not recreated, nothing is written', ['primary' => $primary->directory(), 'secondary' => $secondary->directory()]);

            return new FiscalJournalFlushResult(FiscalJournalFlushResult::JOURNAL_MISSING, 0, $primary->directory());
        }

        $primaryWritable = $primary->prepare();
        $secondaryWritable = $secondary->prepare();
        if (!$primaryWritable && !$secondaryWritable) {
            return $this->unwritable(0, 'neither folder can be written');
        }

        $deadline = $lockWaitSeconds !== null ? microtime(true) + max(0.0, $lockWaitSeconds) : null;
        $locks = [];
        try {
            $primaryUsable = false;
            if ($primaryWritable) {
                try {
                    $lock = $primary->lock($deadline);
                    if ($lock === null) {
                        return new FiscalJournalFlushResult(FiscalJournalFlushResult::BUSY);
                    }
                    $locks[] = $lock;
                    $primaryUsable = true;
                } catch (JournalWriteException $e) {
                    $this->primaryFailed($e);
                }
            }
            // Taken whenever possible: every writer of either folder holds it, so a till that
            // cannot lock the primary never writes the secondary while another merges it.
            $secondaryLocked = false;
            if (is_dir($secondary->directory())) {
                try {
                    $lock = $secondary->lock($deadline);
                    if ($lock === null) {
                        return new FiscalJournalFlushResult(FiscalJournalFlushResult::BUSY);
                    }
                    $locks[] = $lock;
                    $secondaryLocked = true;
                } catch (JournalWriteException) {
                }
            }

            $written = 0;
            if ($primaryUsable) {
                try {
                    return $this->intoPrimary($primary, $secondaryLocked ? $secondary : null, $backfill, $limit, $written);
                } catch (JournalWriteException $e) {
                    $this->primaryFailed($e);
                }
            }
            if (!$secondaryLocked || !$secondaryWritable) {
                return $this->unwritable($written, 'the primary failed and the secondary cannot be written');
            }
            try {
                return $this->intoSecondary($secondary, $primary, $journalId, $backfill, $limit, $written);
            } catch (JournalWriteException $e) {
                return $this->unwritable($written, $e->getMessage());
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                JournalLocation::unlock($lock);
            }
        }
    }

    /**
     * @throws JournalWriteException when the primary fails at any step (the caller goes on in the secondary)
     */
    private function intoPrimary(JournalLocation $primary, ?JournalLocation $secondary, bool $backfill, ?int $limit, int &$written): FiscalJournalFlushResult
    {
        $tail = $primary->repairTail();
        if ($tail === JournalLocation::TAIL_BAD_LAST || $tail === JournalLocation::TAIL_BROKEN_LAST) {
            return $this->damaged($primary, $tail);
        }
        $middleDamaged = $tail === JournalLocation::TAIL_DAMAGED;
        if ($middleDamaged) {
            $this->warn('damaged', 'Fiscal journal in {directory} has a damaged line before the last; nothing is rewritten, appends go on', ['directory' => $primary->directory()]);
        }
        $last = $this->checkedLast($primary);
        if (\is_string($last)) {
            return $this->damaged($primary, $last);
        }

        if ($secondary !== null) {
            try {
                $secondary->repairTail();
            } catch (JournalWriteException) {
                // Merged up to its torn line; the rest waits.
            }
            // The merge counts against the limit of a bounded flush (the rest waits for the next one).
            $merged = JournalMerge::secondaryIntoPrimary($secondary, $primary, $limit !== null ? max(0, $limit - $written) : null);
            $written += $merged;
            if ($merged > 0) {
                $last = $primary->lastLine();
                if ($last === false) {
                    return $this->damaged($primary, JournalVerifier::BAD_LINE);
                }
            }
        }

        $head = $last !== null ? self::headOf($last) : null;
        if (!$this->inDatabase($head)) {
            return $this->headMissing($primary, $head);
        }

        return $this->append($primary, $head, $backfill, $limit, $written, $middleDamaged);
    }

    /**
     * The secondary takes the lines while the primary fails, continuing its numbering.
     *
     * @throws JournalWriteException when the secondary fails too
     */
    private function intoSecondary(JournalLocation $secondary, JournalLocation $primary, string $journalId, bool $backfill, ?int $limit, int &$written): FiscalJournalFlushResult
    {
        if ($secondary->journalId() === null) {
            // The secondary only buffers lines for the primary: it may take the identity.
            $secondary->writeJournalId($journalId, new \DateTimeImmutable('now', new \DateTimeZone(FiscalRecorder::TIME_ZONE)), $primary->backfillUntil());
        }
        $tail = $secondary->repairTail();
        if ($tail === JournalLocation::TAIL_BAD_LAST || $tail === JournalLocation::TAIL_BROKEN_LAST || $tail === JournalLocation::TAIL_DAMAGED) {
            return $this->damaged($secondary, $tail === JournalLocation::TAIL_DAMAGED ? JournalVerifier::BAD_LINE : $tail);
        }
        $last = $this->checkedLast($secondary);
        if (\is_string($last)) {
            return $this->damaged($secondary, $last);
        }

        $primaryHead = $this->primaryHead($primary);
        $head = $last !== null ? self::headOf($last) : null;
        if ($primaryHead !== null && $head !== null && $head['seq'] <= $primaryHead['seq']) {
            // Left over from a merge that could not chain: those documents are in the
            // database, and new lines must continue the primary.
            $secondary->clearLines();
            $head = null;
        }
        if ($head === null || ($primaryHead !== null && $primaryHead['seq'] > $head['seq'])) {
            $head = $primaryHead;
        }
        if (!$this->inDatabase($head)) {
            return $this->headMissing($secondary, $head);
        }

        return $this->append($secondary, $head, $backfill, $limit, $written, false);
    }

    /**
     * The last line of a folder checked against its head.json (D4 as amended): the
     * line (null when the folder holds none) or the reason it is damaged. A missing
     * head.json is rebuilt from the files, one left behind by a killed flush is
     * brought forward.
     *
     * @return array<string, mixed>|string|null
     *
     * @throws JournalWriteException
     */
    private function checkedLast(JournalLocation $location): array|string|null
    {
        $last = $location->lastLine();
        if ($last === false) {
            return JournalVerifier::BAD_LINE;
        }
        $finding = $location->headFinding($last);
        if ($finding !== null) {
            return $finding;
        }
        $head = $location->readHead();
        if ($last !== null && ($head === null || $head['seq'] !== $last['seq'] || $head['line_hash'] !== $last['line_hash'])) {
            if ($head === null) {
                $this->warn('head_rebuilt', 'Fiscal journal: head.json of {directory} was missing or unreadable; rebuilt from the journal files', ['directory' => $location->directory()]);
            }
            $location->writeHead($last);
        }

        return $last;
    }

    /**
     * Appends every document after $head, in id order, up to $limit lines in all.
     *
     * @param array{seq: int, line_hash: string, fiscal_record_id: int, hash: string}|null $head
     *
     * @throws JournalWriteException
     */
    private function append(JournalLocation $target, ?array $head, bool $backfill, ?int $limit, int &$written, bool $damaged): FiscalJournalFlushResult
    {
        $afterId = $head['fiscal_record_id'] ?? 0;
        $backfillUntil = $this->backfillUntil();
        $last = null;
        $zone = new \DateTimeZone(FiscalRecorder::TIME_ZONE);
        try {
            while ($limit === null || $written < $limit) {
                $batch = $limit === null ? self::BATCH : min(self::BATCH, $limit - $written);
                $records = $this->connection->fetchAllAssociative(
                    sprintf('SELECT * FROM fiscal_record WHERE id > ? ORDER BY id LIMIT %d', $batch),
                    [$afterId]
                );
                foreach ($records as $record) {
                    try {
                        $line = $this->line($record, $head, $backfill || ($backfillUntil !== null && (int) $record['id'] <= $backfillUntil), $zone);
                    } catch (\Throwable $e) {
                        $reason = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
                        $this->warn('blocked', 'Fiscal journal: document {number} cannot be journaled ({reason}); the flush stops at it', ['number' => (string) $record['number'], 'reason' => $reason]);

                        return new FiscalJournalFlushResult(FiscalJournalFlushResult::BLOCKED, $written, $target->directory(), $damaged, $reason, (string) $record['number']);
                    }
                    $target->append($line);
                    $head = self::headOf($line);
                    $last = $line;
                    $afterId = (int) $record['id'];
                    ++$written;
                    $this->intents->journaled((string) $record['hash']);
                }
                if (\count($records) < $batch) {
                    break;
                }
            }
        } finally {
            if ($last !== null) {
                // A failure here leaves head.json behind the files, which the next flush brings forward.
                $target->writeHead($last);
            }
        }

        return new FiscalJournalFlushResult(FiscalJournalFlushResult::OK, $written, $target->directory(), $damaged);
    }

    /**
     * One D2 line. Throws when the record cannot be turned into a line (the flush is
     * then blocked at it).
     *
     * @param array<string, mixed> $record
     * @param array{seq: int, line_hash: string}|null $head
     *
     * @return array<string, mixed>
     */
    private function line(array $record, ?array $head, bool $backfill, \DateTimeZone $zone): array
    {
        $document = $this->snapshot->document($record, $this->intents->capturedFor((string) $record['hash']));
        $line = [
            'v' => 1,
            'seq' => ($head['seq'] ?? 0) + 1,
            'journaled_at' => (new \DateTimeImmutable('now', $zone))->format(\DATE_ATOM),
            'kind' => $document['kind'],
            'series' => $document['series'],
            'counter' => $document['counter'],
            'fiscal_record' => $record,
            'rows' => $document['rows'],
            // Rows read when the line was written, not as issued: replay must not trust them.
            'as_of_flush' => $document['as_of_flush'],
            'backfill' => $backfill,
            'prev' => $head['line_hash'] ?? '',
        ];
        $line['line_hash'] = CanonicalJson::lineHash($line);
        JournalLocation::encode($line);

        return $line;
    }

    /** The last document issued before the journal existed (journal.id of the primary, else the secondary). */
    private function backfillUntil(): ?int
    {
        return $this->locations->primary()->backfillUntil() ?? $this->locations->secondary()->backfillUntil();
    }

    /**
     * Where the primary ends, even while its files cannot be read: the later of its
     * last valid line and its head.json.
     *
     * @return array{seq: int, line_hash: string, fiscal_record_id: int, hash: string}|null
     */
    private function primaryHead(JournalLocation $primary): ?array
    {
        $line = $primary->lastValidLine();
        $head = \is_array($line) ? self::headOf($line) : null;
        $file = $primary->readHead();
        if ($file !== null && \is_int($file['fiscal_record_id'] ?? null) && ($head === null || $file['seq'] > $head['seq'])) {
            $head = ['seq' => $file['seq'], 'line_hash' => $file['line_hash'], 'fiscal_record_id' => $file['fiscal_record_id'], 'hash' => (string) ($file['hash'] ?? '')];
        }

        return $head;
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array{seq: int, line_hash: string, fiscal_record_id: int, hash: string}
     */
    private static function headOf(array $line): array
    {
        $record = \is_array($line['fiscal_record'] ?? null) ? $line['fiscal_record'] : [];

        return [
            'seq' => (int) $line['seq'],
            'line_hash' => (string) $line['line_hash'],
            'fiscal_record_id' => (int) ($record['id'] ?? 0),
            'hash' => (string) ($record['hash'] ?? ''),
        ];
    }

    /** @param array{fiscal_record_id: int, hash: string}|null $head */
    private function inDatabase(?array $head): bool
    {
        return $head === null
            || $this->connection->fetchOne('SELECT 1 FROM fiscal_record WHERE id = ? AND hash = ?', [$head['fiscal_record_id'], $head['hash']]) !== false;
    }

    /** @param array{seq: int}|null $head */
    private function headMissing(JournalLocation $location, ?array $head): FiscalJournalFlushResult
    {
        $this->warn('head_missing', 'Fiscal journal: its last document (seq {seq}) is not in the database; nothing is written', ['seq' => $head['seq'] ?? null]);

        return new FiscalJournalFlushResult(FiscalJournalFlushResult::HEAD_MISSING, 0, $location->directory());
    }

    private function damaged(JournalLocation $location, string $reason): FiscalJournalFlushResult
    {
        $this->warn('damaged', 'Fiscal journal in {directory} is damaged ({reason}); nothing is rewritten or appended', ['directory' => $location->directory(), 'reason' => $reason]);

        return new FiscalJournalFlushResult(FiscalJournalFlushResult::DAMAGED, 0, $location->directory(), true, $reason);
    }

    private function unwritable(int $written, string $cause): FiscalJournalFlushResult
    {
        $this->warn('unwritable', 'Fiscal journal: no folder can be written ({cause}); the documents wait in the database', [
            'cause' => $cause,
            'primary' => $this->locations->primary()->directory(),
            'secondary' => $this->locations->secondary()->directory(),
        ]);

        return new FiscalJournalFlushResult(FiscalJournalFlushResult::UNWRITABLE, $written);
    }

    private function primaryFailed(JournalWriteException $e): void
    {
        $this->warn('primary', 'Fiscal journal: the primary folder failed ({message}); the lines go to the secondary', ['message' => $e->getMessage()]);
    }

    /** @param array<string, mixed> $context */
    private function warn(string $condition, string $message, array $context): void
    {
        $level = isset($this->warned[$condition]) ? LogLevel::DEBUG : LogLevel::WARNING;
        $this->warned[$condition] = true;
        $this->logger?->log($level, $message, $context);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
