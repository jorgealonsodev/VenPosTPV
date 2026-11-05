<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Backup;

/**
 * Checks the database before a backup. A failed check never stops the backup: it
 * marks it for review and keeps the older copies (DatabaseBackup).
 */
interface HealthCheck
{
    public function run(): HealthReport;
}
