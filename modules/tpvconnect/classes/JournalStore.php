<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/OrderLookupProcessor.php';

/**
 * Storage of the fiscal-journal copy (table PREFIX_tpvconnect_journal), so the
 * rules can be tested without a shop. PsJournalStorage is the production one.
 */
interface JournalStorage
{
    /** @return array{line_hash: string}|null the line stored at that position */
    public function findBySeq(string $journalId, int $seq): ?array;

    /** @return array{journal_id: string, seq: int}|null the line of this journal holding that document */
    public function findByHash(string $journalId, string $hash): ?array;

    /**
     * @param array{journal_id: string, seq: int, series: string, number: string, invoice_type: string, hash: string, line_hash: string, line: string} $row
     *
     * @return bool false when a unique key (journal_id + seq, or journal_id + hash) already holds a row
     */
    public function insert(array $row): bool;

    /** 0 when the journal has no line. */
    public function lastSeq(string $journalId): int;

    /** @return array<string, array{number: string, hash: string, seq: int}> the last line of every series */
    public function seriesHeads(string $journalId): array;
}

/** A rejected journal request, with the HTTP status and body the TPV receives. */
class JournalRequestException extends RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(private readonly int $httpCode, private readonly array $body)
    {
        parent::__construct((string) ($body['error'] ?? 'Journal request rejected'));
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /** @return array<string, mixed> */
    public function getBody(): array
    {
        return $this->body;
    }
}

/**
 * Shop copy of the TPV's fiscal journal (fiscal-journal D6).
 *
 * POST /module/tpvconnect/journal, signed like the other TPV endpoints:
 * - {"journal_id", "line": {...}} stores one journal line. The idempotency key is
 *   (journal_id, seq, line_hash): the same line again answers
 *   200 {"stored": false, "duplicate": true}; another line at a stored position, or
 *   a document already stored at another position of the same journal, answers 409.
 *   A document stored under another journal_id is stored again: a new journal (the
 *   TPV lost the old one, or backfills) uploads documents the shop already holds. The line_hash is
 *   verified first (400 when it does not match the line).
 * - {"journal_id", "action": "head"} answers the last stored line of every series:
 *   {"journal_id", "last_seq", "series": {"A26": {"number", "hash", "seq"}, ...}}.
 *
 * line_hash = SHA-256 (lowercase hex) of the canonical JSON of the line without
 * line_hash: object keys sorted by byte order at every depth, list order kept,
 * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION.
 */
class JournalStore
{
    /** Largest accepted request body; a longer one answers 413 unread. */
    public const MAX_BODY_BYTES = 1048576;

    public const CANONICAL_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    private const KINDS = ['F2', 'R5', 'F3'];
    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';
    private const SHA256 = '/^[0-9a-fA-F]{64}$/D';
    private const SERIES = '/^[A-Za-z0-9_-]{1,16}$/D';
    private const INVOICE_TYPE = '/^[A-Z0-9]{2}$/D';
    private const MAX_NUMBER_LENGTH = 60;
    private const MAX_JOURNALED_AT_LENGTH = 64;

    public function __construct(private readonly JournalStorage $storage)
    {
    }

