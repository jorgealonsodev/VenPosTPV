<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/** What one flush of the fiscal journal did. */
final class FiscalJournalFlushResult
{
    /** Every committed document is in the journal. */
    public const OK = 'ok';
    /** No journal.id in either location: nothing is written until the journal is created (D11). */
    public const NO_JOURNAL = 'no_journal';
    /** Neither location can be written; the documents wait in the database (S2). */
    public const UNWRITABLE = 'unwritable';
    /** The journal's last document is not in the database (a restore): never written over (D7). */
    public const HEAD_MISSING = 'head_missing';
    /** The last line cannot be told (damaged journal, D4): nothing appended. */
    public const DAMAGED = 'damaged';

    public function __construct(
        public readonly string $status,
        public readonly int $written = 0,
        public readonly ?string $directory = null,
        public readonly bool $damaged = false,
    ) {
    }
}
