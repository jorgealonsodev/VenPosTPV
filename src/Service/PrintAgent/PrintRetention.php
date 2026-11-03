<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * PRD S4, run by the minute task (tpv:sync:outbox): the data of each job (it may
 * carry customer data) is removed 24 h after the job finished, and the job itself
 * after 30 days. Expired jobs are closed first so they count as finished. Old rate
 * limit windows and pairing codes go too. `drawer_audit` is never purged (T11).
 */
class PrintRetention
{
    public const PAYLOAD_HOURS = 24;
    public const HISTORY_DAYS = 30;
    private const BATCH = 1000;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Connection $connection,
        private readonly PrintJobQueue $jobs,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /** @return array{payloads: int, deleted: int} */
    public function run(): array
    {
        $now = $this->clock->now();
        $this->jobs->resolveDue();

        $payloads = (int) $this->connection->executeStatement(
            sprintf('UPDATE print_job SET payload = NULL WHERE payload IS NOT NULL AND finished_at IS NOT NULL AND finished_at <= ? LIMIT %d', self::BATCH),
            [$now->modify(sprintf('-%d hours', self::PAYLOAD_HOURS))->format('Y-m-d H:i:s')]
        );
        $deleted = (int) $this->connection->executeStatement(
            sprintf('DELETE FROM print_job WHERE created_at <= ? LIMIT %d', self::BATCH),
            [$now->modify(sprintf('-%d days', self::HISTORY_DAYS))->format('Y-m-d H:i:s')]
        );
        $this->connection->executeStatement(
            'DELETE FROM rate_limit WHERE window_start < ?',
            [$now->modify('-1 hour')->format('Y-m-d H:i:s')]
        );
        $this->connection->executeStatement(
            'DELETE FROM agent_pairing WHERE expires_at < ?',
            [$now->modify('-1 day')->format('Y-m-d H:i:s')]
        );

        return ['payloads' => $payloads, 'deleted' => $deleted];
    }
}
