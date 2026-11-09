<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\ProvisionCommand;
use VenPosTPV\Service\Provisioning\ProvisioningReport;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;
use VenPosTPV\Service\Provisioning\StepStatus;

final class ProvisionCommandTest extends TestCase
{
    private static function report(bool $shopDone): ProvisioningReport
    {
        return new ProvisioningReport([
            new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::DONE),
            $shopDone
                ? new StepStatus('free-item-products', 'Productos genéricos del artículo libre en PrestaShop', StepStatus::DONE, attempted: true)
                : new StepStatus('free-item-products', 'Productos genéricos del artículo libre en PrestaShop', StepStatus::FAILED, 'Sin conexión con PrestaShop.', new \DateTimeImmutable('2026-11-09 10:00'), true),
        ]);
    }

    public function testStatusOnlyReportsAndRunsNothing(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->expects($this->never())->method('run');
        $runner->expects($this->once())->method('status')->willReturn(self::report(false));
        $tester = new CommandTester(new ProvisionCommand($runner));

        $this->assertSame(Command::FAILURE, $tester->execute(['--status' => true]));
        $this->assertSame(
            "done     migrations          Actualizar la base de datos (migraciones)\n"
            . "failed   free-item-products  Productos genéricos del artículo libre en PrestaShop\n"
            . "         Sin conexión con PrestaShop.\n"
            . "1 provisioning step pending.\n",
            $tester->getDisplay()
        );
    }

    public function testStatusExitsZeroWhenEverythingIsDone(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->method('status')->willReturn(self::report(true));
        $tester = new CommandTester(new ProvisionCommand($runner));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--status' => true]));
        $this->assertStringEndsWith("All provisioning steps are done.\n", $tester->getDisplay());
    }

    public function testARunRunsThePendingStepsWithoutWaiting(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->expects($this->never())->method('status');
        $runner->expects($this->once())->method('run')->with(false)->willReturn(self::report(true));
        $tester = new CommandTester(new ProvisionCommand($runner));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('done     free-item-products  Productos genéricos del artículo libre en PrestaShop', $tester->getDisplay());
    }

    public function testARunThatLeavesAStepPendingExitsOne(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->method('run')->willReturn(self::report(false));
        $tester = new CommandTester(new ProvisionCommand($runner));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Sin conexión con PrestaShop.', $tester->getDisplay());
    }

    public function testABlockedStepIsShownAsBlockedWithItsReason(): void
    {
        $runner = $this->createMock(ProvisioningRunner::class);
        $runner->method('status')->willReturn(new ProvisioningReport([
            new StepStatus('migrations', 'Actualizar la base de datos (migraciones)', StepStatus::FAILED, 'Las migraciones han fallado: 1060 Duplicate column name. Revisa la base de datos.', new \DateTimeImmutable('2026-11-09 10:00'), blocked: true),
        ]));
        $tester = new CommandTester(new ProvisionCommand($runner));

        $this->assertSame(Command::FAILURE, $tester->execute(['--status' => true]));
        $this->assertSame(
            "blocked  migrations          Actualizar la base de datos (migraciones)\n"
            . "         Las migraciones han fallado: 1060 Duplicate column name. Revisa la base de datos.\n"
            . "1 provisioning step pending.\n",
            $tester->getDisplay()
        );
    }
}
