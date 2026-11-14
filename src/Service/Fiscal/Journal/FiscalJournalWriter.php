<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Fiscal\FiscalRecorder;

/**
 * Appends the committed fiscal documents to the fiscal journal (fiscal-journal D3.3):
 * under `journal.lock`, the torn tail is repaired (D4), the lines left in the
 * secondary location are merged back (D3.4), and every fiscal_record after the
 * journal's last document is written, in id order (the chain lock is held from
 * insert to commit, so ids follow the chain), as one D2 line each. Only committed
 * rows are read, so the journal is never ahead of the database.
 *
 * When the primary folder cannot be written the lines go to the secondary with the
 * same numbering; when neither can, nothing is written and the documents wait in
 * the database for the next flush (S2).
 */
class FiscalJournalWriter
{
    private const BATCH = 200;

    private readonly FiscalDocumentSnapshot $snapshot;

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
     * written. The provisioning step (D11) decides when a journal is created.
     *
     * @throws \RuntimeException when no location can be written
     */
    public function createJournal(?string $journalId = null, ?\DateTimeImmutable $at = null): string
    {
        $journalId ??= self::uuid();
        $at ??= new \DateTimeImmutable('now', new \DateTimeZone(FiscalRecorder::TIME_ZONE));
        $written = false;
        foreach ([$this->locations->primary(), $this->locations->secondary()] as $location) {
            if ($location->prepare()) {
                $location->writeJournalId($journalId, $at);
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
     * brought up to date.
     */
    public function flushAfterIssue(): FiscalJournalFlushResult
    {
        $this->intents->resolveOwn($this->connection);
        $result = $this->flush();
        if ($result->status === FiscalJournalFlushResult::OK) {
            $this->intents->reset();
        }

        return $result;
    }

    /** @param bool $backfill marks the lines as documents issued before the journal existed (D11) */
    public function flush(bool $backfill = false): FiscalJournalFlushResult
    {
        $primary = $this->locations->primary();
        $secondary = $this->locations->secondary();
        $primaryWritable = $primary->prepare();
        $secondaryWritable = $secondary->prepare();
        if (!$primaryWritable && !$secondaryWritable) {
            $this->logger?->error('Fiscal journal: neither {primary} nor {secondary} can be written; the documents wait in the database', ['primary' => $primary->directory(), 'secondary' => $secondary->directory()]);

            return new FiscalJournalFlushResult(FiscalJournalFlushResult::UNWRITABLE);
        }
        $journalId = $primary->journalId() ?? $secondary->journalId();
        if ($journalId === null) {
            return new FiscalJournalFlushResult(FiscalJournalFlushResult::NO_JOURNAL);
        }

        $locks = [];
        try {
            if ($primaryWritable) {
                $locks[] = $primary->lock();
            }
            $secondaryLock = $this->tryLock($secondary);
            if ($secondaryLock !== null) {
                $locks[] = $secondaryLock;
            }
            if (!$primaryWritable && $secondaryLock === null) {
                return new FiscalJournalFlushResult(FiscalJournalFlushResult::UNWRITABLE);
            }

            $target = $primaryWritable ? $primary : $secondary;
            if ($target->journalId() === null) {
                $target->writeJournalId($journalId, new \DateTimeImmutable('now', new \DateTimeZone(FiscalRecorder::TIME_ZONE)));
            }
            $damaged = $target->repairTail() === JournalLocation::TAIL_DAMAGED;
            if ($damaged) {
                $this->logger?->error('Fiscal journal in {directory} has a damaged line; nothing is rewritten', ['directory' => $target->directory()]);
            }

            if ($primaryWritable) {
                if ($secondaryLock !== null) {
                    $secondary->repairTail();
                    JournalMerge::secondaryIntoPrimary($secondary, $primary);
                }
                $head = $primary->lastLine();
            } else {
                $primaryHead = $primary->lastValidLine();
                $secondaryHead = $secondary->lastLine();
                if (\is_array($primaryHead) && \is_array($secondaryHead) && $secondaryHead['seq'] <= $primaryHead['seq']) {
                    // Left over from a merge that could not chain: those documents are
                    // in the database, and new lines must continue the primary.
                    $secondary->clearLines();
                    $secondaryHead = null;
                }
                $head = $this->laterOf($primaryHead, $secondaryHead);
            }
            if ($head === false) {
                return new FiscalJournalFlushResult(FiscalJournalFlushResult::DAMAGED, 0, $target->directory(), true);
            }

            $afterId = 0;
            if ($head !== null) {
                $headRecord = \is_array($head['fiscal_record'] ?? null) ? $head['fiscal_record'] : [];
                $afterId = (int) ($headRecord['id'] ?? 0);
                $present = $this->connection->fetchOne('SELECT 1 FROM fiscal_record WHERE id = ? AND hash = ?', [$afterId, (string) ($headRecord['hash'] ?? '')]);
                if ($present === false) {
                    $this->logger?->critical('Fiscal journal: its last document {number} is not in the database; nothing is written', ['number' => $headRecord['number'] ?? '']);

                    return new FiscalJournalFlushResult(FiscalJournalFlushResult::HEAD_MISSING, 0, $target->directory(), $damaged);
                }
            }

            $written = $this->append($target, $head, $afterId, $backfill);

            return new FiscalJournalFlushResult(FiscalJournalFlushResult::OK, $written, $target->directory(), $damaged);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                JournalLocation::unlock($lock);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $head
     *
     * @return int lines written
     */
    private function append(JournalLocation $target, ?array $head, int $afterId, bool $backfill): int
    {
        $written = 0;
        $last = null;
        $zone = new \DateTimeZone(FiscalRecorder::TIME_ZONE);
        try {
            do {
                $records = $this->connection->fetchAllAssociative(
                    sprintf('SELECT * FROM fiscal_record WHERE id > ? ORDER BY id LIMIT %d', self::BATCH),
                    [$afterId]
                );
                foreach ($records as $record) {
                    $hash = (string) $record['hash'];
                    $document = $this->snapshot->build($record, $record['invoice_type'] === FiscalRecorder::CREDIT_NOTE ? $this->intents->movementFor($hash) : null);
                    $line = [
                        'v' => 1,
                        'seq' => (int) ($head['seq'] ?? 0) + 1,
                        'journaled_at' => (new \DateTimeImmutable('now', $zone))->format(\DATE_ATOM),
                        'kind' => $document['kind'],
                        'series' => $document['series'],
                        'counter' => $document['counter'],
                        'fiscal_record' => $record,
                        'rows' => $document['rows'],
                        'backfill' => $backfill,
                        'prev' => (string) ($head['line_hash'] ?? ''),
                    ];
                    $line['line_hash'] = CanonicalJson::lineHash($line);
                    $target->append($line);
                    $head = $line;
                    $last = $line;
                    $afterId = (int) $record['id'];
                    ++$written;
                    $this->intents->journaled($hash);
                }
            } while (\count($records) === self::BATCH);
        } finally {
            if ($last !== null) {
                $target->writeHead($last);
            }
        }

        return $written;
    }

    /**
     * The later of the primary's and the secondary's last line (while the primary
     * is read-only the secondary continues its numbering).
     *
     * @param array<string, mixed>|false|null $primary
     * @param array<string, mixed>|false|null $secondary
     *
     * @return array<string, mixed>|false|null
     */
    private function laterOf(array|false|null $primary, array|false|null $secondary): array|false|null
    {
        if ($secondary === false) {
            return false;
        }
        if ($secondary !== null && ($primary === null || $primary === false || $secondary['seq'] > $primary['seq'])) {
            return $secondary;
        }

        return $primary;
    }

    /** @return resource|null */
    private function tryLock(JournalLocation $location)
    {
        if (!is_dir($location->directory())) {
            return null;
        }
        try {
            return $location->lock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
