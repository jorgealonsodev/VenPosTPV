<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cash;

use Doctrine\DBAL\Connection;

class TerminalService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function resolveForShop(int $shopId): int
    {
        $terminalId = $this->connection->fetchOne(
            'SELECT id FROM terminal WHERE ps_shop_id = ? AND active = 1 ORDER BY id ASC LIMIT 1',
            [$shopId]
        );

        if ($terminalId !== false) {
            return (int) $terminalId;
        }

        $this->connection->insert('terminal', [
            'ps_shop_id' => $shopId,
            'name' => sprintf('TPV Shop %d', $shopId),
            'active' => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->connection->lastInsertId();
    }
}
