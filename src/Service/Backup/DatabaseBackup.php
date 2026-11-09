<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Backup;

/**
 * Writes one gzip-compressed SQL dump of the TPV database to the backup folder and
 * then keeps only the newest ones (docs/despliegue-plesk.md, «Copias de seguridad»).
 *
 * The dump is written to a hidden temporary file in the same folder and renamed at
 * the end, so a failed run never leaves a half file with a backup's name, and the
 * retention only runs after a backup was completed.
 *
 * Before the dump the database is checked (HealthCheck). A failed check still
 * writes the backup, named ...-REVISAR.sql.gz, but skips the retention for that run:
 * the older copies may be the last good ones.
 *
 * runPreMigration() takes the copy the «migrations» provisioning step needs before
 * migrating (docs/provisioning.md): named venpostpv-premigrate-…, outside the daily
 * retention, so the copy from before a failed migration is never pushed out by the
 * daily ones; only the newest PREMIGRATE_KEEP of them (and, apart, of those marked
 * for review) are kept, so they cannot pile up either.
 *
 * The folder is kept at 0700 and the files at 0600: on Plesk, Apache and nginx run
 * as other users, so they cannot read them even if the web server configuration
 * served static files from this folder. The .htaccess is defence in depth.
 */
class DatabaseBackup
{
    public const DEFAULT_KEEP = 7;
    public const PREMIGRATE_KEEP = 3;

    public function __construct(
        private readonly SqlDumper $dumper,
        private readonly BackupRetention $retention,
        private readonly HealthCheck $healthCheck,
        private readonly string $directory,
    ) {
    }

    public function run(int $keep = self::DEFAULT_KEEP, ?\DateTimeImmutable $now = null): BackupResult
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('The number of backups to keep must be at least 1.');
        }

        return $this->backUp('venpostpv-', $now, function (HealthReport $health) use ($keep): void {
            if ($health->ok()) {
                $this->retention->prune($this->directory, $keep);
            }
        });
    }

    public function runPreMigration(?\DateTimeImmutable $now = null): BackupResult
    {
        return $this->backUp('venpostpv-premigrate-', $now, function (HealthReport $health): void {
            // A copy marked for review only ever replaces older ones marked for review.
            $this->retention->pruneMatching(
                $this->directory,
                self::PREMIGRATE_KEEP,
                $health->ok() ? BackupRetention::PREMIGRATE_PATTERN : BackupRetention::PREMIGRATE_REVIEW_PATTERN
            );
        });
    }

    /** @param \Closure(HealthReport): void $prune removes the old copies once this one is in place */
    private function backUp(string $prefix, ?\DateTimeImmutable $now, \Closure $prune): BackupResult
    {
        $this->prepareDirectory();
        $health = $this->check();
        $at = $now ?? new \DateTimeImmutable();
        // Never replace an existing copy (two runs in one second, a manual retry
        // right after a failure): the next free second keeps the name and its order.
        do {
            $name = sprintf('%s%s%s.sql.gz', $prefix, $at->format('Ymd-His'), $health->ok() ? '' : '-REVISAR');
            $final = $this->directory . '/' . $name;
            $at = $at->modify('+1 second');
        } while (file_exists($final));
        $temporary = sprintf('%s/.%s.%s.tmp', $this->directory, $name, bin2hex(random_bytes(4)));

        try {
            $tables = $this->write($temporary);
            if (!rename($temporary, $final)) {
                throw new \RuntimeException(sprintf('Cannot rename the backup to %s.', $name));
            }
        } catch (\Throwable $e) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $e;
        }

        clearstatcache(true, $final);
        // The copy is already in place: a failure to remove old copies (two runs at
        // once, a permission) must not report the backup itself as failed.
        $retentionError = null;
        try {
            $prune($health);
        } catch (\Throwable $e) {
            $retentionError = $e->getMessage();
        }

        return new BackupResult($final, (int) filesize($final), $tables, $health, $retentionError);
    }

    private function check(): HealthReport
    {
        try {
            return $this->healthCheck->run();
        } catch (\Throwable $e) {
            return new HealthReport(0, null, ['Database check failed: ' . $e->getMessage()]);
        }
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cannot create the backup folder %s.', $this->directory));
        }
        // mkdir applies the umask, and a folder unpacked from a zip has its own mode.
        if (!chmod($this->directory, 0700)) {
            throw new \RuntimeException(sprintf('Cannot make the backup folder %s private.', $this->directory));
        }

        $htaccess = $this->directory . '/.htaccess';
        if (!is_file($htaccess) && file_put_contents($htaccess, "Require all denied\n") === false) {
            throw new \RuntimeException('Cannot write the .htaccess of the backup folder.');
        }
    }

    private function write(string $path): int
    {
        if (!touch($path) || !chmod($path, 0600)) {
            throw new \RuntimeException('Cannot create the backup file in ' . $this->directory . '.');
        }

        $gzip = gzopen($path, 'wb6');
        if ($gzip === false) {
            throw new \RuntimeException('Cannot open the backup file for writing.');
        }

        try {
            $tables = $this->dumper->dump(static function (string $sql) use ($gzip): void {
                if ($sql !== '' && gzwrite($gzip, $sql) !== \strlen($sql)) {
                    throw new \RuntimeException('Cannot write the backup file (is the disk full?).');
                }
            });
        } finally {
            $closed = gzclose($gzip);
        }
        if (!$closed) {
            throw new \RuntimeException('Cannot finish the backup file (is the disk full?).');
        }

        return $tables;
    }
}
