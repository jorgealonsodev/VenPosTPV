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
 *
 * The copy is a pre-migration one (DatabaseBackup::runPreMigration(), outside the
 * daily retention). MySQL commits each DDL statement, so a migration that fails
 * half way leaves its first changes applied and every rerun fails too, and a
 * database whose checks found problems will not heal by waiting: both block the
 * step (ProvisioningBlockedException). The minute task then stops trying, so it
 * neither takes a new copy every 15 minutes nor pushes the clean one out; only
 * tpv:provision by hand, or a new release with another set of pending migrations
 * (blockKey()), tries again.
 */
#[AsTaggedItem(priority: 1000)]
final class PendingMigrationsStep implements BlockableProvisioningStep
{
    public const LOCK = 'venpostpv_migrations';
    private const BLOCKED = ' La tarea de cada minuto no lo volverá a intentar: revisa la base de datos y ejecuta php bin/console tpv:provision --env=prod.';

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

    /** The pending set of migrations: another one (a new release) lifts a block. */
    public function blockKey(): string
    {
        return sprintf('executed=%d pending=%d', $this->migrations->executedCount($this->connection), $this->migrations->pendingCount($this->connection));
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
                throw new ProvisioningBlockedException(
                    'Las migraciones han fallado: ' . rtrim($e->getMessage(), '.') . '.'
                    . ($backupName !== null ? ' La copia previa está en backups/' . $backupName . '.' : '')
                    . self::BLOCKED,
                    $this->currentBlockKey(),
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
            $result = $this->backup->runPreMigration();
        } catch (\Throwable $e) {
            throw new ProvisioningException($what . ' porque la copia de seguridad previa ha fallado: ' . $e->getMessage(), 0, $e);
        }
        if (!$result->health->ok()) {
            throw new ProvisioningBlockedException(
                $what . ' porque la comprobación de la base de datos ha encontrado problemas: ' . implode('; ', $result->health->problems)
                . '. La copia se ha guardado en backups/' . $result->fileName() . '.' . self::BLOCKED,
                $this->currentBlockKey()
            );
        }

        return $result->fileName();
    }

    // After a failure the database may be unreachable; an unknown key only means
    // the minute task tries once more and blocks again with the real one.
    private function currentBlockKey(): string
    {
        try {
            return $this->blockKey();
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
