<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/**
 * One folder of the fiscal journal (fiscal-journal D1): `journal-YYYY-MM.jsonl`
 * (JSON Lines, one document per line, month of `journaled_at`), `journal.id`,
 * `head.json`, `intents/`, `quarantine/` and `journal.lock`. The folder is 0700
 * with an `.htaccess` that denies the web, and every file in it is 0600, as the
 * database backups (DatabaseBackup::prepareDirectory).
 *
 * Lines are only ever appended, each with one write followed by fflush and fsync.
 * A process killed in the middle leaves a torn last line: repairTail() cuts it off
 * and keeps the fragment in `quarantine/`, so the flush writes that document again
 * from the database. A line that fails its checks and is not the last one is
 * damage, which is never rewritten here.
 */
final class JournalLocation
{
    public const TAIL_OK = 'ok';
    public const TAIL_REPAIRED = 'repaired';
    /** A line before the last one fails its checks: nothing is cut. */
    public const TAIL_DAMAGED = 'damaged';

    private const FILE_PATTERN = '/\Ajournal-\d{4}-\d{2}\.jsonl\z/D';
    private const LINE_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;
    private const BLOCK = 8192;

    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Creates the folder and its sub-folders with the D1 rules. A folder that is
     * too open is tightened; one that is read-only is left as it is (the journal
     * then uses the other location). Never throws.
     *
     * @return bool whether journal files can be written here
     */
    public function prepare(): bool
    {
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                return false;
            }
            $mode = @fileperms($this->directory);
            if ($mode !== false && ($mode & 0077) !== 0) {
                @chmod($this->directory, $mode & 0700);
            }
            clearstatcache(true, $this->directory);
            if (!$this->isWritable()) {
                return false;
            }
            $htaccess = $this->directory . '/.htaccess';
            if (!is_file($htaccess)) {
                if (@file_put_contents($htaccess, "Require all denied\n") === false) {
                    return false;
                }
                @chmod($htaccess, 0600);
            }
            foreach (['intents', 'quarantine'] as $folder) {
                $path = $this->directory . '/' . $folder;
                if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isWritable(): bool
    {
        return is_dir($this->directory) && is_writable($this->directory);
    }

    public function journalId(): ?string
    {
        $data = $this->readJson($this->directory . '/journal.id');
        $id = $data['journal_id'] ?? null;

        return \is_string($id) && $id !== '' ? $id : null;
    }

    public function writeJournalId(string $journalId, \DateTimeImmutable $at): void
    {
        $this->writeAtomically($this->directory . '/journal.id', json_encode(['journal_id' => $journalId, 'created_at' => $at->format(\DATE_ATOM)], self::LINE_FLAGS));
    }

    /** @return list<string> the monthly journal files, oldest first */
    public function journalFiles(): array
    {
        $files = [];
        foreach (@scandir($this->directory) ?: [] as $entry) {
            if (preg_match(self::FILE_PATTERN, $entry) === 1) {
                $files[] = $this->directory . '/' . $entry;
            }
        }
        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * Appends one line (with its line_hash) to the file of its month, in one write.
     *
     * @param array<string, mixed> $line
     *
     * @throws \RuntimeException when it cannot be written completely
     */
    public function append(array $line): void
    {
        $month = (new \DateTimeImmutable((string) $line['journaled_at']))->format('Y-m');
        $file = $this->directory . '/journal-' . $month . '.jsonl';
        $bytes = json_encode($line, self::LINE_FLAGS) . "\n";
        $existed = is_file($file);
        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open the fiscal journal file %s', $file));
        }
        try {
            if (!$existed) {
                @chmod($file, 0600);
            }
            $written = fwrite($handle, $bytes);
            if ($written !== \strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException(sprintf('Cannot write the fiscal journal file %s', $file));
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $line the last line written */
    public function writeHead(array $line): void
    {
        $record = \is_array($line['fiscal_record'] ?? null) ? $line['fiscal_record'] : [];
        $this->writeAtomically($this->directory . '/head.json', json_encode([
            'seq' => $line['seq'],
            'number' => $record['number'] ?? null,
            'invoice_type' => $record['invoice_type'] ?? null,
            'hash' => $record['hash'] ?? null,
            'line_hash' => $line['line_hash'],
            'fiscal_record_id' => isset($record['id']) ? (int) $record['id'] : null,
        ], self::LINE_FLAGS));
    }

    /** @return array<string, mixed>|null */
    public function readHead(): ?array
    {
        return $this->readJson($this->directory . '/head.json');
    }

    /**
     * Every line, oldest first, decoded (null for a line that is not JSON).
     *
     * @return \Generator<int, array<string, mixed>|null>
     */
    public function lines(): \Generator
    {
        foreach ($this->journalFiles() as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                while (($raw = fgets($handle)) !== false) {
                    $raw = rtrim($raw, "\n");
                    if ($raw !== '') {
                        yield self::decode($raw);
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * The last line when it passes its own checks; when it does not (a torn tail
     * not repaired yet), the line before it if that one does.
     *
     * @return array<string, mixed>|false|null null when the journal is empty, false when no valid line can be told
     */
    public function lastValidLine(): array|false|null
    {
        $tail = $this->tail(2);
        if ($tail === []) {
            return null;
        }
        $last = $tail[\count($tail) - 1];
        if ($last['terminated'] && self::selfValid($last['line'])) {
            return $last['line'];
        }
        $previous = $tail[\count($tail) - 2] ?? null;
        if ($previous === null) {
            return null;
        }

        return self::selfValid($previous['line']) ? $previous['line'] : false;
    }

    /**
     * The last line, only when it is valid (null when the journal is empty, false
     * otherwise): appends must chain to it.
     *
     * @return array<string, mixed>|false|null
     */
    public function lastLine(): array|false|null
    {
        $tail = $this->tail(1);
        if ($tail === []) {
            return null;
        }
        $last = $tail[0];

        return $last['terminated'] && self::selfValid($last['line']) ? $last['line'] : false;
    }

    /**
     * Checks the last line (terminated, JSON, line_hash, `prev` and `seq` against
     * the line before it). A failing last line is cut off and kept in quarantine/;
     * a failing line before it is reported and nothing is cut. Call under the lock.
     */
    public function repairTail(): string
    {
        $tail = $this->tail(2);
        if ($tail === []) {
            return self::TAIL_OK;
        }
        $last = $tail[\count($tail) - 1];
        $previous = $tail[\count($tail) - 2] ?? null;
        $previousValid = $previous === null || self::selfValid($previous['line']);
        $lastValid = $last['terminated'] && self::selfValid($last['line'])
            && ($previous === null || self::follows($last['line'], $previous['line']));

        if ($lastValid) {
            return $previousValid ? self::TAIL_OK : self::TAIL_DAMAGED;
        }
        if (!$previousValid) {
            return self::TAIL_DAMAGED;
        }

        $fragment = (string) @file_get_contents($last['file'], false, null, $last['offset']);
        $quarantine = sprintf('%s/quarantine/%s-%s-%s.part', $this->directory, basename($last['file'], '.jsonl'), date('Ymd-His'), bin2hex(random_bytes(4)));
        if (!is_dir(\dirname($quarantine))) {
            @mkdir(\dirname($quarantine), 0700, true);
        }
        if (@file_put_contents($quarantine, $fragment) === false) {
            throw new \RuntimeException('Cannot keep the torn fiscal journal line in quarantine');
        }
        @chmod($quarantine, 0600);
        $handle = @fopen($last['file'], 'r+b');
        if ($handle === false || !ftruncate($handle, $last['offset']) || !fsync($handle)) {
            if ($handle !== false) {
                fclose($handle);
            }

            throw new \RuntimeException(sprintf('Cannot cut the torn line of %s', $last['file']));
        }
        fclose($handle);
        if ($last['offset'] === 0) {
            @unlink($last['file']);
        }

        return self::TAIL_REPAIRED;
    }

    /** Removes the journal files and the head (the secondary, once merged back). */
    public function clearLines(): void
    {
        foreach ($this->journalFiles() as $file) {
            @unlink($file);
        }
        @unlink($this->directory . '/head.json');
    }

    /** @param array<string, mixed> $data */
    public function writeIntent(string $hash, array $data): void
    {
        $this->writeAtomically($this->intentPath($hash), json_encode($data, self::LINE_FLAGS));
    }

    /** @return array<string, mixed>|null */
    public function readIntent(string $hash): ?array
    {
        return $this->readJson($this->intentPath($hash));
    }

    public function deleteIntent(string $hash): void
    {
        $path = $this->intentPath($hash);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Opens and locks `journal.lock` (exclusive, blocking).
     *
     * @return resource
     */
    public function lock()
    {
        $path = $this->directory . '/journal.lock';
        $existed = is_file($path);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open the fiscal journal lock %s', $path));
        }
        if (!$existed) {
            @chmod($path, 0600);
        }
        if (!flock($handle, \LOCK_EX)) {
            fclose($handle);

            throw new \RuntimeException(sprintf('Cannot lock the fiscal journal %s', $path));
        }

        return $handle;
    }

    /** @param resource $handle */
    public static function unlock($handle): void
    {
        flock($handle, \LOCK_UN);
        fclose($handle);
    }

    /**
     * JSON valid and line_hash matching its content.
     *
     * @param array<string, mixed>|null $line
     */
    public static function selfValid(?array $line): bool
    {
        return \is_array($line)
            && \is_string($line['line_hash'] ?? null)
            && \is_int($line['seq'] ?? null)
            && hash_equals(CanonicalJson::lineHash($line), $line['line_hash']);
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $previous
     */
    public static function follows(array $line, array $previous): bool
    {
        return ($line['prev'] ?? null) === ($previous['line_hash'] ?? null)
            && ($line['seq'] ?? null) === ($previous['seq'] ?? 0) + 1;
    }

    /**
     * The last $count lines of the journal (across month files), oldest first.
     *
     * @return list<array{file: string, offset: int, terminated: bool, line: array<string, mixed>|null}>
     */
    private function tail(int $count): array
    {
        $result = [];
        foreach (array_reverse($this->journalFiles()) as $file) {
            foreach (array_reverse($this->fileTail($file, $count - \count($result))) as $entry) {
                array_unshift($result, $entry);
            }
            if (\count($result) >= $count) {
                break;
            }
        }

        return $result;
    }

    /**
     * Reads a file backwards until it has its last $count lines.
     *
     * @return list<array{file: string, offset: int, terminated: bool, line: array<string, mixed>|null}>
     */
    private function fileTail(string $file, int $count): array
    {
        clearstatcache(true, $file);
        $size = (int) @filesize($file);
        if ($size === 0 || $count < 1) {
            return [];
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            $buffer = '';
            $position = $size;
            // One newline more than lines wanted, or the start of the file.
            while ($position > 0 && substr_count($buffer, "\n") <= $count) {
                $read = min(self::BLOCK, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = (string) fread($handle, $read) . $buffer;
            }
        } finally {
            fclose($handle);
        }

        $terminated = str_ends_with($buffer, "\n");
        $body = $terminated ? substr($buffer, 0, -1) : $buffer;
        $parts = explode("\n", $body);
        // The first part is incomplete unless the buffer starts at the file start.
        $offsets = [];
        $offset = $position;
        foreach ($parts as $part) {
            $offsets[] = $offset;
            $offset += \strlen($part) + 1;
        }
        if ($position > 0) {
            array_shift($parts);
            array_shift($offsets);
        }
        $entries = [];
        $total = \count($parts);
        foreach ($parts as $index => $raw) {
            if ($raw === '') {
                continue;
            }
            $entries[] = [
                'file' => $file,
                'offset' => $offsets[$index],
                'terminated' => $index < $total - 1 || $terminated,
                'line' => self::decode($raw),
            ];
        }

        return \array_slice($entries, -$count);
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

    private function intentPath(string $hash): string
    {
        if (preg_match('/\A[0-9A-Fa-f]{64}\z/D', $hash) !== 1) {
            throw new \InvalidArgumentException('An intent is named by its record hash');
        }

        return $this->directory . '/intents/' . $hash . '.json';
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        $json = @file_get_contents($path);
        if (!\is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);

        return \is_array($data) ? $data : null;
    }

    private function writeAtomically(string $path, string $content): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $content) === false) {
            throw new \RuntimeException(sprintf('Cannot write %s', $path));
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Cannot write %s', $path));
        }
    }
}
