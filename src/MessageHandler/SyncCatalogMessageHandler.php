<?php

declare(strict_types=1);

namespace VenPosTPV\MessageHandler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use VenPosTPV\Message\SyncCatalogMessage;
use VenPosTPV\Service\Catalog\CatalogSyncService;
use VenPosTPV\Service\Catalog\Exception\CatalogSyncException;
use VenPosTPV\Service\PrestaShop\Exception\PsApiException;

#[AsMessageHandler]
final class SyncCatalogMessageHandler
{
    public function __construct(
        private readonly CatalogSyncService $catalogSyncService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncCatalogMessage $message): void
    {
        try {
            $counts = $this->catalogSyncService->syncAll();

            $this->logger->info(
                'Catalog sync completed: {products} products, {combinations} combinations, {stock} stock, {categories} categories',
                [
                    'products' => $counts['products'],
                    'combinations' => $counts['combinations'],
                    'stock' => $counts['stock'],
                    'categories' => $counts['categories'],
                ]
            );
        } catch (PsApiException $e) {
            $this->logger->error('Catalog sync failed due to PrestaShop API error: {message}', ['message' => $e->getMessage()]);
            throw $e;
        } catch (CatalogSyncException $e) {
            $this->logger->error('Catalog sync failed: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
