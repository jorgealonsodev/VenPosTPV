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
 *
 * Also the retired journals (app_setting «fiscal_journal.retired», a JSON list):
 * the journals the shop held when an administrator deliberately started over
 * with `tpv:fiscal:journal:reset --accept-shop-ahead`. FiscalJournalGuard, the
 * reset and the provisioning step ignore what the shop holds under them.
 */
class FiscalJournalIdentity
{
    public const SETTING = 'fiscal_journal.id';
    public const RETIRED_SETTING = 'fiscal_journal.retired';

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

    /**
     * @return list<string> the retired journals, lowercase (empty when none or unreadable as JSON)
     *
     * @throws \Doctrine\DBAL\Exception when the database cannot be read
     */
    public function retired(): array
    {
        $value = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [self::RETIRED_SETTING]);
        $list = \is_string($value) ? json_decode($value, true) : null;

        return \is_array($list) ? array_values(array_unique(array_map(static fn ($id): string => strtolower((string) $id), array_filter($list, 'is_string')))) : [];
    }

    /**
     * Adds journals to the retired list (kept with the ones already there).
     *
     * @param list<string> $journalIds
     */
    public function retire(array $journalIds): void
    {
        $all = array_values(array_unique(array_merge($this->retired(), array_map('strtolower', $journalIds))));
        sort($all);
        $this->connection->transactional(function (Connection $connection) use ($all): void {
            $connection->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::RETIRED_SETTING]);
            $connection->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [self::RETIRED_SETTING, json_encode($all, \JSON_THROW_ON_ERROR)]);
        });
    }

    public function store(string $journalId): void
    {
        $this->connection->transactional(function (Connection $connection) use ($journalId): void {
            $connection->executeStatement('DELETE FROM app_setting WHERE name = ?', [self::SETTING]);
            $connection->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [self::SETTING, strtolower($journalId)]);
        });
    }
}
