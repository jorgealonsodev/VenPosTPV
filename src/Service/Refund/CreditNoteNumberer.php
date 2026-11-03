<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Doctrine\DBAL\Connection;

/**
 * Hands out the next credit-note number of the year (R2026-000001, R2026-000002...).
 *
 * Must run inside the transaction that completes the refund: the counter row stays
 * locked until commit, so concurrent refunds are serialized and a rollback gives
 * the number back, keeping the series free of gaps.
 */
class CreditNoteNumberer
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Creates the year's counter row (if missing) in its own autocommit statement,
     * before the refund transaction. Inside the transaction next() then only ever
     * updates an existing row, avoiding the insert race that can deadlock
     * concurrent first refunds of a year.
     */
    public function prepareYear(\DateTimeImmutable $date): void
    {
        $this->connection->executeStatement(
            'INSERT IGNORE INTO credit_note_sequence (year, last_number) VALUES (?, 0)',
            [(int) $date->format('Y')]
        );
    }

    public function next(\DateTimeImmutable $date): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Credit-note numbers must be taken inside the refund transaction');
        }

        $year = (int) $date->format('Y');
        $this->connection->executeStatement(
            'INSERT INTO credit_note_sequence (year, last_number) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$year]
        );
        $number = (int) $this->connection->fetchOne('SELECT LAST_INSERT_ID()');

        return sprintf('R%d-%06d', $year, $number);
    }
}
