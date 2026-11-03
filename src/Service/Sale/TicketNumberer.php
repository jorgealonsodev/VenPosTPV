<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sale;

use Doctrine\DBAL\Connection;

/**
 * Hands out the next ticket number of a terminal: letter + two year digits + six
 * digits (A26000123), which is also the PrestaShop order reference (9 characters).
 *
 * The counter is keyed by the terminal LETTER and the year, so a letter change never
 * reuses a reference. Like CreditNoteNumberer, next() must run inside the
 * transaction that stores the sale: the counter row stays locked until commit, so
 * concurrent sales of the same letter are serialized and a rollback gives the
 * number back, keeping the series free of gaps.
 */
class TicketNumberer
{
    public const MAX_NUMBER = 999999;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Creates the letter's counter row for the year (if missing) in its own
     * autocommit statement, before the sale transaction. Inside the transaction
     * next() then only ever updates an existing row, avoiding the insert race that
     * can deadlock concurrent first sales of a year.
     */
    public function prepareYear(int $terminalId, \DateTimeImmutable $date): void
    {
        $letter = $this->letterOf($terminalId);
        $this->connection->executeStatement(
            'INSERT IGNORE INTO ticket_sequence (letter, year, last_number) VALUES (?, ?, 0)',
            [$letter, (int) $date->format('Y')]
        );
    }

    /**
     * @throws \LogicException    outside a transaction
     * @throws \DomainException   when the terminal has no ticket letter
     * @throws \OverflowException when the letter used up its 999999 numbers of the year;
     *                            the caller's rollback restores the counter
     */
    public function next(int $terminalId, \DateTimeImmutable $date): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Ticket numbers must be taken inside the sale transaction');
        }

        $letter = $this->letterOf($terminalId);
        $year = (int) $date->format('Y');
        $this->connection->executeStatement(
            'INSERT INTO ticket_sequence (letter, year, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$letter, $year]
        );
        $number = (int) $this->connection->fetchOne('SELECT LAST_INSERT_ID()');

        return self::format($letter, $year, $number);
    }

    public static function format(string $letter, int $year, int $number): string
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Ticket numbers start at 1');
        }
        if ($number > self::MAX_NUMBER) {
            throw new \OverflowException(sprintf('La caja %s ha agotado los números de ticket de %d', $letter, $year));
        }

        return sprintf('%s%02d%06d', $letter, $year % 100, $number);
    }

    private function letterOf(int $terminalId): string
    {
        $letter = $this->connection->fetchOne('SELECT ticket_letter FROM terminal WHERE id = ?', [$terminalId]);
        if (!\is_string($letter) || !preg_match('/^[A-Z]$/', $letter)) {
            throw new \DomainException('La caja no tiene letra de ticket: asígnala en Ajustes');
        }

        return $letter;
    }
}
