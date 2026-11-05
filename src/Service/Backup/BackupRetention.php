<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Backup;

/**
 * Keeps only the newest database backups in the backup folder. Only files named
 * exactly like the backup command names them are ever considered: anything else in
 * the folder (an .htaccess, a copy renamed by hand, a temporary file) is left alone.
 * The name holds the timestamp, so sorting by it is sorting by age.
 */
class BackupRetention
{
    /** Backups marked for review (-REVISAR) count as backups too. */
    public const PATTERN = '/\Avenpostpv-(\d{8}-\d{6})(?:-REVISAR)?\.sql\.gz\z/D';

    /** @return list<string> the names of the deleted backups, oldest first */
    public function prune(string $directory, int $keep): array
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('The number of backups to keep must be at least 1.');
        }

        $stamps = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match(self::PATTERN, $name, $match) === 1 && is_file($directory . '/' . $name)) {
                $stamps[$name] = $match[1];
            }
        }
        uksort($stamps, static fn (string $a, string $b): int => [$stamps[$a], $a] <=> [$stamps[$b], $b]);
        $backups = array_keys($stamps);

        $deleted = [];
        foreach (\array_slice($backups, 0, max(0, \count($backups) - $keep)) as $name) {
            if (!unlink($directory . '/' . $name)) {
                throw new \RuntimeException(sprintf('Cannot delete the old backup %s.', $name));
            }
            $deleted[] = $name;
        }

        return $deleted;
    }
}
