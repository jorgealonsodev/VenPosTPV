<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidFieldNameException;

/**
 * The cashier's name on printed documents (tickets, credit notes, X/Z reports):
 * the employee's «Nombre y apellidos» (user.full_name, Ajustes → Empleados) when
 * set, else the user name, else the fallback. Login and the screen keep the user
 * name.
 */
final class CashierName
{
    public const DEFAULT = 'Cajero';

    public static function of(Connection $connection, int $userId, string $fallback = self::DEFAULT): string
    {
        try {
            $row = $connection->fetchAssociative('SELECT full_name, name FROM user WHERE id = ?', [$userId]);
        } catch (InvalidFieldNameException) {
            // Code deployed before Version00000049: the column does not exist yet.
            $row = $connection->fetchAssociative('SELECT name FROM user WHERE id = ?', [$userId]);
        }
        if (!\is_array($row)) {
            return $fallback;
        }
        foreach (['full_name', 'name'] as $column) {
            if (isset($row[$column]) && \is_string($row[$column]) && trim($row[$column]) !== '') {
                return trim($row[$column]);
            }
        }

        return $fallback;
    }
}
