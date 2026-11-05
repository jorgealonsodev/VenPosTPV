<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Invoice;

use Doctrine\DBAL\Connection;

/**
 * Hands out the next full-invoice number of the shop: F + two year digits + six
 * digits (F26000001), one series for the whole shop and apart from tickets and
 * credit notes (RD 1619/2012 art. 7.1.a; docs/prd/factura-completa.md F4, P1).
 * Nine characters, the shape of a PrestaShop order reference, for invoices issued
 * at sale time (F2).
 *
 * Same mechanism as TicketNumberer: next() runs inside the transaction that stores
 * the invoice and its fiscal record, so the counter row stays locked until commit
 * and a rollback gives the number back (no gaps).
 */
class InvoiceNumberer
{
    /** The letter of the series; tills cannot use it as ticket letter. */
    public const SERIES = 'F';
    public const MAX_NUMBER = 999999;
    public const RESERVED_LETTER_MESSAGE = 'La letra F está reservada para las facturas';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Creates the year's counter row (if missing) in its own statement, before the transaction. */
    public function prepareYear(\DateTimeImmutable $date): void
    {
        $this->connection->executeStatement(
            'INSERT IGNORE INTO invoice_sequence (series, year, last_number) VALUES (?, ?, 0)',
            [self::SERIES, (int) $date->format('Y')]
        );
    }

    /**
     * @return array{number: string, year: int, seq: int}
     *
     * @throws \LogicException    outside a transaction
     * @throws \OverflowException when the year used up its 999999 numbers
     */
    public function next(\DateTimeImmutable $date): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Invoice numbers must be taken inside the invoice transaction');
        }

        $year = (int) $date->format('Y');
        $this->connection->executeStatement(
            'INSERT INTO invoice_sequence (series, year, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [self::SERIES, $year]
        );
        $seq = (int) $this->connection->fetchOne('SELECT LAST_INSERT_ID()');

        return ['number' => self::format($year, $seq), 'year' => $year, 'seq' => $seq];
    }

    /** The year's series (F26), as the numbering audit records it. */
    public static function series(int $year): string
    {
        return sprintf('%s%02d', self::SERIES, $year % 100);
    }

    public static function format(int $year, int $number): string
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Invoice numbers start at 1');
        }
        if ($number > self::MAX_NUMBER) {
            throw new \OverflowException(sprintf('Se han agotado los números de factura de %d', $year));
        }

        return sprintf('%s%06d', self::series($year), $number);
    }

    public static function isReservedLetter(string $letter): bool
    {
        return strtoupper(trim($letter)) === self::SERIES;
    }
}
