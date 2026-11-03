<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Customer;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\PrestaShop\PrestaShopApiClient;

/**
 * Refreshes the till's copy of the PrestaShop customers (PRD C1), like the catalog
 * sync: the remote read happens before the write transaction, so a failed read
 * leaves the copy as it was. Rows are upserted by PrestaShop id; a customer
 * PrestaShop no longer returns is deactivated, never deleted, and customers created
 * at the till that are not uploaded yet (no PrestaShop id) are left alone.
 */
class CustomerSyncService
{
    public function __construct(
        private readonly PrestaShopApiClient $apiClient,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{customers: int, deactivated: int}
     */
    public function syncAll(): array
    {
        $customers = $this->apiClient->getCustomers();

        return $this->connection->transactional(function () use ($customers): array {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $seen = [];
            foreach ($customers as $customer) {
                $id = (int) ($customer['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $seen[] = $id;
                $email = trim((string) ($customer['email'] ?? ''));
                $this->connection->executeStatement(
                    'INSERT INTO customer (ps_customer_id, firstname, lastname, email, active, created_at, updated_at, synced_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE firstname = VALUES(firstname), lastname = VALUES(lastname), email = VALUES(email),
                         active = VALUES(active), updated_at = VALUES(updated_at), synced_at = VALUES(synced_at)',
                    [
                        $id,
                        mb_substr(trim((string) ($customer['firstname'] ?? '')), 0, 255),
                        mb_substr(trim((string) ($customer['lastname'] ?? '')), 0, 255),
                        $email !== '' ? mb_strtolower(mb_substr($email, 0, 255)) : null,
                        (int) ($customer['active'] ?? 1) === 1 ? 1 : 0,
                        $now,
                        $now,
                        $now,
                    ]
                );
            }

            $deactivated = 0;
            $known = $this->connection->fetchFirstColumn('SELECT ps_customer_id FROM customer WHERE ps_customer_id IS NOT NULL AND active = 1');
            $gone = array_values(array_diff(array_map('intval', $known), $seen));
            foreach (array_chunk($gone, 500) as $chunk) {
                $deactivated += (int) $this->connection->executeStatement(
                    'UPDATE customer SET active = 0, updated_at = ? WHERE ps_customer_id IN (' . implode(',', array_fill(0, \count($chunk), '?')) . ')',
                    [$now, ...$chunk]
                );
            }

            return ['customers' => \count($seen), 'deactivated' => $deactivated];
        });
    }
}
