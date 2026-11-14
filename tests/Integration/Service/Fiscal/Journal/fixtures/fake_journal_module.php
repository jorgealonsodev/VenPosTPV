<?php

declare(strict_types=1);

/*
 * A local stand-in for the shop's POST /module/tpvconnect/journal: the module's own
 * JournalStore (modules/tpvconnect/classes/JournalStore.php, with the module test
 * doubles of PrestaShop) on a table kept in a JSON file, so the TPV talks to the
 * real module rules without a shop. Reads {"store", "secret", "body", "auth"} on
 * STDIN and prints {"status", "body"}.
 */

require __DIR__ . '/../../../../../../modules/tpvconnect/tests/bootstrap.php';
require_once __DIR__ . '/../../../../../../modules/tpvconnect/classes/JournalStore.php';

final class FileJournalStorage implements JournalStorage
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    public function __construct(private readonly string $file)
    {
        $this->rows = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
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

    public function journalIds(): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $row): string => $row['journal_id'], $this->rows)));
        sort($ids);

        return $ids;
    }

    public function latestBySeries(): array
    {
        // Rows are kept in the order they were stored, as the table's auto-increment id.
        $latest = [];
        foreach ($this->rows as $row) {
            $latest[$row['series']] = ['journal_id' => $row['journal_id'], 'number' => $row['number'], 'hash' => $row['hash'], 'seq' => $row['seq']];
        }

        return $latest;
    }
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
[$status, $body] = (new JournalStore(new FileJournalStorage($request['store'])))->respond($request['body'], $request['auth'], $request['secret']);
echo json_encode(['status' => $status, 'body' => json_encode($body)]);
