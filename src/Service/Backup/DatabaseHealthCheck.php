<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Backup;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;

/**
 * CHECK TABLE on every base table, then the fiscal chain verification. A row of
 * type "error", or a status other than OK, is a problem; notes and warnings (for
 * example an engine that cannot be checked) are not. A database without the
 * fiscal_record table simply has no chain to verify.
 */
final class DatabaseHealthCheck implements HealthCheck
{
    private const HEALTHY_STATUS = ['ok', 'table is already up to date'];

    public function __construct(
        private readonly Connection $connection,
        private readonly FiscalChainVerifier $fiscalChain,
    ) {
    }

    public function run(): HealthReport
    {
        $tables = [];
        foreach ($this->connection->fetchAllNumeric("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $tables[] = (string) $row[0];
        }
        sort($tables, SORT_STRING);

        $problems = [];
        foreach ($tables as $table) {
            foreach ($this->connection->fetchAllAssociative('CHECK TABLE `' . str_replace('`', '``', $table) . '`') as $row) {
                $type = strtolower((string) ($row['Msg_type'] ?? ''));
                $text = trim((string) ($row['Msg_text'] ?? ''));
                if ($type === 'error' || ($type === 'status' && !\in_array(strtolower($text), self::HEALTHY_STATUS, true))) {
                    $problems[] = sprintf('Table %s: %s', $table, $text);
                }
            }
        }

        $fiscalRecords = null;
        if (\in_array('fiscal_record', $tables, true)) {
            $chain = $this->fiscalChain->verify();
            if ($chain['ok']) {
                $fiscalRecords = $chain['records'];
            } else {
                $problems[] = $chain['broken_id'] === null
                    ? sprintf('Fiscal chain broken: %s', $chain['reason'])
                    : sprintf('Fiscal chain broken at record %d: %s', $chain['broken_id'], $chain['reason']);
            }
        }

        return new HealthReport(\count($tables), $fiscalRecords, $problems);
    }
}
