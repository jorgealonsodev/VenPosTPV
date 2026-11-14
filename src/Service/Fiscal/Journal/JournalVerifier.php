<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use VenPosTPV\Service\Fiscal\FiscalHash;

/**
 * Checks the lines of a journal folder (fiscal-journal D4): valid JSON, its
 * line_hash, `seq` one more than the previous line and `prev` equal to its
 * line_hash, and the fiscal record's own `hash` recomputed from its fields (a line
 * whose line_hash was recomputed after a change is caught there). It also checks
 * that `head.json` matches the last line, and that nothing verified before has
 * gone: the checkpoint (the last line verified: file, byte offset, seq, line_hash)
 * must still be there, and the journal must not hold fewer lines than it said.
 *
 * Normally only what was appended after the checkpoint is read (the every-minute
 * job); a full check reads everything and still compares against the checkpoint.
 * An unterminated last line is a write in progress or a torn tail the writer
 * repairs, never damage. It only reads: damage is reported, never fixed.
 */
final class JournalVerifier
{
    public const BAD_LINE = 'line';
    public const CHAIN_BREAK = 'chain';
    public const FISCAL_HASH = 'fiscal_hash';
    public const REMOVED = 'removed';
    public const HEAD = 'head';

    /**
     * @param array<string, mixed>|null $checkpoint from the previous call
     * @param bool $complete whether this folder holds the journal from its first line
     *                       (the primary); the secondary only holds lines while the
     *                       primary cannot be written and is emptied once merged
     *
     * @return array{damaged: ?array{file: ?string, line: ?int, reason: string}, checkpoint: ?array{file: string, offset: int, line: int, seq: int, line_hash: string}}
     */
    public static function verify(JournalLocation $location, ?array $checkpoint, bool $full, bool $complete): array
    {
        $checkpoint = self::validCheckpoint($checkpoint);
        $files = $location->journalFiles();
        if (!$complete && $files === []) {
            return ['damaged' => null, 'checkpoint' => null];
        }

        $start = ['index' => 0, 'offset' => 0, 'line' => 0, 'previous' => null];
        if ($checkpoint !== null && !$complete && ($full || self::resume($files, $checkpoint) === null)) {
            // The secondary is emptied once merged and written again later: no history to keep.
            $checkpoint = null;
            $full = true;
        }
        if ($checkpoint !== null && !$full) {
            $start = self::resume($files, $checkpoint);
            if ($start === null) {
                return ['damaged' => ['file' => $checkpoint['file'], 'line' => $checkpoint['line'], 'reason' => self::REMOVED], 'checkpoint' => $checkpoint];
            }
        }

        $previous = $start['previous'];
        $lastGood = $full ? null : $checkpoint;
        $seenCheckpoint = false;
        foreach (\array_slice($files, $start['index']) as $position => $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                return ['damaged' => ['file' => basename($file), 'line' => null, 'reason' => self::BAD_LINE], 'checkpoint' => $checkpoint];
            }
            try {
                $offset = $position === 0 ? $start['offset'] : 0;
                $number = $position === 0 ? $start['line'] : 0;
                fseek($handle, $offset);
                while (($raw = fgets($handle)) !== false) {
                    if (!str_ends_with($raw, "\n")) {
                        break;
                    }
                    ++$number;
                    $offset += \strlen($raw);
                    $line = self::decode(rtrim($raw, "\n"));
                    $reason = self::check($line, $previous, $complete);
                    if ($reason !== null) {
                        return ['damaged' => ['file' => basename($file), 'line' => $number, 'reason' => $reason], 'checkpoint' => $checkpoint];
                    }
                    if ($checkpoint !== null && $line['seq'] === $checkpoint['seq']) {
                        if ($line['line_hash'] !== $checkpoint['line_hash']) {
                            return ['damaged' => ['file' => basename($file), 'line' => $number, 'reason' => self::REMOVED], 'checkpoint' => $checkpoint];
                        }
                        $seenCheckpoint = true;
                    }
                    $previous = $line;
                    $lastGood = ['file' => basename($file), 'offset' => $offset, 'line' => $number, 'seq' => $line['seq'], 'line_hash' => $line['line_hash']];
                }
            } finally {
                fclose($handle);
            }
        }

        if ($full && $checkpoint !== null && !$seenCheckpoint) {
            return ['damaged' => ['file' => $checkpoint['file'], 'line' => $checkpoint['line'], 'reason' => self::REMOVED], 'checkpoint' => $checkpoint];
        }

