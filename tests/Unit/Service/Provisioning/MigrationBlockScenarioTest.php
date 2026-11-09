<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\BackupRetention;
use VenPosTPV\Service\Backup\DatabaseBackup;
use VenPosTPV\Service\Backup\HealthCheck;
use VenPosTPV\Service\Backup\HealthReport;
use VenPosTPV\Service\Backup\SqlDumper;
use VenPosTPV\Service\Install\MigrationRunner;
use VenPosTPV\Service\Provisioning\DatabaseLock;
use VenPosTPV\Service\Provisioning\PendingMigrationsStep;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\ProvisioningStateStore;

/**
 * The minute task against a migration that fails half way (MySQL commits DDL, so
 * every rerun fails too): the real runner, step and backup service, with fakes
 * only for the dump, the migrator and the lock. The clean pre-migration copy must
 * survive and the minute task must stop backing up and migrating.
 */
final class MigrationBlockScenarioTest extends TestCase
{
    private Connection $state;
    private string $root;
    private string $dir;
    private int $migrateCalls = 0;
    private int $pending = 1;
    private int $executed = 48;
    private ?\Throwable $migrateError = null;
    /** @var list<string> */
    private array $problems = [];

    protected function setUp(): void
    {
        $this->state = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->state->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
        $this->root = sys_get_temp_dir() . '/venpos-block-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700);
        $this->dir = $this->root . '/backups';
        $this->migrateError = new \RuntimeException('SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name \'ticket_note\'');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
                unlink($this->dir . '/' . $file);
            }
            rmdir($this->dir);
        }
        rmdir($this->root);
    }

    private function backup(): DatabaseBackup
    {
        $dumper = $this->createMock(SqlDumper::class);
        $dumper->method('dump')->willReturnCallback(static function (\Closure $write): int {
            $write('SELECT 1;');

            return 1;
        });
        $problems = &$this->problems;
        $health = new class($problems) implements HealthCheck {
            /** @param list<string> $problems */
            public function __construct(private array &$problems)
            {
            }

            public function run(): HealthReport
            {
                return new HealthReport(1, 0, $this->problems);
            }
        };

        return new DatabaseBackup($dumper, new BackupRetention(), $health, $this->dir);
    }

    private function runner(bool $lockFree = true): ProvisioningRunner
    {
        $migrations = $this->createMock(MigrationRunner::class);
        $migrations->method('pendingCount')->willReturnCallback(fn (): int => $this->pending);
        $migrations->method('executedCount')->willReturnCallback(fn (): int => $this->executed);
        $migrations->method('migrate')->willReturnCallback(function (): int {
            ++$this->migrateCalls;
            if ($this->migrateError !== null) {
                throw $this->migrateError;
            }
            $done = $this->pending;
            $this->executed += $this->pending;
            $this->pending = 0;

            return $done;
        });
        $lock = $this->createMock(DatabaseLock::class);
        $lock->method('acquire')->willReturn($lockFree);

        $step = new PendingMigrationsStep($this->createMock(Connection::class), $migrations, $this->backup(), $lock);

        return new ProvisioningRunner([$step], new ProvisioningStateStore($this->state));
    }

    /** @return list<string> */
    private function premigrate(): array
    {
        return array_values(preg_grep('/\Avenpostpv-premigrate-/', scandir($this->dir) ?: []) ?: []);
    }

    /** @return list<string> */
    private function backups(): array
    {
        return array_values(preg_grep('/\.sql\.gz\z/', scandir($this->dir) ?: []) ?: []);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-09 ' . $time);
    }

    public function testAHalfFailedMigrationIsBackedUpOnceAndTheMinuteTaskStops(): void
    {
        $runner = $this->runner();

        $first = $runner->run(throttled: true, now: self::at('10:00:00'));

        $this->assertSame(1, $this->migrateCalls);
        $this->assertCount(1, $this->premigrate());
        [$clean] = $this->premigrate();
        $this->assertTrue($first->steps[0]->blocked);
        $this->assertStringContainsString('La copia previa está en backups/' . $clean . '.', (string) $first->steps[0]->reason);
        $this->assertStringContainsString('ejecuta php bin/console tpv:provision --env=prod', (string) $first->steps[0]->reason);

        for ($i = 1; $i <= 10; ++$i) {
            $report = $this->runner()->run(throttled: true, now: self::at('10:00:00')->modify(sprintf('+%d minutes', 15 * $i)));
            $this->assertTrue($report->steps[0]->blocked);
            $this->assertSame($first->steps[0]->reason, $report->steps[0]->reason, 'still names the first copy');
        }

        $this->assertSame(1, $this->migrateCalls, 'the migrator is never called again');
        $this->assertSame([$clean], $this->backups(), 'no further backup');

        for ($day = 10; $day <= 20; ++$day) {
            $this->backup()->run(7, new \DateTimeImmutable(sprintf('2026-11-%02d 04:00:00', $day)));
        }
        $this->assertContains($clean, $this->backups(), 'the daily retention never deletes it');
    }

    public function testAManualRunAfterABlockTriesOnceWithANewPreMigrationBackup(): void
    {
        $this->runner()->run(throttled: true, now: self::at('10:00:00'));
        $this->runner()->run(throttled: true, now: self::at('10:15:00'));

        $this->migrateError = null;
        $report = $this->runner()->run(throttled: false, now: self::at('10:20:00'));

        $this->assertSame(2, $this->migrateCalls);
        $this->assertCount(2, $this->premigrate(), 'one new copy for the manual run');
        $this->assertSame('done', $report->steps[0]->status);
        $this->assertFalse($this->state->fetchOne("SELECT value FROM app_setting WHERE name = 'provisioning.migrations'"));
    }

    public function testAFailedHealthCheckBlocksAndWritesNoFurtherCopies(): void
    {
        $this->problems = ['Table sale: Corrupt'];

        $first = $this->runner()->run(throttled: true, now: self::at('10:00:00'));
        for ($i = 1; $i <= 10; ++$i) {
            $this->runner()->run(throttled: true, now: self::at('10:00:00')->modify(sprintf('+%d minutes', 15 * $i)));
        }

        $this->assertTrue($first->steps[0]->blocked);
        $this->assertSame(0, $this->migrateCalls);
        $this->assertCount(1, $this->backups(), 'one -REVISAR copy, not one every 15 minutes');
        $this->assertMatchesRegularExpression('/\Avenpostpv-premigrate-\d{8}-\d{6}-REVISAR\.sql\.gz\z/', $this->backups()[0]);
    }

    public function testANewReleaseWithAnotherPendingSetIsTriedAgainByTheMinuteTask(): void
    {
        $this->runner()->run(throttled: true, now: self::at('10:00:00'));
        $this->runner()->run(throttled: true, now: self::at('10:15:00'));
        $this->assertSame(1, $this->migrateCalls);

        $this->pending = 2;
        $this->migrateError = null;
        $report = $this->runner()->run(throttled: true, now: self::at('10:16:00'));

        $this->assertSame(2, $this->migrateCalls);
        $this->assertCount(2, $this->premigrate());
        $this->assertSame('done', $report->steps[0]->status);
    }

    public function testTheSuccessPathBacksUpOnceAndMigrates(): void
    {
        $this->migrateError = null;

        $report = $this->runner()->run(throttled: true, now: self::at('10:00:00'));

        $this->assertSame('done', $report->steps[0]->status);
        $this->assertSame(1, $this->migrateCalls);
        $this->assertCount(1, $this->premigrate());
    }

    public function testABusyLockIsNotRecordedAsAFailure(): void
    {
        $report = $this->runner(lockFree: false)->run(throttled: true, now: self::at('10:00:00'));

        $this->assertSame('pending', $report->steps[0]->status);
        $this->assertFalse($report->steps[0]->blocked);
        $this->assertSame(0, $this->migrateCalls);
        $this->assertFalse(is_dir($this->dir) && $this->backups() !== [], 'no backup');
        $this->assertFalse($this->state->fetchOne("SELECT value FROM app_setting WHERE name = 'provisioning.migrations'"));
    }

    public function testNothingPendingTakesNoBackup(): void
    {
        $this->pending = 0;

        $report = $this->runner()->run(throttled: true, now: self::at('10:00:00'));

        $this->assertSame('done', $report->steps[0]->status);
        $this->assertSame(0, $this->migrateCalls);
        $this->assertDirectoryDoesNotExist($this->dir);
    }
}
