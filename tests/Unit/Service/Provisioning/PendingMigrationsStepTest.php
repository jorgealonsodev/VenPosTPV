<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Provisioning;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\BackupResult;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Backup\HealthReport;
use VenPosTPV\Service\Install\MigrationRunner;
use VenPosTPV\Service\Provisioning\DatabaseLock;
use VenPosTPV\Service\Provisioning\PendingMigrationsStep;
use VenPosTPV\Service\Provisioning\BlockableProvisioningStep;
use VenPosTPV\Service\Provisioning\ProvisioningBlockedException;
use VenPosTPV\Service\Provisioning\ProvisioningBusyException;
use VenPosTPV\Service\Provisioning\ProvisioningException;

/**
 * Pending migrations as a provisioning step: an installed database is backed up
 * first and is never migrated without a good backup, and only one run migrates at
 * a time.
 */
final class PendingMigrationsStepTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    private function migrations(int $pending, int $executed, ?\Throwable $migrateError = null): MigrationRunner
    {
        $migrations = $this->createMock(MigrationRunner::class);
        $migrations->method('pendingCount')->willReturn($pending);
        $migrations->method('executedCount')->willReturn($executed);
        $migrations->method('migrate')->willReturnCallback(function () use ($pending, $migrateError): int {
            $this->log[] = 'migrate';
            if ($migrateError !== null) {
                throw $migrateError;
            }

            return $pending;
        });

        return $migrations;
    }

    private function backup(?\Throwable $error = null, array $problems = []): DatabaseBackup
    {
        $backup = $this->createMock(DatabaseBackup::class);
        $backup->expects($this->never())->method('run');
        $backup->method('runPreMigration')->willReturnCallback(function () use ($error, $problems): BackupResult {
            $this->log[] = 'backup';
            if ($error !== null) {
                throw $error;
            }

            return new BackupResult('/srv/tpv/backups/venpostpv-premigrate-20261109-100000' . ($problems === [] ? '' : '-REVISAR') . '.sql.gz', 2048, 40, new HealthReport(40, 3, $problems));
        });

        return $backup;
    }

    private function lock(bool $free = true): DatabaseLock
    {
        $lock = $this->createMock(DatabaseLock::class);
        $lock->method('acquire')->willReturnCallback(function (string $name) use ($free): bool {
            $this->log[] = 'lock ' . $name;

            return $free;
        });
        $lock->method('release')->willReturnCallback(function (string $name): void {
            $this->log[] = 'unlock ' . $name;
        });

        return $lock;
    }

    private function step(MigrationRunner $migrations, ?DatabaseBackup $backup = null, ?DatabaseLock $lock = null): PendingMigrationsStep
    {
        return new PendingMigrationsStep($this->createMock(Connection::class), $migrations, $backup ?? $this->backup(), $lock ?? $this->lock());
    }

    public function testItIsDoneWhenNoMigrationIsPending(): void
    {
        $this->assertTrue($this->step($this->migrations(0, 49))->isDone());
        $this->assertFalse($this->step($this->migrations(2, 47))->isDone());
        $this->assertSame('migrations', $this->step($this->migrations(0, 49))->id());
        $this->assertSame('Actualizar la base de datos (migraciones)', $this->step($this->migrations(0, 49))->description());
    }

    public function testNothingPendingMeansNoBackupAndNoMigration(): void
    {
        $this->step($this->migrations(0, 49))->run();

        $this->assertSame(['lock venpostpv_migrations', 'unlock venpostpv_migrations'], $this->log);
    }

    public function testAnInstalledDatabaseIsBackedUpBeforeItIsMigrated(): void
    {
        $this->step($this->migrations(2, 47))->run();

        $this->assertSame(['lock venpostpv_migrations', 'backup', 'migrate', 'unlock venpostpv_migrations'], $this->log);
    }

    public function testAnEmptyDatabaseIsMigratedWithoutABackup(): void
    {
        $this->step($this->migrations(49, 0))->run();

        $this->assertSame(['lock venpostpv_migrations', 'migrate', 'unlock venpostpv_migrations'], $this->log);
    }

    public function testAFailedBackupBlocksTheMigrationAndGivesTheReason(): void
    {
        try {
            $this->step($this->migrations(2, 47), $this->backup(new \RuntimeException('Cannot write the backup file (is the disk full?).')))->run();
            self::fail('No migration without a backup.');
        } catch (ProvisioningException $e) {
            $this->assertSame(
                'No se han aplicado las 2 migraciones pendientes porque la copia de seguridad previa ha fallado: Cannot write the backup file (is the disk full?).',
                $e->getMessage()
            );
        }

        $this->assertNotContains('migrate', $this->log);
        $this->assertSame('unlock venpostpv_migrations', end($this->log), 'the lock is always released');
    }

    public function testAFailedBackupIsNotABlock(): void
    {
        try {
            $this->step($this->migrations(2, 47), $this->backup(new \RuntimeException('Cannot write the backup file (is the disk full?).')))->run();
            self::fail('No migration without a backup.');
        } catch (ProvisioningException $e) {
            $this->assertNotInstanceOf(ProvisioningBlockedException::class, $e, 'nothing was written: the minute task may try again later');
        }
    }

    public function testABackupWhoseChecksFoundProblemsBlocksTheMigrationAndTheMinuteTask(): void
    {
        try {
            $this->step($this->migrations(1, 48), $this->backup(problems: ['Table sale: Corrupt']))->run();
            self::fail('No migration on a database with problems.');
        } catch (ProvisioningBlockedException $e) {
            $this->assertSame(
                'No se ha aplicado la migración pendiente porque la comprobación de la base de datos ha encontrado problemas: Table sale: Corrupt. '
                . 'La copia se ha guardado en backups/venpostpv-premigrate-20261109-100000-REVISAR.sql.gz. '
                . 'La tarea de cada minuto no lo volverá a intentar: revisa la base de datos y ejecuta php bin/console tpv:provision --env=prod.',
                $e->getMessage()
            );
            $this->assertSame('executed=48 pending=1', $e->blockKey);
        }

        $this->assertNotContains('migrate', $this->log);
    }

    public function testWhileAnotherRunMigratesNothingIsDone(): void
    {
        try {
            $this->step($this->migrations(2, 47), lock: $this->lock(free: false))->run();
            self::fail('A busy lock must be reported.');
        } catch (ProvisioningBusyException $e) {
            $this->assertSame('Otra ejecución está actualizando la base de datos en este momento.', $e->getMessage());
        }

        $this->assertSame(['lock venpostpv_migrations'], $this->log, 'no backup, no migration and no release of a lock it does not hold');
    }

    public function testAFailedMigrationNamesTheBackupAndReleasesTheLock(): void
    {
        try {
            $this->step($this->migrations(2, 47, new \RuntimeException('Duplicate column name')))->run();
            self::fail('A failed migration must be reported.');
        } catch (ProvisioningBlockedException $e) {
            $this->assertSame(
                'Las migraciones han fallado: Duplicate column name. La copia previa está en backups/venpostpv-premigrate-20261109-100000.sql.gz. '
                . 'La tarea de cada minuto no lo volverá a intentar: revisa la base de datos y ejecuta php bin/console tpv:provision --env=prod.',
                $e->getMessage()
            );
            $this->assertSame('executed=47 pending=2', $e->blockKey, 'the pending set the block belongs to');
        }

        $this->assertSame('unlock venpostpv_migrations', end($this->log));
    }

    public function testItsBlockKeyIsThePendingSetOfMigrations(): void
    {
        $step = $this->step($this->migrations(2, 47));

        $this->assertInstanceOf(BlockableProvisioningStep::class, $step);
        $this->assertSame('executed=47 pending=2', $step->blockKey());
        $this->assertSame([], $this->log, 'cheap: no lock, no backup, no migration');
        $this->assertSame('executed=47 pending=3', $this->step($this->migrations(3, 47))->blockKey(), 'a new release has a new key');
    }
}
