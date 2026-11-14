<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Starts a new fiscal journal (fiscal-journal D11, A6): writes `journal.id` in both
 * folders and the app_setting `fiscal_journal.id` (FiscalJournalIdentity::store())
 * together, under the chain lock (`fiscal_chain` FOR UPDATE), the lock every issue
 * takes before FiscalJournalGuard checks the journal. Between the two writes the
 * guard would say `journal_missing`; under the lock no till issues in between.
 *
 * The journal records the last fiscal_record id issued before it (`backfill_until`):
 * those documents are journaled with `backfill: true` by whichever flush writes them
 * (the step's own bounded flush, the every-minute job, the reset).
 *
 * Used by FiscalJournalStep (first install or upgrade) and FiscalJournalReset; never
 * by anything that runs by itself over a lost journal.
 */
class FiscalJournalStarter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalJournalWriter $writer,
        private readonly FiscalJournalIdentity $identity,
    ) {
    }

    /**
     * @param (callable(): void)|null $beforeCreate runs first under the chain lock (the reset archives the old journal there)
     * @param (callable(string, int): void)|null $afterStore runs under the chain lock with the new id and backfill_until, in the same transaction (the reset's audit record)
     *
     * @return array{journal_id: string, backfill_until: int}
     *
     * @throws \Throwable when a folder or the database cannot be written (the transaction is rolled back)
     */
    public function start(?callable $beforeCreate = null, ?callable $afterStore = null): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($beforeCreate, $afterStore): array {
            $lock = 'SELECT last_hash FROM fiscal_chain WHERE id = 1';
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $lock .= ' FOR UPDATE';
            }
            $connection->fetchOne($lock);
            $until = (int) ($connection->fetchOne('SELECT MAX(id) FROM fiscal_record') ?: 0);
            if ($beforeCreate !== null) {
                $beforeCreate();
            }
            $journalId = $this->writer->createJournal(null, null, $until);
            $this->identity->store($journalId);
            if ($afterStore !== null) {
                $afterStore($journalId, $until);
            }

            return ['journal_id' => $journalId, 'backfill_until' => $until];
        });
    }
}
