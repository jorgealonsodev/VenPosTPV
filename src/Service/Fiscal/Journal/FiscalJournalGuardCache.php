<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * FiscalJournalGuard for every state-changing request and the every-minute task
 * (FiscalJournalGuardListener::onRequest, CronRunner): while the database is behind
 * the fiscal journal or the shop copy nothing may take the ids the recovery needs
 * (a cash movement, a till opening, an upload row), not only the issuing actions.
 *
 * A full check reads both journal folders and looks documents up in the database,
 * so a "not blocked" answer is kept in one small file shared by every PHP process,
 * keyed by the database's chain head (`fiscal_chain.last_hash`), and reused for
 * TTL_SECONDS. Staleness bound: a restore of an older backup changes the chain head,
 * so it is seen by the very next request; anything else that can start a block
 * without touching the chain (a journal folder removed, a newer shop head stored by
 * the every-minute task) is seen within TTL_SECONDS. A blocking answer is never
 * kept: the till stays blocked exactly until the recovery, and is free on the next
 * request after it. A chain that cannot be read or a file that cannot be written
 * only cost a full check each time.
 *
 * The issuing actions keep their own uncached checks (the listener's early check and
 * FiscalRecorder::append under the chain lock).
 */
class FiscalJournalGuardCache
{
    public const TTL_SECONDS = 5;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly FiscalJournalGuard $guard,
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/var/fiscal-journal-guard.json')] private readonly string $file,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** Whether the guard blocks now (behind_journal, behind_shop, journal_missing). */
    public function blocks(): bool
    {
        return $this->state() !== FiscalJournalGuard::OK;
    }

    /** The guard's state; a "not blocked" answer may be up to TTL_SECONDS old for the same chain head. */
    public function state(): string
    {
        $head = $this->chainHead();
        $now = ($this->clock)()->getTimestamp();
        if ($head !== null && $this->reusable($head, $now)) {
            return FiscalJournalGuard::OK;
        }

        $state = $this->guard->check()['state'];
        if ($state === FiscalJournalGuard::OK && $head !== null) {
            $this->write(['state' => FiscalJournalGuard::OK, 'head' => $head, 'checked_at' => $now]);
        } elseif ($state !== FiscalJournalGuard::OK) {
            $this->forget();
        }

        return $state;
    }

    /** Drops the kept answer: the next call checks in full. */
    public function forget(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /** The database's chain head ('' for an empty chain), null when it cannot be read. */
    private function chainHead(): ?string
    {
        try {
            $row = $this->connection->fetchAssociative('SELECT last_record_id, last_hash FROM fiscal_chain WHERE id = 1');
        } catch (\Throwable) {
            return null;
        }
        if ($row === false) {
            return '';
        }

        return (string) ($row['last_record_id'] ?? '') . ':' . (string) ($row['last_hash'] ?? '');
    }

    private function reusable(string $head, int $now): bool
    {
        $raw = @file_get_contents($this->file);
        if (!\is_string($raw) || $raw === '') {
            return false;
        }
        $kept = json_decode($raw, true);
        if (!\is_array($kept) || ($kept['state'] ?? null) !== FiscalJournalGuard::OK || ($kept['head'] ?? null) !== $head || !\is_int($kept['checked_at'] ?? null)) {
            return false;
        }
        $age = $now - $kept['checked_at'];

        return $age >= 0 && $age < self::TTL_SECONDS;
    }

    /** @param array{state: string, head: string, checked_at: int} $answer */
    private function write(array $answer): void
    {
        $directory = \dirname($this->file);
        if (!is_dir($directory)) {
            return;
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, json_encode($answer, \JSON_THROW_ON_ERROR)) === false || !@rename($temporary, $this->file)) {
            @unlink($temporary);
            $this->logger?->debug('Fiscal journal guard answer could not be kept in {file}', ['file' => $this->file]);
        }
    }
}
