<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\PrintAgent;

use Doctrine\DBAL\Connection;

/**
 * Tills of their own shop for the print agent tests, removed with everything they
 * created (jobs, agents, codes, drawer audit rows, rate limit windows).
 */
trait PrintAgentFixtures
{
    private const FIXTURE_SHOP = 9371;

    private function till(Connection $connection, string $name, string $printerType = 'agent'): int
    {
        $config = $printerType === 'agent' ? ['enabled' => true, 'type' => 'agent'] : ['enabled' => false, 'type' => $printerType];
        $connection->insert('terminal', [
            'ps_shop_id' => self::FIXTURE_SHOP,
            'name' => $name,
            'printer_type' => $printerType,
            'printer_config' => json_encode($config, JSON_THROW_ON_ERROR),
            'active' => 1,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function cleanPrintAgentFixtures(Connection $connection): void
    {
        $tills = array_map('intval', $connection->fetchFirstColumn('SELECT id FROM terminal WHERE ps_shop_id = ?', [self::FIXTURE_SHOP]));
        if ($tills !== []) {
            $in = implode(',', $tills);
            $connection->executeStatement("DELETE FROM print_job WHERE terminal_id IN ($in)");
            $connection->executeStatement("DELETE FROM drawer_audit WHERE terminal_id IN ($in)");
            $connection->executeStatement("DELETE FROM agent_pairing WHERE terminal_id IN ($in)");
            $connection->executeStatement("DELETE FROM print_agent WHERE terminal_id IN ($in)");
            $connection->executeStatement("DELETE FROM audit_log WHERE terminal_id IN ($in)");
        }
        $connection->executeStatement('DELETE FROM rate_limit');
        $connection->executeStatement('DELETE FROM terminal WHERE ps_shop_id = ?', [self::FIXTURE_SHOP]);
    }
}
