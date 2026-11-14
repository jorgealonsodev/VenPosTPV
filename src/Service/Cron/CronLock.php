<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cron;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Non-blocking file locks (flock on <directory>/<name>.lock) so a long job never
 * overlaps with itself. Not a MySQL GET_LOCK: the catalog sync closes its database
 * connection half way, which would free a connection-bound lock.
 */
class CronLock
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var')] private readonly string $directory,
    ) {
    }

    /** Where the lock files live. */
    public function directory(): string
    {
        return $this->directory;
    }

    /** True when the lock is now held by this process; false when another holds it. Never waits. */
    public function acquire(string $name): bool
    {
        if (isset($this->handles[$name])) {
            return false;
        }
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $path = $this->directory . '/' . $name . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open the lock file {$path}.");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handles[$name] = $handle;

        return true;
    }

    public function release(string $name): void
    {
        $handle = $this->handles[$name] ?? null;
        if ($handle === null) {
            return;
        }
        unset($this->handles[$name]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
