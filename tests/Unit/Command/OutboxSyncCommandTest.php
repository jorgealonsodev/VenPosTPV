<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\OutboxSyncCommand;
use VenPosTPV\Service\PrintAgent\PrintRetention;
use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\StepStatus;
use VenPosTPV\Service\Sync\OutboxSync;

final class OutboxSyncCommandTest extends TestCase
{
    public function testRunsTheDueUploadsAndPrintsTheCounts(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->expects($this->once())->method('run')->with(100)->willReturn(['uploaded' => 3, 'retried' => 1, 'incidents' => 2, 'recovered' => 1]);
        $tester = new CommandTester(new OutboxSyncCommand($sync));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame("Outbox sync: 3 uploaded, 1 to retry, 2 incidents, 1 recovered\n", $tester->getDisplay());
    }

    public function testLimitOption(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->expects($this->once())->method('run')->with(5)->willReturn(['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);

        $this->assertSame(Command::SUCCESS, (new CommandTester(new OutboxSyncCommand($sync)))->execute(['--limit' => '5']));
    }

    public function testInvalidLimitRunsNothing(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->expects($this->never())->method('run');
        $tester = new CommandTester(new OutboxSyncCommand($sync));

        $this->assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        $this->assertStringContainsString('--limit must be a positive integer', $tester->getDisplay());
    }

    public function testDatabaseFailureIsAFailedRun(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->method('run')->willThrowException(new \RuntimeException('Connection refused'));
        $tester = new CommandTester(new OutboxSyncCommand($sync));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Outbox sync failed: Connection refused', $tester->getDisplay());
    }

    public function testThePrintHistoryIsCleanedByTheSameMinuteTaskWithoutChangingItsOutput(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->method('run')->willReturn(['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);
        $retention = $this->createMock(PrintRetention::class);
        $retention->expects($this->once())->method('run')->willReturn(['payloads' => 4, 'deleted' => 2]);
        $tester = new CommandTester(new OutboxSyncCommand($sync, $retention));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame("Outbox sync: 0 uploaded, 0 to retry, 0 incidents, 0 recovered\n", $tester->getDisplay());
    }

    public function testACleanupFailureNeverChangesTheUploadRun(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->expects($this->once())->method('run')->willReturn(['uploaded' => 1, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);
        $retention = $this->createMock(PrintRetention::class);
        $retention->method('run')->willThrowException(new \RuntimeException("Table 'print_job' doesn't exist"));
        $tester = new CommandTester(new OutboxSyncCommand($sync, $retention));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame("Outbox sync: 1 uploaded, 0 to retry, 0 incidents, 0 recovered\n", $tester->getDisplay());
    }

    public function testThePendingInstallationStepsRunThrottledBeforeTheUploads(): void
    {
        $log = [];
        $sync = $this->createMock(OutboxSync::class);
        $sync->method('run')->willReturnCallback(static function () use (&$log): array {
            $log[] = 'sync';

            return ['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0];
        });
        $provisioning = $this->createMock(ProvisioningRunner::class);
        $provisioning->expects($this->once())->method('run')->with(true)->willReturnCallback(static function () use (&$log): ProvisioningReport {
            $log[] = 'provision';

            return new ProvisioningReport([new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::DONE)]);
        });
        $tester = new CommandTester(new OutboxSyncCommand($sync, null, $provisioning));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(['provision', 'sync'], $log, 'migrations and setup go first, so the uploads use them');
        $this->assertSame("Outbox sync: 0 uploaded, 0 to retry, 0 incidents, 0 recovered\n", $tester->getDisplay(), 'nothing attempted, nothing printed');
    }

    public function testAnAttemptedInstallationStepIsPrintedWithoutChangingTheExitCode(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->method('run')->willReturn(['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);
        $provisioning = $this->createMock(ProvisioningRunner::class);
        $provisioning->method('run')->willReturn(new ProvisioningReport([
            new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::DONE, attempted: true),
            new StepStatus('free-item-products', 'Productos genéricos del artículo libre en PrestaShop', StepStatus::FAILED, 'Sin conexión con PrestaShop.', new \DateTimeImmutable(), true),
        ]));
        $tester = new CommandTester(new OutboxSyncCommand($sync, null, $provisioning));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(
            "Provisioning: migrations done\n"
            . "Provisioning: free-item-products failed: Sin conexión con PrestaShop.\n"
            . "Outbox sync: 0 uploaded, 0 to retry, 0 incidents, 0 recovered\n",
            $tester->getDisplay()
        );
    }

    public function testAProvisioningErrorNeverStopsTheUploads(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->expects($this->once())->method('run')->willReturn(['uploaded' => 1, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);
        $provisioning = $this->createMock(ProvisioningRunner::class);
        $provisioning->method('run')->willThrowException(new \RuntimeException('Connection refused'));
        $tester = new CommandTester(new OutboxSyncCommand($sync, null, $provisioning));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(
            "Provisioning failed: Connection refused\nOutbox sync: 1 uploaded, 0 to retry, 0 incidents, 0 recovered\n",
            $tester->getDisplay()
        );
    }

    public function testABlockedStepIsPrintedOnlyWhenTheRunnerReportsIt(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->method('run')->willReturn(['uploaded' => 0, 'retried' => 0, 'incidents' => 0, 'recovered' => 0]);
        $provisioning = $this->createMock(ProvisioningRunner::class);
        $provisioning->method('run')->willReturnOnConsecutiveCalls(
            new ProvisioningReport([new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::FAILED, 'Las migraciones han fallado.', new \DateTimeImmutable(), blocked: true, reported: true)]),
            new ProvisioningReport([new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::FAILED, 'Las migraciones han fallado.', new \DateTimeImmutable(), blocked: true)]),
        );
        $command = new OutboxSyncCommand($sync, null, $provisioning);

        $tester = new CommandTester($command);
        $tester->execute([]);
        $this->assertSame(
            "Provisioning: migrations blocked: Las migraciones han fallado.\n"
            . "Outbox sync: 0 uploaded, 0 to retry, 0 incidents, 0 recovered\n",
            $tester->getDisplay()
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
        $this->assertSame("Outbox sync: 0 uploaded, 0 to retry, 0 incidents, 0 recovered\n", $tester->getDisplay(), 'between reports it stays quiet');
    }
}
