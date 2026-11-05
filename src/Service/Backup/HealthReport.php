<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Backup;

final class HealthReport
{
    /**
     * @param ?int         $fiscalRecords records in an intact fiscal chain; null when there is no fiscal_record table
     * @param list<string> $problems      one readable line per problem found
     */
    public function __construct(
        public readonly int $tables,
        public readonly ?int $fiscalRecords,
        public readonly array $problems,
    ) {
    }

    public function ok(): bool
    {
        return $this->problems === [];
    }
}
