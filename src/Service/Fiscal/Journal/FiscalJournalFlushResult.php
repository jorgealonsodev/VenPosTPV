<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/** What one flush of the fiscal journal did. */
final class FiscalJournalFlushResult
{
    /** Every committed document is in the journal (or, for a bounded flush, as many as it may write). */
    public const OK = 'ok';
    /** No journal.id in either location: nothing is written until the journal is created (D11). */
    public const NO_JOURNAL = 'no_journal';
    /** Neither location can be written; the documents wait in the database (S2). */
    public const UNWRITABLE = 'unwritable';
    /** The journal's last document is not in the database (a restore): never written over (D7). */
    public const HEAD_MISSING = 'head_missing';
    /** The journal is damaged ($reason, JournalVerifier's reasons): nothing appended, nothing rewritten (D4). */
    public const DAMAGED = 'damaged';
    /** Document $number cannot be turned into a line ($reason): the flush stops at it, seq stays gapless. */
    public const BLOCKED = 'blocked';
    /** A bounded flush (after a response) could not get journal.lock in time: the next flush does the work. */
    public const BUSY = 'busy';
    /** The primary folder has no journal.id while the secondary has one: it is never recreated here (D7). */
    public const JOURNAL_MISSING = 'journal_missing';

    public function __construct(
        public readonly string $status,
        public readonly int $written = 0,
        public readonly ?string $directory = null,
        public readonly bool $damaged = false,
        public readonly ?string $reason = null,
        public readonly ?string $number = null,
    ) {
    }
}
