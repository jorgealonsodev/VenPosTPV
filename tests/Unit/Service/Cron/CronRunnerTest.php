<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cron\CronJob;
use VenPosTPV\Service\Cron\CronJobOutcome;
use VenPosTPV\Service\Cron\CronLock;
use VenPosTPV\Service\Cron\CronRunner;
use VenPosTPV\Service\Cron\CronStateStore;

/**
 * The single scheduled task (odd/tasks/single-cron-task.md): every minute it
 * launches what is due at that moment.
 */
final class CronRunnerTest extends TestCase
{
    private Connection $connection;
    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/venpos-cron-' . bin2hex(random_bytes(6));
        mkdir($this->lockDir, 0700);
        // A file, not ':memory:': the runner closes the connection between jobs
        // (it reconnects on next use), which would drop an in-memory database.
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

    private function runner(): CronRunner
    {
        return new CronRunner(new CronStateStore($this->connection), new CronLock($this->lockDir), 'Europe/Madrid');
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Madrid'));
    }

    /**
     * Runs the task at $time and returns the ids of the jobs it launched.
     *
     * @param array<string, int|\Throwable> $results exit code (or error) per job id; 0 when absent
     *
     * @return list<string>
     */
    private function launchedAt(string $time, array $results = []): array
    {
        $launched = [];
        $this->runner()->run(static function (CronJob $job) use (&$launched, $results): CronJobOutcome {
            $launched[] = $job->id;
            $result = $results[$job->id] ?? 0;
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return new CronJobOutcome($result, $result === 0 ? 'ok' : "line one\nSync failed: shop unreachable");
        }, self::at($time));

        return $launched;
    }

    public function testTheJobsAreTheFiveExistingCommandsWithTheirArguments(): void
    {
        $jobs = [];
        foreach (CronRunner::jobs() as $job) {
            $jobs[$job->id] = [$job->command, $job->arguments, $job->dailyAt];
        }

        self::assertSame([
            'outbox' => ['tpv:sync:outbox', [], null],
            'stock' => ['tpv:catalog:sync', ['--stock-only' => true], null],
            'catalog' => ['tpv:catalog:sync', ['--full' => true], '03:00'],
            'customers' => ['tpv:customers:sync', [], '03:30'],
            'backup' => ['tpv:backup:database', [], '04:00'],
        ], $jobs);
    }

