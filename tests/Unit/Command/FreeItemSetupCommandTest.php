<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\FreeItemSetupCommand;
use VenPosTPV\Service\Checkout\FreeItemSetupService;

final class FreeItemSetupCommandTest extends TestCase
{
    private function tester(FreeItemSetupService $service): CommandTester
    {
        $command = new FreeItemSetupCommand($service);
        $command->setApplication(new Application());

        return new CommandTester($command);
    }

    public function testItPrintsTheProductOfEachRateAndItsSlots(): void
    {
        $service = $this->createMock(FreeItemSetupService::class);
        $service->expects($this->once())->method('run')->willReturn([
            '21.00' => ['id_product' => 22, 'slots' => array_combine(range(1, 20), range(46, 65))],
            '10.00' => ['id_product' => 23, 'slots' => array_combine(range(1, 20), range(66, 85))],
        ]);
        $tester = $this->tester($service);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(
            "VAT 21.00 %: PrestaShop product 22, 20 slot combinations (46-65)\nVAT 10.00 %: PrestaShop product 23, 20 slot combinations (66-85)\n",
            $tester->getDisplay()
        );
    }

    public function testAFailureIsPrintedAndExitsOne(): void
    {
        $service = $this->createMock(FreeItemSetupService::class);
        $service->method('run')->willThrowException(new \RuntimeException('PS module returned HTTP 500: boom'));
        $tester = $this->tester($service);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame("Free item setup failed: PS module returned HTTP 500: boom\n", $tester->getDisplay());
    }
}
