<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cron\CronAttemptMarker;
use VenPosTPV\Service\Cron\CronJob;
use VenPosTPV\Service\Cron\CronJobOutcome;
use VenPosTPV\Service\Cron\CronLock;
use VenPosTPV\Service\Cron\CronRunner;
use VenPosTPV\Service\Cron\CronStateStore;

/**
 * The nightly jobs keep their once-a-night accounting (at most 3 attempts, 30
 * minutes apart) even when the database refuses to save it: a local marker per
 * job in the lock directory also has to say the job is due.
 */
final class CronAttemptMarkerTest extends TestCase
{
    private Connection $connection;
    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/venpos-cron-marker-' . bin2hex(random_bytes(6));
        mkdir($this->lockDir, 0700);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->lockDir . '/state.sqlite']);
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        foreach (glob($this->lockDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->lockDir);
    }

    private function runner(?CronStateStore $store = null, ?CronAttemptMarker $marker = null): CronRunner
    {
        return new CronRunner($store ?? new CronStateStore($this->connection), new CronLock($this->lockDir), 'Europe/Madrid', $marker);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Madrid'));
    }

    /**
     * @param array<string, int> $results exit code per job id; 0 when absent
     *
     * @return list<string>
     */
    private function launchedAt(string $time, array $results = [], ?CronRunner $runner = null): array
    {
        $launched = [];
        ($runner ?? $this->runner())->run(static function (CronJob $job) use (&$launched, $results): CronJobOutcome {
            $launched[] = $job->id;

            return new CronJobOutcome($results[$job->id] ?? 0, 'Sync failed');
        }, self::at($time));

        return $launched;
    }

    /**
     * Runs the task once a minute and counts the launches per job.
     *
     * @param array<string, int> $results
     *
     * @return array<string, int>
     */
    private function simulate(string $from, int $runs, array $results): array
    {
        $counts = ['outbox' => 0, 'stock' => 0, 'catalog' => 0, 'customers' => 0, 'backup' => 0];
        $at = self::at($from);
        for ($i = 0; $i < $runs; ++$i) {
            foreach ($this->launchedAt($at->format('Y-m-d H:i:s'), $results) as $id) {
                ++$counts[$id];
            }
            $at = $at->modify('+1 minute');
        }

        return $counts;
    }

    private function refuseWrites(?string $name = null): void
    {
        $when = $name !== null ? " WHEN NEW.name = '{$name}'" : '';
        $this->connection->executeStatement("CREATE TRIGGER no_writes BEFORE INSERT ON app_setting{$when} BEGIN SELECT RAISE(ABORT, 'MySQL server has gone away'); END");
    }

    public function testWithEveryStateWriteRefusedEachNightlyJobStillRunsAtMostItsAttemptsANight(): void
    {
        $this->refuseWrites();
        $this->launchedAt('2026-11-14 12:00:10');

        // 03:00 to 06:00, one run a minute, the full catalog always failing.
        $counts = $this->simulate('2026-11-15 03:00:10', 181, ['catalog' => 1]);

        self::assertSame(['outbox' => 181, 'stock' => 181, 'catalog' => 3, 'customers' => 1, 'backup' => 1], $counts);
    }

    public function testWithOnlyTheCatalogStateRefusedTheCatalogRunsThreeTimes(): void
    {
        $this->refuseWrites('cron.catalog');
        $this->launchedAt('2026-11-14 12:00:10');

        $counts = $this->simulate('2026-11-15 03:00:10', 181, ['catalog' => 1]);

        self::assertSame(3, $counts['catalog']);
        self::assertSame(1, $counts['customers']);
        self::assertSame(1, $counts['backup']);
    }

    public function testTheNextNightTheAttemptsStartOverWhileTheDatabaseStillRefusesWrites(): void
    {
        $this->refuseWrites();
        $this->launchedAt('2026-11-14 12:00:10');
        $this->simulate('2026-11-15 03:00:10', 181, ['catalog' => 1]);

        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 20:00:10', ['catalog' => 1]));
        self::assertContains('catalog', $this->launchedAt('2026-11-16 03:00:10', ['catalog' => 1]), 'a new night');
        self::assertNotContains('catalog', $this->launchedAt('2026-11-16 03:01:10', ['catalog' => 1]));
        self::assertContains('catalog', $this->launchedAt('2026-11-16 03:30:10'), 'second try of the new night');
        self::assertNotContains('catalog', $this->launchedAt('2026-11-16 04:00:10'), 'it worked: once a night');
    }

    public function testAStateThatCannotBeReadNeverStopsTheRunAndIsNotAFailure(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        $store = new class($this->connection) extends CronStateStore {
            public function job(string $id): array
            {
                throw new \RuntimeException('database is locked');
            }
        };

        $launched = [];
        $report = $this->runner($store)->run(static function (CronJob $job) use (&$launched): CronJobOutcome {
            $launched[] = $job->id;

            return new CronJobOutcome(0, 'ok');
        }, self::at('2026-11-15 03:00:10'));

        // The marker still knows the nightly jobs ran yesterday: only the catalog is due.
        self::assertSame(['outbox', 'stock', 'catalog'], $launched);
        self::assertSame([], $report->failures());
        self::assertFalse($report->failed());
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 03:01:10', [], $this->runner($store)), 'the marker keeps it once a night');
    }

    public function testWithoutAnyStateReadableEveryJobRuns(): void
    {
        $store = new class($this->connection) extends CronStateStore {
            public function job(string $id): array
            {
                throw new \RuntimeException('database is locked');
            }
        };

        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $this->launchedAt('2026-11-14 12:00:10', [], $this->runner($store)));
    }

    public function testACorruptMarkerIsIgnoredAndTheDatabaseDecides(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        file_put_contents($this->lockDir . '/cron-catalog.state', '{"slot": "not a date", "attempts": "x"');
        file_put_contents($this->lockDir . '/cron-customers.state', '["slot", 3]');
        file_put_contents($this->lockDir . '/cron-backup.state', '{"slot": 7, "attempts": [], "last_attempt_at": false, "succeeded": "yes"}');

        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $this->launchedAt('2026-11-15 09:00:10'));
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 09:01:10'));
    }

    public function testAnUnwritableMarkerDirectoryDoesNotBlockTheJobs(): void
    {
        // A regular file where the directory should be: nothing can be written there.
        $notADirectory = $this->lockDir . '/not-a-directory';
        file_put_contents($notADirectory, '');
        $marker = new CronAttemptMarker($notADirectory . '/var');
        $runner = $this->runner(null, $marker);

        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $this->launchedAt('2026-11-14 12:00:10', [], $runner));
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-14 12:01:10', [], $runner), 'the database alone decides');
        self::assertSame(['outbox', 'stock', 'catalog'], $this->launchedAt('2026-11-15 03:00:10', [], $runner));
    }

    public function testAnAttemptLeftByAKilledProcessCountsAsAnAttempt(): void
    {
        $this->refuseWrites('cron.catalog');
        $this->launchedAt('2026-11-14 12:00:10');
        // A process started the catalog at 03:00 and was killed before it finished.
        (new CronAttemptMarker($this->lockDir))->begin('catalog', self::at('2026-11-15 03:00:00'), self::at('2026-11-15 03:00:10'));

        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 03:01:10'));
        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 03:29:10'));
        self::assertContains('catalog', $this->launchedAt('2026-11-15 03:30:10'), 'retried after half an hour');
    }

    public function testTheMarkerIsWrittenBeforeTheJobIsLaunched(): void
    {
        $this->refuseWrites('cron.catalog');
        $this->launchedAt('2026-11-14 12:00:10');
        $file = $this->lockDir . '/cron-catalog.state';
        $seen = [];

        $this->runner()->run(static function (CronJob $job) use ($file, &$seen): CronJobOutcome {
            if ($job->id === 'catalog') {
                $seen = json_decode((string) file_get_contents($file), true);
            }

            return new CronJobOutcome(0, 'ok');
        }, self::at('2026-11-15 03:00:10'));

        self::assertSame(1, $seen['attempts'] ?? null);
        self::assertArrayHasKey('succeeded', $seen);
        self::assertNull($seen['succeeded'], 'no result yet while the job runs');
        self::assertTrue(json_decode((string) file_get_contents($file), true)['succeeded']);
    }

    public function testASkippedJobWritesNoMarker(): void
    {
        $held = fopen($this->lockDir . '/cron-nightly.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));
        try {
            $this->launchedAt('2026-11-14 12:00:10');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        self::assertSame([], glob($this->lockDir . '/*.state'));
    }

    public function testTheMinuteJobsUseNoMarker(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');

        self::assertFileDoesNotExist($this->lockDir . '/cron-outbox.state');
        self::assertFileDoesNotExist($this->lockDir . '/cron-stock.state');
        self::assertFileExists($this->lockDir . '/cron-catalog.state');
    }

    public function testFirstRunAfterTheUpgradeWithoutMarkersTheDatabaseDecidesAsBefore(): void
    {
        $store = new CronStateStore($this->connection);
        // Tonight's catalog already failed twice; the customers already ran.
        $store->saveJob('catalog', ['last_attempt_at' => '2026-11-15T03:30:10+01:00', 'last_success_at' => '2026-11-14T03:00:10+01:00', 'last_error' => 'x', 'failed_for' => '2026-11-15T03:00:00+01:00', 'failed_attempts' => 2]);
        $store->saveJob('customers', ['last_attempt_at' => '2026-11-15T03:30:10+01:00', 'last_success_at' => '2026-11-15T03:30:10+01:00', 'last_error' => null]);
        $store->saveJob('backup', ['last_attempt_at' => '2026-11-14T04:00:10+01:00', 'last_success_at' => '2026-11-14T04:00:10+01:00', 'last_error' => null]);

        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 03:59:10', ['catalog' => 1]));
        self::assertSame(['outbox', 'stock', 'catalog', 'backup'], $this->launchedAt('2026-11-15 04:00:10', ['catalog' => 1]), 'third try');
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 04:30:10', ['catalog' => 1]), 'no fourth try');
    }
}
