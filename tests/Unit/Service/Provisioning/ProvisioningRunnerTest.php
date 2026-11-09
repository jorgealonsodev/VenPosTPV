<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Provisioning;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Provisioning\ProvisioningBusyException;
use VenPosTPV\Service\Provisioning\ProvisioningException;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\ProvisioningStateStore;
use VenPosTPV\Service\Provisioning\ProvisioningStep;

/**
 * The provisioning runner (docs/provisioning.md): every registered step is checked
 * in order, the pending ones are run, a failure is remembered with its reason and
 * never stops the next steps, and the minute task retries a failed step at most
 * once every 15 minutes.
 */
final class ProvisioningRunnerTest extends TestCase
{
    private Connection $connection;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE app_setting (name VARCHAR(100) PRIMARY KEY, value TEXT)');
    }

    private function runner(ProvisioningStep ...$steps): ProvisioningRunner
    {
        return new ProvisioningRunner($steps, new ProvisioningStateStore($this->connection));
    }

    /** @param \Closure(): void|null $run */
    private function step(string $id, bool $done = false, ?\Closure $run = null): FakeStep
    {
        return new FakeStep($id, 'Paso ' . $id, $done, $run, $this->log);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-09 ' . $time);
    }

    public function testThePendingStepsRunInTheirRegisteredOrderAndTheDoneOnesAreNotRun(): void
    {
        $report = $this->runner($this->step('a'), $this->step('b', done: true), $this->step('c'))->run();

        $this->assertSame(['check a', 'run a', 'check a', 'check b', 'check c', 'run c', 'check c'], $this->log);
        $this->assertSame(['a', 'b', 'c'], array_map(static fn ($s) => $s->id, $report->steps));
        $this->assertSame(['done', 'done', 'done'], array_map(static fn ($s) => $s->status, $report->steps));
        $this->assertSame([true, false, true], array_map(static fn ($s) => $s->attempted, $report->steps));
        $this->assertTrue($report->isComplete());
    }

    public function testRunningAgainChangesNothing(): void
    {
        $runner = $this->runner($this->step('a'), $this->step('b'));
        $runner->run();
        $this->log = [];

        $report = $runner->run();

        $this->assertSame(['check a', 'check b'], $this->log, 'done steps are only checked');
        $this->assertSame([], $report->attempted());
        $this->assertTrue($report->isComplete());
    }

    public function testAFailingStepDoesNotStopTheOthersAndItsReasonIsKept(): void
    {
        $failing = $this->step('shop', run: static function (): void {
            throw new ProvisioningException('El módulo TPV Connect no responde.');
        });

        $report = $this->runner($failing, $this->step('next'))->run(now: self::at('10:00'));

        $this->assertSame(['failed', 'done'], array_map(static fn ($s) => $s->status, $report->steps));
        $this->assertSame('El módulo TPV Connect no responde.', $report->steps[0]->reason);
        $this->assertEquals(self::at('10:00'), $report->steps[0]->attemptedAt);
        $this->assertFalse($report->isComplete());
        $this->assertSame(['shop'], array_map(static fn ($s) => $s->id, $report->pending()));

        $stored = json_decode((string) $this->connection->fetchOne("SELECT value FROM app_setting WHERE name = 'provisioning.shop'"), true);
        $this->assertSame('El módulo TPV Connect no responde.', $stored['reason']);
    }

    public function testTheMinuteTaskRetriesAFailedStepAtMostOnceEveryFifteenMinutes(): void
    {
        $failing = $this->step('shop', run: static function (): void {
            throw new ProvisioningException('Sin conexión con PrestaShop.');
        });
        $runner = $this->runner($failing);
        $runner->run(throttled: true, now: self::at('10:00'));
        $this->log = [];

        $report = $runner->run(throttled: true, now: self::at('10:14'));
        $this->assertSame(['check shop'], $this->log, 'not retried before 15 minutes');
        $this->assertSame('failed', $report->steps[0]->status);
        $this->assertFalse($report->steps[0]->attempted);
        $this->assertSame('Sin conexión con PrestaShop.', $report->steps[0]->reason);
        $this->assertEquals(self::at('10:15'), $report->steps[0]->nextAttemptAt);

        $this->log = [];
        $report = $runner->run(throttled: true, now: self::at('10:15'));
        $this->assertSame(['check shop', 'run shop'], $this->log);
        $this->assertTrue($report->steps[0]->attempted);
        $this->assertEquals(self::at('10:15'), $report->steps[0]->attemptedAt);
    }

    public function testAManualRunIgnoresTheWait(): void
    {
        $calls = 0;
        $failing = $this->step('shop', run: static function () use (&$calls): void {
            ++$calls;
            throw new ProvisioningException('Sin conexión con PrestaShop.');
        });
        $runner = $this->runner($failing);
        $runner->run(throttled: true, now: self::at('10:00'));
        $runner->run(now: self::at('10:01'));

        $this->assertSame(2, $calls);
    }

    public function testASuccessForgetsTheEarlierFailure(): void
    {
        $fail = true;
        $step = $this->step('shop', run: static function () use (&$fail): void {
            if ($fail) {
                throw new ProvisioningException('Sin conexión con PrestaShop.');
            }
        });
        $runner = $this->runner($step);
        $runner->run(now: self::at('10:00'));
        $fail = false;

        $report = $runner->run(now: self::at('10:30'));

        $this->assertSame('done', $report->steps[0]->status);
        $this->assertNull($report->steps[0]->reason);
        $this->assertFalse($this->connection->fetchOne("SELECT value FROM app_setting WHERE name = 'provisioning.shop'"));
    }

    public function testTheStatusReportRunsNothing(): void
    {
        $failing = $this->step('shop', run: static function (): void {
            throw new ProvisioningException('Sin conexión con PrestaShop.');
        });
        $this->runner($failing)->run(now: self::at('10:00'));
        $this->log = [];

        $report = $this->runner($this->step('db', done: true), $failing, $this->step('new'))->status(self::at('10:05'));

        $this->assertSame(['check db', 'check shop', 'check new'], $this->log);
        $this->assertSame(['done', 'failed', 'pending'], array_map(static fn ($s) => $s->status, $report->steps));
        $this->assertSame([null, 'Sin conexión con PrestaShop.', null], array_map(static fn ($s) => $s->reason, $report->steps));
        $this->assertSame('Paso shop', $report->steps[1]->description);

        $array = $report->toArray();
        $this->assertFalse($array['complete']);
        $this->assertSame([
            'id' => 'shop',
            'description' => 'Paso shop',
            'status' => 'failed',
            'reason' => 'Sin conexión con PrestaShop.',
            'attempted_at' => self::at('10:00')->format(\DATE_ATOM),
            'next_attempt_at' => self::at('10:15')->format(\DATE_ATOM),
        ], $array['steps'][1]);
    }

    public function testAStepAnotherRunIsDoingStaysPendingWithoutAFailure(): void
    {
        $busy = $this->step('db', run: static function (): void {
            throw new ProvisioningBusyException('Otra ejecución está actualizando la base de datos.');
        });

        $report = $this->runner($busy)->run(throttled: true, now: self::at('10:00'));

        $this->assertSame('pending', $report->steps[0]->status);
        $this->assertSame('Otra ejecución está actualizando la base de datos.', $report->steps[0]->reason);
        $this->assertFalse($this->connection->fetchOne("SELECT value FROM app_setting WHERE name = 'provisioning.db'"), 'not remembered, so not throttled');
    }

    public function testAnUnexpectedErrorBecomesTheReason(): void
    {
        $step = $this->step('shop', run: static function (): void {
            throw new \RuntimeException('boom');
        });

        $report = $this->runner($step)->run();

        $this->assertSame('failed', $report->steps[0]->status);
        $this->assertSame('Error inesperado: boom', $report->steps[0]->reason);
    }

    public function testAStepThatEndsWithoutBeingDoneIsAFailure(): void
    {
        $step = new FakeStep('shop', 'Paso shop', false, static function (): void {
        }, $this->log, staysPending: true);

        $report = $this->runner($step)->run();

        $this->assertSame('failed', $report->steps[0]->status);
        $this->assertSame('El paso ha terminado, pero sigue pendiente.', $report->steps[0]->reason);
    }

    public function testAStepThatCannotBeCheckedIsReportedAndNotRun(): void
    {
        $step = new FakeStep('db', 'Paso db', false, null, $this->log, checkError: new \RuntimeException('Connection refused'));

        $report = $this->runner($step, $this->step('next'))->run();

        $this->assertNotContains('run db', $this->log);
        $this->assertSame(['failed', 'done'], array_map(static fn ($s) => $s->status, $report->steps));
        $this->assertSame('No se ha podido comprobar: Connection refused', $report->steps[0]->reason);
    }

    public function testWithoutTheSettingsTableTheStepsStillRun(): void
    {
        $this->connection->executeStatement('DROP TABLE app_setting');
        $failing = $this->step('shop', run: static function (): void {
            throw new ProvisioningException('Sin conexión con PrestaShop.');
        });

        $report = $this->runner($failing, $this->step('next'))->run();

        $this->assertSame(['failed', 'done'], array_map(static fn ($s) => $s->status, $report->steps));
    }
}

final class FakeStep implements ProvisioningStep
{
    private bool $ran = false;

    /**
     * @param \Closure(): void|null $run
     * @param list<string>          $log
     */
    public function __construct(
        private readonly string $id,
        private readonly string $description,
        private readonly bool $done,
        private readonly ?\Closure $run,
        private array &$log,
        private readonly bool $staysPending = false,
        private readonly ?\Throwable $checkError = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function isDone(): bool
    {
        $this->log[] = 'check ' . $this->id;
        if ($this->checkError !== null) {
            throw $this->checkError;
        }

        return $this->done || ($this->ran && !$this->staysPending);
    }

    public function run(): void
    {
        $this->log[] = 'run ' . $this->id;
        if ($this->run !== null) {
            ($this->run)();
        }
        $this->ran = true;
    }
}
