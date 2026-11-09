<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Provisioning;

use Doctrine\DBAL\Connection;

/**
 * A MySQL named lock (GET_LOCK) that never waits. MySQL frees it when the
 * connection ends, so a run that dies half way never blocks the next one.
 */
class DatabaseLock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function acquire(string $name): bool
    {
        return (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$name]) === 1;
    }

    public function release(string $name): void
    {
        $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
    }
}
