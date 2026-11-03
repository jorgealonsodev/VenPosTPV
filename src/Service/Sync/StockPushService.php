<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Sync;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;
use VenPosTPV\Service\Stock\PendingUploadStock;

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
     * The value sent is what PrestaShop should show BEFORE the operations still in
     * the outbox are uploaded: a pending sale is already off the local stock and its
     * order upload takes it off PrestaShop, so it is added back here (and a pending
     * restock taken off), or the sale would count twice (T8).
     *
     * @return int number of records successfully pushed
     */
    public function pushPending(): int
    {
        // One snapshot for both reads: a checkout committing in between is in both or in neither.
        $this->connection->beginTransaction();
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id_product, id_product_attribute, quantity, ps_stock_id FROM catalog_stock WHERE ps_stock_id IS NOT NULL'
            );
            $owed = (new PendingUploadStock($this->connection))->owedByPendingUploads();
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        $pushed = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $psStockId = (int) $row['ps_stock_id'];
            $qty = (int) $row['quantity'] + ($owed[PendingUploadStock::key((int) $row['id_product'], (int) $row['id_product_attribute'])] ?? 0);

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