    /**
     * The whole request: body size, signature (OrderLookupProcessor::verifySignature,
     * the same check and the same 401 as the other signed endpoints), then the body.
     *
     * @return array{0: int, 1: array<string, mixed>|object} HTTP status and JSON body
     */
    public function respond(string $rawBody, string $authHeader, string $secret): array
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return [413, ['error' => 'Payload too large']];
        }
        if (!OrderLookupProcessor::verifySignature($rawBody, $secret, $authHeader)) {
            return [401, ['error' => 'Unauthorized']];
        }

        try {
            return $this->handle($rawBody);
        } catch (JournalRequestException $e) {
            return [$e->getHttpCode(), $e->getBody()];
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     *
     * @throws JournalRequestException
     */
    private function handle(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        // The line again as objects: an empty JSON object must stay {} for the hash.
        $objects = json_decode($rawBody, false);
        if (!is_array($data) || !$objects instanceof stdClass) {
            throw self::invalid(['body' => 'Invalid JSON']);
        }

        $journalId = $data['journal_id'] ?? null;
        if (!is_string($journalId) || preg_match(self::UUID, $journalId) !== 1) {
            throw self::invalid(['journal_id' => 'Required UUID']);
        }

        $hasAction = array_key_exists('action', $data);
        $hasLine = array_key_exists('line', $data);
        if ($hasAction && $hasLine) {
            throw self::invalid(['action' => 'Send either "line" or "action", not both']);
        }
        if ($hasAction) {
            if ($data['action'] !== 'head') {
                throw self::invalid(['action' => 'Unknown action']);
            }

            return [200, $this->head($journalId)];
        }
        if (!$hasLine) {
            throw self::invalid(['line' => 'Required object']);
        }
        if (!is_array($data['line']) || !$objects->line instanceof stdClass) {
            throw self::invalid(['line' => 'Must be an object']);
        }

        return $this->store($journalId, $data['line'], $objects->line);
    }

    /**
     * @param array<string, mixed> $line the line decoded as arrays, for validation
     * @param stdClass $lineObject the same line decoded as objects, for the hash
     *
     * @return array{0: int, 1: array<string, mixed>}
     *
     * @throws JournalRequestException
     */
    private function store(string $journalId, array $line, stdClass $lineObject): array
    {
        self::validateLine($line);

        $lineHash = strtolower($line['line_hash']);
        if (!hash_equals(self::lineHash($lineObject), $lineHash)) {
            throw new JournalRequestException(400, ['error' => 'line_hash mismatch']);
        }

        $journalId = strtolower($journalId);
        $seq = $line['seq'];
        $known = $this->existing($journalId, $seq, $lineHash, $line['fiscal_record']['hash']);
        if ($known !== null) {
            return $known;
        }

        $inserted = $this->storage->insert([
            'journal_id' => $journalId,
            'seq' => $seq,
            'series' => $line['series'],
            'number' => $line['fiscal_record']['number'],
            'invoice_type' => $line['fiscal_record']['invoice_type'],
            'hash' => $line['fiscal_record']['hash'],
            'line_hash' => $lineHash,
            'line' => self::canonicalJson($lineObject),
        ]);
        if ($inserted) {
            return [201, ['stored' => true, 'duplicate' => false]];
        }

        // A concurrent request stored this position or document first.
        $known = $this->existing($journalId, $seq, $lineHash, $line['fiscal_record']['hash']);
        if ($known !== null) {
            return $known;
        }

        throw new RuntimeException('Journal line could not be stored');
    }

    /**
     * The answer for a position or document that is already stored, or null.
     *
     * @return array{0: int, 1: array<string, mixed>}|null
     *
     * @throws JournalRequestException
     */
    private function existing(string $journalId, int $seq, string $lineHash, string $documentHash): ?array
    {
        $atSeq = $this->storage->findBySeq($journalId, $seq);
        if ($atSeq !== null) {
            if (hash_equals(strtolower((string) $atSeq['line_hash']), $lineHash)) {
                return [200, ['stored' => false, 'duplicate' => true]];
            }

            throw new JournalRequestException(409, ['error' => 'Conflict', 'details' => ['seq' => 'Another line is stored at this position']]);
        }

        if ($this->storage->findByHash($journalId, $documentHash) !== null) {
            throw new JournalRequestException(409, ['error' => 'Conflict', 'details' => ['hash' => 'This document is stored at another journal position']]);
        }

        return null;
    }

    /** @return array{journal_id: string, last_seq: int, series: object} */
    private function head(string $journalId): array
    {
        $normalized = strtolower($journalId);
        $series = [];
        foreach ($this->storage->seriesHeads($normalized) as $name => $head) {
            $series[(string) $name] = ['number' => (string) $head['number'], 'hash' => (string) $head['hash'], 'seq' => (int) $head['seq']];
        }

        // An object even when empty or when a series name looks like a number.
        return ['journal_id' => $journalId, 'last_seq' => $this->storage->lastSeq($normalized), 'series' => (object) $series];
    }

    /**
     * Types and lengths of every D2 field the module reads or relies on.
     *
     * @param array<string, mixed> $line
     *
     * @throws JournalRequestException
     */
    private static function validateLine(array $line): void
    {
        $errors = [];
        if (($line['v'] ?? null) !== 1) {
            $errors['line.v'] = 'Must be 1';
        }
        if (!is_int($line['seq'] ?? null) || $line['seq'] < 1) {
            $errors['line.seq'] = 'Required integer of at least 1';
        }
        $journaledAt = $line['journaled_at'] ?? null;
        if (!is_string($journaledAt) || $journaledAt === '' || strlen($journaledAt) > self::MAX_JOURNALED_AT_LENGTH) {
            $errors['line.journaled_at'] = 'Required date string';
        }
        if (!in_array($line['kind'] ?? null, self::KINDS, true)) {
            $errors['line.kind'] = 'One of ' . implode(', ', self::KINDS);
        }
        if (!is_string($line['series'] ?? null) || preg_match(self::SERIES, $line['series']) !== 1) {
            $errors['line.series'] = 'Up to 16 letters, digits, "-" or "_"';
        }
        foreach (['counter', 'rows'] as $key) {
            if (!is_array($line[$key] ?? null)) {
                $errors['line.' . $key] = 'Required object';
            }
        }
        if (!is_bool($line['backfill'] ?? null)) {
            $errors['line.backfill'] = 'Required boolean';
        }
        $prev = $line['prev'] ?? null;
        if (!is_string($prev) || ($prev !== '' && preg_match(self::SHA256, $prev) !== 1)) {
            $errors['line.prev'] = 'Empty or a SHA-256 hex string';
        }
        if (!is_string($line['line_hash'] ?? null) || preg_match(self::SHA256, $line['line_hash']) !== 1) {
            $errors['line.line_hash'] = 'Required SHA-256 hex string';
        }

        $record = $line['fiscal_record'] ?? null;
        if (!is_array($record)) {
            $errors['line.fiscal_record'] = 'Required object';
        } else {
            $number = $record['number'] ?? null;
            if (!is_string($number) || $number === '' || mb_strlen($number, 'UTF-8') > self::MAX_NUMBER_LENGTH) {
                $errors['line.fiscal_record.number'] = 'Required string of up to 60 characters';
            }
            if (!is_string($record['invoice_type'] ?? null) || preg_match(self::INVOICE_TYPE, $record['invoice_type']) !== 1) {
                $errors['line.fiscal_record.invoice_type'] = 'Two capital letters or digits';
            }
            if (!is_string($record['hash'] ?? null) || preg_match(self::SHA256, $record['hash']) !== 1) {
                $errors['line.fiscal_record.hash'] = 'Required SHA-256 hex string';
            }
        }

        if ($errors !== []) {
            throw self::invalid($errors);
        }
    }

    /** @param array<string, string> $details */
    private static function invalid(array $details): JournalRequestException
    {
        return new JournalRequestException(422, ['error' => 'Validation failed', 'details' => $details]);
    }

    /** SHA-256 hex of the canonical JSON of the line without its line_hash. */
    public static function lineHash(stdClass $line): string
    {
        $withoutHash = clone $line;
        unset($withoutHash->line_hash);

        return hash('sha256', self::canonicalJson($withoutHash));
    }

    /**
     * Canonical JSON of a value decoded with json_decode($json, false): object keys
     * sorted by byte order at every depth, list order kept.
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortKeys($value), self::CANONICAL_FLAGS | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            foreach ($properties as $key => $item) {
                $properties[$key] = self::sortKeys($item);
            }

            return (object) $properties;
        }
        if (is_array($value)) {
            // A JSON list (decoded as objects, every array is one): order kept.
            return array_map([self::class, 'sortKeys'], $value);
        }

        return $value;
    }
}

/**
 * Production JournalStorage on PrestaShop's Db. Every value is a validated
 * string or an integer and still goes through pSQL() or (int).
 */
class PsJournalStorage implements JournalStorage
{
    private const TABLE = 'tpvconnect_journal';

    public function findBySeq(string $journalId, int $seq): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `line_hash` FROM `' . _DB_PREFIX_ . self::TABLE . "`
            WHERE `journal_id` = '" . pSQL($journalId) . "' AND `seq` = " . (int) $seq,
            false
        );

        return is_array($row) ? ['line_hash' => (string) $row['line_hash']] : null;
    }

    public function findByHash(string $journalId, string $hash): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `journal_id`, `seq` FROM `' . _DB_PREFIX_ . self::TABLE . "`
            WHERE `journal_id` = '" . pSQL($journalId) . "' AND `hash` = '" . pSQL($hash) . "'",
            false
        );

        return is_array($row) ? ['journal_id' => (string) $row['journal_id'], 'seq' => (int) $row['seq']] : null;
    }

    public function insert(array $row): bool
    {
        try {
            return (bool) Db::getInstance()->insert(self::TABLE, [
                'journal_id' => pSQL($row['journal_id']),
                'seq' => (int) $row['seq'],
                'series' => pSQL($row['series']),
                'number' => pSQL($row['number']),
                'invoice_type' => pSQL($row['invoice_type']),
                'hash' => pSQL($row['hash']),
                'line_hash' => pSQL($row['line_hash']),
                // html=true: pSQL() would otherwise strip_tags() the JSON and change the line.
                'line' => pSQL($row['line'], true),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        } catch (PrestaShopDatabaseException $e) {
            // A duplicate key throws in debug mode; the caller re-reads the row.
            return false;
        }
    }

    public function lastSeq(string $journalId): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT MAX(`seq`) FROM `' . _DB_PREFIX_ . self::TABLE . "` WHERE `journal_id` = '" . pSQL($journalId) . "'",
            false
        );
    }

    public function seriesHeads(string $journalId): array
    {
        $table = '`' . _DB_PREFIX_ . self::TABLE . '`';
        $journal = "'" . pSQL($journalId) . "'";
        $rows = Db::getInstance()->executeS(
            'SELECT j.`series`, j.`number`, j.`hash`, j.`seq` FROM ' . $table . ' j
            INNER JOIN (SELECT `series`, MAX(`seq`) AS `seq` FROM ' . $table . ' WHERE `journal_id` = ' . $journal . ' GROUP BY `series`) m
                ON m.`series` = j.`series` AND m.`seq` = j.`seq`
            WHERE j.`journal_id` = ' . $journal . '
            ORDER BY j.`series`',
            true,
            false
        );

        $heads = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $heads[(string) $row['series']] = ['number' => (string) $row['number'], 'hash' => (string) $row['hash'], 'seq' => (int) $row['seq']];
        }

        return $heads;
    }
}
