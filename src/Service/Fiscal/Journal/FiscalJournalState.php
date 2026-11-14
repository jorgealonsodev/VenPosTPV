<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/**
 * What the administrator is told about the fiscal journal (fiscal-journal D9): the
 * content of `state.json` and of GET /api/fiscal/journal, plus the exit code of
 * tpv:fiscal:journal.
 */
final class FiscalJournalState
{
    /** No journal created yet (the provisioning step creates it, D11): nothing to do. */
    public const NOT_STARTED = 'not_started';
    /** Every document of the database is in the journal. */
    public const OK = 'ok';
    /** Some documents are not in the journal yet, for less than 5 minutes. */
    public const PENDING = 'pending';
    /** Documents have been waiting for more than 5 minutes (the folders cannot be written). */
    public const LAGGING = 'lagging';
    /** A line before the last one fails its checks (D4): reported, never rewritten. */
    public const DAMAGED = 'damaged';
    /** A document cannot be turned into a journal line: the flush stops at it, the later ones wait. */
    public const BLOCKED = 'blocked';
    /** The journal's last document is not in the database (a restore): the guard's case (D7). */
    public const HEAD_MISSING = 'head_missing';
    /** The primary folder lost its journal.id while the secondary has one: never recreated (D7, the guard's case). */
    public const JOURNAL_MISSING = 'journal_missing';

    /*
     * Exit codes of tpv:fiscal:journal. An incident (damaged, blocked, no folder
     * writable) gives its code only on the run that first detects it and once a day
     * while it lasts; the runs in between exit 0 (the state still tells it).
     */
    public const EXIT_OK = 0;
    /** No folder can be written, or a document cannot be journaled. */
    public const EXIT_UNWRITABLE = 1;
    /** Reserved for the guard (D7, its own task): the till cannot issue documents. */
    public const EXIT_GUARD = 2;
    public const EXIT_DAMAGED = 3;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $exitCode = self::EXIT_OK,
    ) {
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? self::NOT_STARTED);
    }

    /**
     * The payload for the administrator (the verification checkpoints are internal).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->data;
        unset($data['verified']);

        return $data;
    }

    public static function notStarted(\DateTimeImmutable $now): self
    {
        return new self([
            'v' => 1,
            'status' => self::NOT_STARTED,
            'checked_at' => $now->format(\DATE_ATOM),
            'journal_id' => null,
            'writable' => null,
            'last_document' => null,
            'pending' => ['count' => 0, 'oldest_number' => null, 'oldest_at' => null],
            'damaged' => null,
            'blocked' => null,
            'unconfirmed_intents' => [],
            'confirmed_missing' => [],
            'alert' => null,
            'full_verified_at' => null,
        ]);
    }
}
