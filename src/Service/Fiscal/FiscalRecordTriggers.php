<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

use Doctrine\DBAL\Connection;

/**
 * Makes MySQL refuse UPDATE and DELETE on fiscal_record. Shared hosting often
 * denies CREATE TRIGGER (no TRIGGER privilege, or binary logging without SUPER),
 * so this is best effort: it never breaks an install, and the chain verification
 * still detects any change made behind the application's back.
 */
final class FiscalRecordTriggers
{
    private const TRIGGERS = [
        'fiscal_record_no_update' => 'UPDATE',
        'fiscal_record_no_delete' => 'DELETE',
    ];

    /** @return bool whether both triggers exist afterwards */
    public static function create(Connection $connection): bool
    {
        foreach (self::TRIGGERS as $name => $event) {
            try {
                $connection->executeStatement(sprintf(
                    "CREATE TRIGGER IF NOT EXISTS %s BEFORE %s ON fiscal_record FOR EACH ROW
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fiscal_record is append-only'",
                    $name,
                    $event
                ));
            } catch (\Throwable) {
                return false;
            }
        }

        return self::exist($connection);
    }

    public static function exist(Connection $connection): bool
    {
        $found = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'fiscal_record'
               AND TRIGGER_NAME IN ('fiscal_record_no_update', 'fiscal_record_no_delete')"
        );

        return $found === \count(self::TRIGGERS);
    }
}
