<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/** What tpv:fiscal:journal:reset did (FiscalJournalReset), with its message in Spanish. */
final class FiscalJournalResetResult
{
    /** A new journal was started (the backfill may continue in the every-minute job). */
    public const DONE = 'done';
    /** No reason given: nothing done. */
    public const INVALID = 'invalid';
    /** The database is behind a healthy journal or behind the shop: nothing done. */
    public const REFUSED = 'refused';
    /** Something failed (a replay running, a folder or the database not writable). */
    public const ERROR = 'error';

    /**
     * @param list<string> $archived the archive folders, relative to the project
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $journalId = null,
        public readonly int $backfilled = 0,
        public readonly array $archived = [],
    ) {
    }

    /** The command's exit code: 0 done, 1 error, 2 no reason, 3 refused. */
    public function exitCode(): int
    {
        return match ($this->status) {
            self::DONE => 0,
            self::INVALID => 2,
            self::REFUSED => 3,
            default => 1,
        };
    }
}
