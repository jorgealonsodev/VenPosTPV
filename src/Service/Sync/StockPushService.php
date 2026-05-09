<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;

/**
 * Pushes local catalog_stock quantities to PrestaShop (TPV → PS direction).
 * Used for periodic retry of failed outbound pushes.
 */
class StockPushService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PrestaShopApiClient $psApiClient,
        private readonly SyncLogRepository $syncLogRepo,
    ) {
    }

    /**
     * Push ALL local stock quantities to PrestaShop.
     * Called on manual sync or periodic retry timer.
     *
     * @return int number of records successfully pushed
     */
    public function pushPending(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id_product, id_product_attribute, quantity, ps_stock_id FROM catalog_stock WHERE ps_stock_id IS NOT NULL'
        );

        $pushed = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $psStockId = (int) $row['ps_stock_id'];
            $qty = (int) $row['quantity'];

            try {
                $this->psApiClient->updateStockAvailable($psStockId, $qty);
                $pushed++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        if ($pushed > 0) {
            $this->syncLogRepo->upsert('stock', 'outbound', new \DateTimeImmutable(), $pushed);
        }

        return $pushed;
    }
}
