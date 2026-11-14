<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use VenPosTPV\Service\Fiscal\Journal\CanonicalJson;

/** Chained D2 lines for the file-level tests (no database). */
final class JournalLines
{
    /**
     * @return list<array<string, mixed>> lines $from..$to chained after $previous
     */
    public static function chain(int $from, int $to, ?array $previous = null, string $journaledAt = '2026-11-14T18:02:11+01:00'): array
    {
        $lines = [];
        for ($seq = $from; $seq <= $to; ++$seq) {
            $line = [
                'v' => 1,
                'seq' => $seq,
                'journaled_at' => $journaledAt,
                'kind' => 'F2',
                'series' => 'A26',
                'counter' => ['table' => 'ticket_sequence', 'letter' => 'A', 'year' => 2026, 'number' => $seq],
                'fiscal_record' => ['id' => $seq, 'invoice_type' => 'F2', 'number' => sprintf('A26%06d', $seq), 'hash' => strtoupper(hash('sha256', 'doc' . $seq))],
                'rows' => ['order_meta' => ['id' => 100 + $seq], 'sale_line' => []],
                'backfill' => false,
                'prev' => $previous['line_hash'] ?? '',
            ];
            $line['line_hash'] = CanonicalJson::lineHash($line);
            $lines[] = $line;
            $previous = $line;
        }

        return $lines;
    }

    public static function temporaryDirectory(string $name): string
    {
        $directory = sys_get_temp_dir() . '/venpos-journal-' . $name . '-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);

        return $directory;
    }

    public static function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            @chmod($path, 0700);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