    public function testANewInstallRunsEverythingOnItsFirstMinute(): void
    {
        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $this->launchedAt('2026-11-14 12:00:10'));
    }

    public function testUploadsAndStockRunEveryMinuteAndTheNightlyJobsOnceANight(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');

        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-14 12:01:10'));
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 02:59:10'), 'not before its hour');
        self::assertSame(['outbox', 'stock', 'catalog'], $this->launchedAt('2026-11-15 03:00:10'));
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 03:01:10'), 'once a night');
        self::assertSame(['outbox', 'stock', 'customers'], $this->launchedAt('2026-11-15 03:30:10'));
        self::assertSame(['outbox', 'stock', 'backup'], $this->launchedAt('2026-11-15 04:00:10'));
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 23:59:10'));
        self::assertSame(['outbox', 'stock', 'catalog'], $this->launchedAt('2026-11-16 03:00:10'), 'the next night again');
    }

    public function testANightlyJobMissedAtItsHourRunsAtTheNextMinute(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');

        // The task was down from 02:00 to 09:00.
        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $this->launchedAt('2026-11-15 09:00:10'));
        self::assertSame(['outbox', 'stock'], $this->launchedAt('2026-11-15 09:01:10'));
    }

    public function testAFailedNightlyJobIsTriedAgainAfterHalfAnHourUpToThreeTimesANight(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        $failing = ['catalog' => 1];

        self::assertContains('catalog', $this->launchedAt('2026-11-15 03:00:10', $failing));
        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 03:01:10', $failing), 'not every minute');
        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 03:29:10', $failing));
        self::assertContains('catalog', $this->launchedAt('2026-11-15 03:30:10', $failing), 'second try');
        self::assertContains('catalog', $this->launchedAt('2026-11-15 04:00:10', $failing), 'third try');
        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 04:30:10', $failing), 'no fourth try that night');
        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 20:00:10', $failing));
        self::assertContains('catalog', $this->launchedAt('2026-11-16 03:00:10'), 'the next night it starts over');
        self::assertNotContains('catalog', $this->launchedAt('2026-11-16 03:30:10'), 'it worked: once a night');
    }

    public function testARetryThatWorksEndsTheRetries(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        $this->launchedAt('2026-11-15 03:00:10', ['catalog' => 1]);

        self::assertContains('catalog', $this->launchedAt('2026-11-15 03:30:10'));
        self::assertNotContains('catalog', $this->launchedAt('2026-11-15 04:00:10'));
    }

    public function testAJobThatThrowsCountsAsFailedAndTheOthersStillRun(): void
    {
        $launched = $this->launchedAt('2026-11-14 12:00:10', ['outbox' => new \RuntimeException('boom')]);

        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $launched);
        $status = $this->runner()->status(self::at('2026-11-14 12:00:20'));
        self::assertSame('boom', $this->jobStatus($status, 'outbox')['last_error']);
        self::assertNull($this->jobStatus($status, 'stock')['last_error']);
    }

    public function testAJobStillRunningFromAnEarlierMinuteIsSkippedAndTheOthersRun(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        // Another process holds the catalog job (a full sync takes minutes).
        $held = fopen($this->lockDir . '/cron-catalog.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            $report = null;
            $launched = [];
            $report = $this->runner()->run(static function (CronJob $job) use (&$launched): CronJobOutcome {
                $launched[] = $job->id;

                return new CronJobOutcome(0, 'ok');
            }, self::at('2026-11-15 03:00:10'));

            self::assertSame(['outbox', 'stock'], $launched);
            self::assertSame(['catalog'], $report->skipped());
            self::assertFalse($report->failed(), 'a skipped job is not a failure');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        // Released: the job was not counted as done nor as an attempt, so it runs now.
        self::assertSame(['outbox', 'stock', 'catalog'], $this->launchedAt('2026-11-15 03:01:10'));
    }

    public function testTheLockIsFreeAgainAfterEachJob(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');

        $lock = new CronLock($this->lockDir);
        foreach (['outbox', 'stock', 'catalog', 'customers', 'backup'] as $id) {
            self::assertTrue($lock->acquire('cron-' . $id), $id);
            $lock->release('cron-' . $id);
        }
    }

    public function testTheReportSaysWhatRanAndWhetherSomethingFailed(): void
    {
        $report = $this->runner()->run(
            static fn (CronJob $job): CronJobOutcome => new CronJobOutcome($job->id === 'stock' ? 1 : 0, ''),
            self::at('2026-11-14 12:00:10')
        );

        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $report->launched());
        self::assertSame(['stock'], $report->failures());
        self::assertTrue($report->failed());
    }

    public function testTheStatusTellsWhetherTheTaskIsRunningAndEachJobsLastResult(): void
    {
        $never = $this->runner()->status(self::at('2026-11-14 12:00:00'));
        self::assertNull($never['last_run_at']);
        self::assertFalse($never['running']);
        self::assertCount(5, $never['jobs']);
        self::assertNull($this->jobStatus($never, 'catalog')['last_success_at']);

        $this->launchedAt('2026-11-14 12:00:10', ['catalog' => 1]);

        $now = $this->runner()->status(self::at('2026-11-14 12:02:00'));
        self::assertSame('2026-11-14T12:00:10+01:00', $now['last_run_at']);
        self::assertTrue($now['running']);
        self::assertSame('2026-11-14T12:00:10+01:00', $this->jobStatus($now, 'stock')['last_success_at']);
        self::assertNull($this->jobStatus($now, 'stock')['last_error']);
        $catalog = $this->jobStatus($now, 'catalog');
        self::assertNull($catalog['last_success_at']);
        self::assertSame('Sync failed: shop unreachable', $catalog['last_error'], 'the last line the command printed');
        self::assertSame('03:00', $catalog['daily_at']);
        self::assertNull($this->jobStatus($now, 'stock')['daily_at']);

        $later = $this->runner()->status(self::at('2026-11-14 12:10:11'));
        self::assertFalse($later['running'], 'ten minutes without a run: the task is not running');
        self::assertSame('2026-11-14T12:00:10+01:00', $later['last_run_at']);
    }

    public function testASuccessClearsTheErrorOfTheJob(): void
    {
        $this->launchedAt('2026-11-14 12:00:10', ['stock' => 1]);
        $this->launchedAt('2026-11-14 12:01:10');

        $status = $this->runner()->status(self::at('2026-11-14 12:01:20'));
        self::assertNull($this->jobStatus($status, 'stock')['last_error']);
        self::assertSame('2026-11-14T12:01:10+01:00', $this->jobStatus($status, 'stock')['last_success_at']);
    }

    public function testWithoutTheSettingsTableNothingIsRememberedAndNothingFails(): void
    {
        $this->connection->executeStatement('DROP TABLE app_setting');

        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $this->launchedAt('2026-11-14 12:00:10'));
        self::assertNull($this->runner()->status(self::at('2026-11-14 12:00:20'))['last_run_at']);
    }

    public function testAStateThatCannotBeSavedNeverStopsTheRun(): void
    {
        $launched = [];
        $report = $this->runner()->run(function (CronJob $job) use (&$launched): CronJobOutcome {
            $launched[] = $job->id;
            if ($job->id === 'catalog') {
                // From now on every state write fails (like a database that went away).
                $this->connection->executeStatement("CREATE TRIGGER no_writes BEFORE INSERT ON app_setting BEGIN SELECT RAISE(ABORT, 'MySQL server has gone away'); END");

                return new CronJobOutcome(1, 'PrestaShop API error: timeout');
            }

            return new CronJobOutcome(0, 'ok');
        }, self::at('2026-11-14 03:00:00'));

        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $launched);
        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $report->launched());
        self::assertSame(['catalog'], $report->failures());
        self::assertSame(['catalog' => 'PrestaShop API error: timeout'], $report->errors());
    }

    public function testAConnectionLeftUnusableByAJobIsReplacedBeforeItsStateIsSaved(): void
    {
        $this->runner()->run(function (CronJob $job): CronJobOutcome {
            if ($job->id === 'catalog') {
                // This connection can no longer write (as after minutes idle on MySQL).
                $this->connection->executeStatement('PRAGMA query_only = 1');

                return new CronJobOutcome(1, 'PrestaShop API error: timeout');
            }

            return new CronJobOutcome(0, 'ok');
        }, self::at('2026-11-14 03:00:00'));

        $status = $this->runner()->status(self::at('2026-11-14 03:00:30'));
        self::assertSame('PrestaShop API error: timeout', $this->jobStatus($status, 'catalog')['last_error'], 'the attempt is counted');
        self::assertSame('2026-11-14T03:00:00+01:00', $this->jobStatus($status, 'backup')['last_success_at']);
    }

    public function testEveryJobStartsOnAFreshDatabaseConnection(): void
    {
        $connected = [];
        $this->runner()->run(function (CronJob $job) use (&$connected): CronJobOutcome {
            $connected[$job->id] = $this->connection->isConnected();

            return new CronJobOutcome(0, 'ok');
        }, self::at('2026-11-14 12:00:10'));

        self::assertSame(['outbox' => false, 'stock' => false, 'catalog' => false, 'customers' => false, 'backup' => false], $connected);
    }

    public function testAJobsStateIsSavedBeforeItsLockIsReleased(): void
    {
        $lockDir = $this->lockDir;
        $lockHeldWhileSaving = new \ArrayObject();
        $store = new class($this->connection, $lockDir, $lockHeldWhileSaving) extends CronStateStore {
            /** @param \ArrayObject<string, bool> $held */
            public function __construct(Connection $connection, private readonly string $lockDir, private readonly \ArrayObject $held)
            {
                parent::__construct($connection);
            }

            public function saveJob(string $id, array $data): void
            {
                $probe = new CronLock($this->lockDir);
                $free = $probe->acquire('cron-' . $id);
                $probe->release('cron-' . $id);
                $this->held[$id] = !$free;
                parent::saveJob($id, $data);
            }
        };

        (new CronRunner($store, new CronLock($lockDir), 'Europe/Madrid'))->run(
            static fn (CronJob $job): CronJobOutcome => new CronJobOutcome(0, 'ok'),
            self::at('2026-11-14 12:00:10')
        );

        self::assertSame(['outbox' => true, 'stock' => true, 'catalog' => true, 'customers' => true, 'backup' => true], $lockHeldWhileSaving->getArrayCopy());
    }

    public function testASuccessDatedInTheFutureDoesNotKeepANightlyJobFromRunning(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        $store = new CronStateStore($this->connection);
        $store->saveJob('catalog', ['last_attempt_at' => '2027-06-01T03:00:00+02:00', 'last_success_at' => '2027-06-01T03:00:00+02:00', 'last_error' => null]);

        self::assertContains('catalog', $this->launchedAt('2026-11-15 03:00:10'));
    }

    public function testAnAttemptDatedInTheFutureDoesNotPostponeTheRetry(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        $this->launchedAt('2026-11-15 03:00:10', ['catalog' => 1]);
        $store = new CronStateStore($this->connection);
        $state = $store->job('catalog');
        $state['last_attempt_at'] = '2027-06-01T03:00:00+02:00';
        $store->saveJob('catalog', $state);

        self::assertContains('catalog', $this->launchedAt('2026-11-15 03:30:10'));
    }

    public function testAHeartbeatInTheFutureIsNotReportedAsRunning(): void
    {
        (new CronStateStore($this->connection))->recordRun(self::at('2027-06-01 03:00:00'));

        self::assertFalse($this->runner()->status(self::at('2026-11-14 12:00:00'))['running']);
    }

    public function testWhileANightlyJobRunsNoOtherNightlyJobStartsButTheMinuteJobsDo(): void
    {
        // Another process is inside a nightly job (the full catalog takes minutes).
        $held = fopen($this->lockDir . '/cron-nightly.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            $launched = [];
            $report = $this->runner()->run(static function (CronJob $job) use (&$launched): CronJobOutcome {
                $launched[] = $job->id;

                return new CronJobOutcome(0, 'ok');
            }, self::at('2026-11-14 12:00:10'));

            self::assertSame(['outbox', 'stock'], $launched);
            self::assertSame(['catalog', 'customers', 'backup'], $report->skipped());
            self::assertFalse($report->failed(), 'a skipped job is not a failure');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        // Not counted as attempts: they run as soon as the nightly lock is free.
        self::assertSame(['outbox', 'stock', 'catalog', 'customers', 'backup'], $this->launchedAt('2026-11-14 12:01:10'));
    }

    public function testTheMinuteJobsDoNotTakeTheNightlyLock(): void
    {
        $this->launchedAt('2026-11-14 12:00:10');
        $nightlyFree = [];
        $this->runner()->run(function (CronJob $job) use (&$nightlyFree): CronJobOutcome {
            $probe = new CronLock($this->lockDir);
            $nightlyFree[$job->id] = $probe->acquire('cron-nightly');
            $probe->release('cron-nightly');

            return new CronJobOutcome(0, 'ok');
        }, self::at('2026-11-15 03:00:10'));

        self::assertSame(['outbox' => true, 'stock' => true, 'catalog' => false], $nightlyFree);
    }

    /**
     * @param array{jobs: list<array<string, mixed>>} $status
     *
     * @return array<string, mixed>
     */
    private function jobStatus(array $status, string $id): array
    {
        foreach ($status['jobs'] as $job) {
            if ($job['id'] === $id) {
                return $job;
            }
        }
        self::fail("job {$id} is not in the status");
    }
}
