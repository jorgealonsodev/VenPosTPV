<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/**
 * What a run of FiscalReplay found or did (fiscal-journal D8.5), shared by the
 * command and, later, the Ajustes button. `message` is the Spanish text for the
 * administrator when there is one.
 */
final class FiscalReplayResult
{
    /** Nothing to replay: the database holds every document of the journal. */
    public const NOTHING = 'nothing';
    /**
     * The database holds every document of the journal and more: those are not
     * journaled yet (a flush pending). Nothing to replay; the next tpv:cron journals them.
     */
    public const AHEAD = 'ahead';
    /** Dry run: these documents would be replayed. */
    public const PENDING = 'pending';
    /** Replayed, the chain verifies and the guard no longer blocks. */
    public const DONE = 'done';
    /** The restored backup is older than the oldest journal line kept (S10). */
    public const TOO_OLD = 'too_old';
    /** A row to insert is taken by another one, or the database holds documents the journal does not. */
    public const CONFLICT = 'conflict';
    /** Nothing written: the journal is damaged, busy, or the replay failed and was rolled back. */
    public const ERROR = 'error';
    /** The replay has nothing more to put back but the guard still refuses to issue. */
    public const STILL_BLOCKED = 'still_blocked';

    private const EXIT_CODES = [
        self::NOTHING => 0,
        self::DONE => 0,
        self::AHEAD => 0,
        self::ERROR => 1,
        self::STILL_BLOCKED => 1,
        self::PENDING => 2,
        self::TOO_OLD => 3,
        self::CONFLICT => 4,
    ];

    /**
     * @param list<array{seq: int, kind: string, number: string, generated_at: string}> $documents
     *        the documents replayed (DONE, STILL_BLOCKED) or that would be (PENDING)
     * @param list<array{table: string, from: int, to: int}> $remaps movement, outbox and
     *        session rows put under a fresh id because their original id holds another row
     */
    public function __construct(
        public readonly string $status,
        public readonly array $documents = [],
        public readonly ?string $message = null,
        public readonly array $remaps = [],
    ) {
    }

    public function exitCode(): int
    {
        return self::exitCodeOf($this->status);
    }

    public static function exitCodeOf(string $status): int
    {
        return self::EXIT_CODES[$status] ?? 1;
    }
}
