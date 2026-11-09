<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Install\MigrationRunner;

/**
 * The pending database migrations (the whole migrations/ folder, the same setup
 * as the web installer). It goes first: the other steps and the uploads of the
 * minute task may need the new schema.
 *
 * Applying migrations from the minute task on a live shop is guarded:
 * - only one run migrates at a time (a MySQL named lock that never waits; the
 *   other run reports the step as busy and checks again next minute);
 * - an installed database (one that has executed migrations) is backed up first
 *   with the daily backup service, and is not migrated when the backup fails or
 *   its checks find problems: the reason is reported and nothing changes.
 * An empty database (a new install) has nothing to back up.
 */
#[AsTaggedItem(priority: 1000)]
final class PendingMigrationsStep implements ProvisioningStep
{
    public const LOCK = 'venpostpv_migrations';

    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRunner $migrations,
        private readonly DatabaseBackup $backup,
        private readonly DatabaseLock $lock,
    ) {
    }

    public function id(): string
    {
        return 'migrations';
    }

    public function description(): string
    {
        return 'Actualizar la base de datos (migraciones)';
    }

    public function isDone(): bool
    {
        return $this->migrations->pendingCount($this->connection) === 0;
    }

    public function run(): void
    {
        if (!$this->lock->acquire(self::LOCK)) {
            throw new ProvisioningBusyException('Otra ejecución está actualizando la base de datos en este momento.');
        }

        try {
            // Checked again under the lock: the run that held it may have migrated.
            $pending = $this->migrations->pendingCount($this->connection);
            if ($pending === 0) {
                return;
            }
            $backupName = null;
            if ($this->migrations->executedCount($this->connection) > 0) {
                $backupName = $this->backUp($pending);
            }

            try {
                $this->migrations->migrate($this->connection);
            } catch (\Throwable $e) {
                throw new ProvisioningException(
                    'Las migraciones han fallado: ' . rtrim($e->getMessage(), '.') . '.'
                    . ($backupName !== null ? ' La copia previa está en backups/' . $backupName . '.' : ''),
                    0,
                    $e
                );
            }
        } finally {
            $this->lock->release(self::LOCK);
        }
    }

    /** @return string the backup file name */
    private function backUp(int $pending): string
    {
        $what = $pending === 1 ? 'No se ha aplicado la migración pendiente' : sprintf('No se han aplicado las %d migraciones pendientes', $pending);
        try {
            $result = $this->backup->run();
        } catch (\Throwable $e) {
            throw new ProvisioningException($what . ' porque la copia de seguridad previa ha fallado: ' . $e->getMessage(), 0, $e);
        }
        if (!$result->health->ok()) {
            throw new ProvisioningException(
                $what . ' porque la comprobación de la base de datos ha encontrado problemas: ' . implode('; ', $result->health->problems)
                . '. La copia se ha guardado en backups/' . $result->fileName() . '.'
            );
        }

        return $result->fileName();
    }
}
