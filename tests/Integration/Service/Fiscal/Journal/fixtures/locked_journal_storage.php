<?php

declare(strict_types=1);

/*
 * The module's journal table for several processes at once: a JSON file read and
 * written under an exclusive flock, so requests reach the module's JournalStore one
 * at a time as its UNIQUE keys would order them. Needs the module test bootstrap and
 * classes/JournalStore.php loaded first.
 */

final class LockedFileJournalStorage implements JournalStorage
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    public function __construct(private readonly string $file)
    {
        $this->rows = is_file($file) && filesize($file) > 0 ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /** @return array{0: int, 1: string} the module's status and JSON body; the request is logged as "pid seq status time" */
    public static function respond(string $store, string $log, string $body, string $auth, string $secret): array
    {
        $lock = fopen($store . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            usleep(random_int(0, 15000));
            [$status, $response] = (new JournalStore(new self($store)))->respond($body, $auth, $secret);
            $data = json_decode($body, true);
            file_put_contents($log, sprintf("%d %s %d %.6f\n", getmypid(), $data['line']['seq'] ?? 'head', $status, microtime(true)), FILE_APPEND);

            return [$status, (string) json_encode($response)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function findBySeq(string $journalId, int $seq): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['journal_id'] === $journalId && $row['seq'] === $seq) {
                return $row;
            }
        }

        return null;
    }

    public function findByHash(string $journalId, string $hash): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['journal_id'] === $journalId && strcasecmp($row['hash'], $hash) === 0) {
                return $row;
            }
        }

        return null;
    }

    public function insert(array $row): bool
    {
        if ($this->findBySeq($row['journal_id'], $row['seq']) !== null || $this->findByHash($row['journal_id'], $row['hash']) !== null) {
            return false;
        }
        $this->rows[] = $row;
        file_put_contents($this->file, json_encode($this->rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return true;
    }

    public function lastSeq(string $journalId): int
    {
        $last = 0;
        foreach ($this->rows as $row) {
            if ($row['journal_id'] === $journalId) {
                $last = max($last, $row['seq']);
            }
        }

        return $last;
    }

    public function contiguousSeq(string $journalId): int
    {
        $stored = [];
        foreach ($this->rows as $row) {
            if ($row['journal_id'] === $journalId) {
                $stored[$row['seq']] = true;
            }
        }
        $n = 0;
        while (isset($stored[$n + 1])) {
            ++$n;
        }

        return $n;
    }

    public function seriesHeads(string $journalId): array
    {
        $heads = [];
        foreach ($this->rows as $row) {
            if ($row['journal_id'] === $journalId && (!isset($heads[$row['series']]) || $heads[$row['series']]['seq'] < $row['seq'])) {
                $heads[$row['series']] = ['number' => $row['number'], 'hash' => $row['hash'], 'seq' => $row['seq']];
            }
        }

        return $heads;
    }
}
