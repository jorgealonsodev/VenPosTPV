<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use VenPosTPV\Command\CatalogSyncCommand;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;

final class CatalogSyncCommandTest extends TestCase
{
    private function createCommandTester(array $syncResult, ?\Throwable $throw = null): CommandTester
    {
        $service = $this->createMock(CatalogSyncService::class);

        if ($throw !== null) {
            $service->method('syncAll')->willThrowException($throw);
            $service->method('syncStock')->willThrowException($throw);
        } else {
            $service->method('syncAll')->willReturn($syncResult);
            $service->method('syncStock')->willReturn(['stock' => $syncResult['stock'] ?? 0]);
        }

        $command = new CatalogSyncCommand($service);
        $command->setApplication(new Application());

        return new CommandTester($command);
    }

    public function testFullSyncOutputsSummary(): void
    {
        $tester = $this->createCommandTester([
            'products' => 5,
            'combinations' => 10,
            'stock' => 15,
            'categories' => 3,
        ]);

        $tester->execute(['--full' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Catalog sync completed', $tester->getDisplay());
        $this->assertStringContainsString('Products', $tester->getDisplay());
        $this->assertStringContainsString('5', $tester->getDisplay());
    }

    public function testStockOnlySyncOutputsStockCount(): void
    {
        $tester = $this->createCommandTester(['stock' => 20]);

        $tester->execute(['--stock-only' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Stock', $tester->getDisplay());
        $this->assertStringContainsString('20', $tester->getDisplay());
    }

    public function testPsApiErrorReturnsExitCode1(): void
    {
        $tester = $this->createCommandTester([], PsApiException::httpError(401, 'Invalid credentials'));

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('PrestaShop API error', $tester->getDisplay());
    }

    public function testSyncExceptionReturnsExitCode1(): void
    {
        $tester = $this->createCommandTester([], CatalogSyncException::fromPrevious(new \RuntimeException('DB error')));

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Sync failed', $tester->getDisplay());
    }

    public function testBothOptionsFail(): void
    {
        $tester = $this->createCommandTester([]);

        $tester->execute(['--full' => true, '--stock-only' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Cannot use both', $tester->getDisplay());
    }
}