        $head = $location->readHead();
        $headAgrees = $previous === null
            ? $head === null
            : \is_array($head) && ($head['seq'] ?? null) === $previous['seq'] && ($head['line_hash'] ?? null) === $previous['line_hash'];
        if (!$headAgrees) {
            return ['damaged' => ['file' => 'head.json', 'line' => null, 'reason' => self::HEAD], 'checkpoint' => $checkpoint];
        }

        return ['damaged' => null, 'checkpoint' => $lastGood];
    }

    /**
     * @param array<string, mixed>|null $line
     * @param array<string, mixed>|null $previous
     */
    private static function check(?array $line, ?array $previous, bool $complete): ?string
    {
        if (!JournalLocation::selfValid($line)) {
            return self::BAD_LINE;
        }
        if ($previous !== null ? !JournalLocation::follows($line, $previous) : ($complete && ($line['seq'] !== 1 || ($line['prev'] ?? null) !== ''))) {
            return self::CHAIN_BREAK;
        }

        return self::fiscalHashMatches($line['fiscal_record'] ?? null) ? null : self::FISCAL_HASH;
    }

    /** The record's hash recomputed from its fields (FiscalRecorder::append). */
    private static function fiscalHashMatches(mixed $record): bool
    {
        if (!\is_array($record) || !\is_string($record['hash'] ?? null)) {
            return false;
        }
        foreach (['issuer_nif', 'number', 'issue_date', 'invoice_type', 'tax_total', 'total', 'generated_at'] as $field) {
            if (!\is_string($record[$field] ?? null)) {
                return false;
            }
        }
        $expected = FiscalHash::alta(
            $record['issuer_nif'],
            $record['number'],
            $record['issue_date'],
            $record['invoice_type'],
            $record['tax_total'],
            $record['total'],
            (string) ($record['previous_hash'] ?? ''),
            $record['generated_at'],
        );

        return hash_equals($expected, $record['hash']);
    }

    /**
     * Where to go on after the checkpoint; null when it no longer fits the files
     * (its file is gone or shorter, or the line ending there is another one).
     *
     * @param list<string> $files
     * @param array{file: string, offset: int, line: int, seq: int, line_hash: string} $checkpoint
     *
     * @return array{index: int, offset: int, line: int, previous: ?array{seq: int, line_hash: string}}|null
     */
    private static function resume(array $files, array $checkpoint): ?array
    {
        foreach ($files as $index => $file) {
            if (basename($file) !== $checkpoint['file']) {
                continue;
            }
            clearstatcache(true, $file);
            if ((int) @filesize($file) < $checkpoint['offset'] || !self::endsWithLine($file, $checkpoint['offset'], $checkpoint['line_hash'])) {
                return null;
            }

            return [
                'index' => $index,
                'offset' => $checkpoint['offset'],
                'line' => $checkpoint['line'],
                'previous' => ['seq' => $checkpoint['seq'], 'line_hash' => $checkpoint['line_hash']],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $checkpoint
     *
     * @return array{file: string, offset: int, line: int, seq: int, line_hash: string}|null
     */
    private static function validCheckpoint(?array $checkpoint): ?array
    {
        if (!\is_string($checkpoint['file'] ?? null) || !\is_int($checkpoint['offset'] ?? null) || !\is_int($checkpoint['line'] ?? null)
            || !\is_int($checkpoint['seq'] ?? null) || !\is_string($checkpoint['line_hash'] ?? null)) {
            return null;
        }

        return [
            'file' => $checkpoint['file'],
            'offset' => $checkpoint['offset'],
            'line' => $checkpoint['line'],
            'seq' => $checkpoint['seq'],
            'line_hash' => $checkpoint['line_hash'],
        ];
    }

    /** Whether the line that ends at $offset is still the checkpoint's line. */
    private static function endsWithLine(string $file, int $offset, string $lineHash): bool
    {
        if ($offset === 0) {
            return false;
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $length = min($offset, 128);
            fseek($handle, $offset - $length);
            $chunk = (string) fread($handle, $length);
        } finally {
            fclose($handle);
        }

        // Lines are written with line_hash as their last key (D2 order).
        return str_ends_with($chunk, '"line_hash":"' . $lineHash . "\"}\n");
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $raw): ?array
    {
        try {
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }
}
