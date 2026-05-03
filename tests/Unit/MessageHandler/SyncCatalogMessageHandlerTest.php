<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\MessageHandler;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use VenPosTPV\Message\SyncCatalogMessage;
use VenPosTPV\MessageHandler\SyncCatalogMessageHandler;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;

final class SyncCatalogMessageHandlerTest extends TestCase
{
    public function testHandlerCallsSyncAllAndLogsCounts(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->expects($this->once())
            ->method('syncAll')
            ->willReturn([
                'products' => 5,
                'combinations' => 3,
                'stock' => 10,
                'categories' => 2,
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Catalog sync completed: {products} products, {combinations} combinations, {stock} stock, {categories} categories',
                [
                    'products' => 5,
                    'combinations' => 3,
                    'stock' => 10,
                    'categories' => 2,
                ]
            );

        $handler = new SyncCatalogMessageHandler($syncService, $logger);
        $handler(new SyncCatalogMessage());
    }

    public function testHandlerRethrowsPsApiException(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->method('syncAll')->willThrowException(
            PsApiException::httpError(401, 'Invalid credentials')
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new SyncCatalogMessageHandler($syncService, $logger);

        $this->expectException(PsApiException::class);
        $handler(new SyncCatalogMessage());
    }

    public function testHandlerRethrowsCatalogSyncException(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->method('syncAll')->willThrowException(
            CatalogSyncException::fromPrevious(new \RuntimeException('DB error'))
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new SyncCatalogMessageHandler($syncService, $logger);

        $this->expectException(CatalogSyncException::class);
        $handler(new SyncCatalogMessage());
    }
}
