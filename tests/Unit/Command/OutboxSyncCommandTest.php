<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\OutboxSyncCommand;
use VenPosTPV\Service\PrintAgent\PrintRetention;
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
}
