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
use VenPosTPV\Service\Sync\SyncLogRepository;

final class SyncCatalogMessageHandlerTest extends TestCase
{
    private function createHandler(
        CatalogSyncService $syncService,
        LoggerInterface $logger,
        ?SyncLogRepository $syncLogRepository = null,
    ): SyncCatalogMessageHandler {
        return new SyncCatalogMessageHandler(
            $syncService,
            $logger,
            $syncLogRepository ?? $this->createMock(SyncLogRepository::class),
        );
    }

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

        $handler = $this->createHandler($syncService, $logger);
        $handler(new SyncCatalogMessage());
    }

    public function testHandlerCallsSyncStockWhenStockOnly(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->expects($this->once())
            ->method('syncStock')
            ->willReturn(['stock' => 42]);

        $syncService->expects($this->never())->method('syncAll');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Stock sync completed: {stock} stock rows',
                ['stock' => 42]
            );

        $handler = $this->createHandler($syncService, $logger);
        $handler(new SyncCatalogMessage(stockOnly: true));
    }

    public function testHandlerUpsertsStockSyncLogOnStockOnlySuccess(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->method('syncStock')->willReturn(['stock' => 42]);

        $logger = $this->createMock(LoggerInterface::class);

        $syncLogRepository = $this->createMock(SyncLogRepository::class);
        $syncLogRepository->expects($this->once())
            ->method('upsert')
            ->with(
                'stock',
                'inbound',
                $this->isInstanceOf(\DateTimeInterface::class),
                $this->logicalOr($this->isNull(), $this->isType('int'))
            );

        $handler = $this->createHandler($syncService, $logger, $syncLogRepository);
        $handler(new SyncCatalogMessage(stockOnly: true));
    }

    public function testHandlerUpsertsSyncLogOnSuccess(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->method('syncAll')->willReturn([
            'products' => 5,
            'combinations' => 3,
            'stock' => 10,
            'categories' => 2,
        ]);

        $logger = $this->createMock(LoggerInterface::class);

        $syncLogRepository = $this->createMock(SyncLogRepository::class);
        $syncLogRepository->expects($this->once())
            ->method('upsert')
            ->with(
                'catalog',
                'inbound',
                $this->isInstanceOf(\DateTimeInterface::class),
                $this->logicalOr($this->isNull(), $this->isType('int'))
            );

        $handler = $this->createHandler($syncService, $logger, $syncLogRepository);
        $handler(new SyncCatalogMessage());
    }

    public function testHandlerDoesNotUpsertSyncLogOnPsApiException(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->method('syncAll')->willThrowException(
            PsApiException::httpError(401, 'Invalid credentials')
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $syncLogRepository = $this->createMock(SyncLogRepository::class);
        $syncLogRepository->expects($this->never())->method('upsert');

        $handler = $this->createHandler($syncService, $logger, $syncLogRepository);

        $this->expectException(PsApiException::class);
        $handler(new SyncCatalogMessage());
    }

    public function testHandlerDoesNotUpsertSyncLogOnCatalogSyncException(): void
    {
        $syncService = $this->createMock(CatalogSyncService::class);
        $syncService->method('syncAll')->willThrowException(
            CatalogSyncException::fromPrevious(new \RuntimeException('DB error'))
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $syncLogRepository = $this->createMock(SyncLogRepository::class);
        $syncLogRepository->expects($this->never())->method('upsert');

        $handler = $this->createHandler($syncService, $logger, $syncLogRepository);

        $this->expectException(CatalogSyncException::class);
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

        $handler = $this->createHandler($syncService, $logger);

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

        $handler = $this->createHandler($syncService, $logger);

        $this->expectException(CatalogSyncException::class);
        $handler(new SyncCatalogMessage());
    }
}
