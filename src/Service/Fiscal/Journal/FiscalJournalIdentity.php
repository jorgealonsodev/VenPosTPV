<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;

/**
 * The journal the database belongs to (fiscal-journal D1): app_setting
 * «fiscal_journal.id» = the uuid in `journal.id`. A folder without that journal
 * while the setting exists is a lost journal (D7 `journal_missing`).
 *
 * The one reader and writer of the setting: the provisioning step (D11) and
 * `tpv:fiscal:journal:reset` (A6) store it through store().
 */
class FiscalJournalIdentity
{
    public const SETTING = 'fiscal_journal.id';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return string|null null when the database names no journal
     *
     * @throws \Doctrine\DBAL\Exception when the database cannot be read
     */
    public function journalId(): ?string
    {
        $value = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::SETTING]);

        return \is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }

    public function store(string $journalId): void
    {
        $this->connection->transactional(function (Connection $connection) use ($journalId): void {
            $connection->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::SETTING]);
            $connection->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [self::SETTING, strtolower($journalId)]);
        });
    }
}
