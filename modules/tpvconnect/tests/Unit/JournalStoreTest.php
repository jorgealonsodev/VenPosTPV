<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use JournalStorage;
use JournalStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * In-memory JournalStorage with the table's two unique keys:
 * (journal_id, seq) and (journal_id, hash). series and number compare
 * case-sensitively, as the table's utf8mb4_bin columns do.
 */
final class InMemoryJournalStorage implements JournalStorage
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

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
            if ($row['journal_id'] !== $journalId) {
                continue;
            }
            if (!isset($heads[$row['series']]) || $heads[$row['series']]['seq'] < $row['seq']) {
                $heads[$row['series']] = ['number' => $row['number'], 'hash' => $row['hash'], 'seq' => $row['seq']];
            }
        }

        return $heads;
    }
}

/**
 * Shop copy of the fiscal journal (fiscal-journal D6): POST /module/tpvconnect/journal.
 */
final class JournalStoreTest extends TestCase
{
    private const SECRET = 'journal-test-secret-that-is-long-enough-0123456789';
    private const JOURNAL = '3f2b8c1e-9a4d-4e6f-8b2a-1c3d5e7f9a0b';
    private const OTHER_JOURNAL = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    private InMemoryJournalStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryJournalStorage();
    }

    #[DataProvider('vectors')]
    public function test_the_canonical_json_vector_matches_plain_php(string $file): void
    {
        $vector = $this->vector($file);
        $plain = json_encode(
            self::sortKeys($vector['line_without_hash']),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        self::assertSame($vector['canonical_json'], $plain);
        self::assertSame($vector['line_hash'], hash('sha256', $plain));
        // The module computes it from the decoded request body, never from PHP arrays it built.
        $decoded = json_decode(json_encode($vector['line_without_hash'], JSON_PRESERVE_ZERO_FRACTION), false);
        self::assertSame($vector['canonical_json'], JournalStore::canonicalJson($decoded));
        self::assertSame($vector['line_hash'], JournalStore::lineHash($decoded));
        if (array_key_exists('counter', $vector['line_without_hash']) && $vector['line_without_hash']['counter'] === null) {
            self::assertStringContainsString('"counter":null', JournalStore::canonicalJson($decoded));
        }
    }

    public function test_a_coupon_sale_numbered_by_the_shop_is_stored_and_heads_its_ps_series(): void
    {
        $coupon = $this->vector('journal-line-vector-coupon.json');
        $line = $coupon['line_without_hash'] + ['line_hash' => $coupon['line_hash']];
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(1, 'A26', 'A26000001')]);
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(2, 'A26', 'A26000002')]);

        $response = $this->post(['journal_id' => self::JOURNAL, 'line' => $line]);

        self::assertSame([201, ['stored' => true, 'duplicate' => false]], $response);
        [, $head] = $this->post(['journal_id' => self::JOURNAL, 'action' => 'head']);
        self::assertSame(['number' => 'XKBKNABJK', 'hash' => $line['fiscal_record']['hash'], 'seq' => 3], ((array) $head['series'])['PS']);
        self::assertSame(3, $head['contiguous_seq']);
    }

    public function test_contiguous_seq_of_a_complete_journal_is_its_last_seq(): void
    {
        foreach ([1, 2, 3] as $seq) {
            $this->post(['journal_id' => self::JOURNAL, 'line' => self::line($seq, 'A26', 'A2600000' . $seq)]);
        }

        [, $head] = $this->post(['journal_id' => self::JOURNAL, 'action' => 'head']);

        self::assertSame(3, $head['last_seq']);
        self::assertSame(3, $head['contiguous_seq']);
    }

    public function test_contiguous_seq_stops_before_a_missing_middle_line(): void
    {
        foreach ([1, 5, 6] as $seq) {
            $this->post(['journal_id' => self::JOURNAL, 'line' => self::line($seq, 'A26', 'A2600000' . $seq)]);
        }

        [, $head] = $this->post(['journal_id' => self::JOURNAL, 'action' => 'head']);

        self::assertSame(6, $head['last_seq']);
        self::assertSame(1, $head['contiguous_seq']);
    }

    public function test_contiguous_seq_is_zero_when_seq_1_is_missing(): void
    {
        foreach ([2, 3] as $seq) {
            $this->post(['journal_id' => self::JOURNAL, 'line' => self::line($seq, 'A26', 'A2600000' . $seq)]);
        }

        [, $head] = $this->post(['journal_id' => self::JOURNAL, 'action' => 'head']);

        self::assertSame(3, $head['last_seq']);
        self::assertSame(0, $head['contiguous_seq']);
    }

    public function test_series_and_numbers_that_differ_only_in_case_are_kept_apart(): void
    {
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(1, 'A26', 'A26000001')]);
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(2, 'a26', 'a26000001')]);

        [, $head] = $this->post(['journal_id' => self::JOURNAL, 'action' => 'head']);

        self::assertSame('A26000001', ((array) $head['series'])['A26']['number']);
        self::assertSame('a26000001', ((array) $head['series'])['a26']['number']);
    }

    public function test_a_new_line_is_stored_with_its_document_columns(): void
    {
        $line = self::line(1, 'A26', 'A26000001');

        $response = $this->post(['journal_id' => self::JOURNAL, 'line' => $line]);

        self::assertSame([201, ['stored' => true, 'duplicate' => false]], $response);
        self::assertCount(1, $this->storage->rows);
        $row = $this->storage->rows[0];
        self::assertSame(self::JOURNAL, $row['journal_id']);
        self::assertSame(1, $row['seq']);
        self::assertSame('A26', $row['series']);
        self::assertSame('A26000001', $row['number']);
        self::assertSame('F2', $row['invoice_type']);
        self::assertSame($line['fiscal_record']['hash'], $row['hash']);
        self::assertSame($line['line_hash'], $row['line_hash']);
        self::assertEquals($line, json_decode($row['line'], true));
        self::assertSame(JournalStore::canonicalJson(json_decode((string) json_encode($line, JSON_PRESERVE_ZERO_FRACTION), false)), $row['line']);
    }

    public function test_the_same_line_again_is_a_duplicate_and_stores_nothing(): void
    {
        $line = self::line(1, 'A26', 'A26000001');
        $this->post(['journal_id' => self::JOURNAL, 'line' => $line]);

        $response = $this->post(['journal_id' => self::JOURNAL, 'line' => $line]);

        self::assertSame([200, ['stored' => false, 'duplicate' => true]], $response);
        self::assertCount(1, $this->storage->rows);
    }

    public function test_another_line_at_a_stored_position_is_a_conflict(): void
    {
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(1, 'A26', 'A26000001')]);

        [$status, $body] = $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(1, 'A26', 'A26000099')]);

        self::assertSame(409, $status);
        self::assertSame('Conflict', $body['error']);
        self::assertArrayHasKey('seq', $body['details']);
        self::assertCount(1, $this->storage->rows);
        self::assertSame('A26000001', $this->storage->rows[0]['number']);
    }

    public function test_a_line_whose_hash_does_not_match_its_content_is_rejected(): void
    {
        $line = self::line(1, 'A26', 'A26000001');
        $line['fiscal_record']['total'] = '99.99';

        $response = $this->post(['journal_id' => self::JOURNAL, 'line' => $line]);

        self::assertSame([400, ['error' => 'line_hash mismatch']], $response);
        self::assertSame([], $this->storage->rows);
    }

    public function test_head_answers_the_last_document_of_every_series(): void
    {
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(1, 'A26', 'A26000001')]);
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(2, 'R2026', 'R2026000001', 'R5')]);
        $last = self::line(3, 'A26', 'A26000002');
        $this->post(['journal_id' => self::JOURNAL, 'line' => $last]);

        [$status, $body] = $this->post(['journal_id' => self::JOURNAL, 'action' => 'head']);

        self::assertSame(200, $status);
        self::assertSame(self::JOURNAL, $body['journal_id']);
        self::assertSame(3, $body['last_seq']);
        $series = (array) $body['series'];
        ksort($series);
        self::assertSame([
            'A26' => ['number' => 'A26000002', 'hash' => $last['fiscal_record']['hash'], 'seq' => 3],
            'R2026' => ['number' => 'R2026000001', 'hash' => self::line(2, 'R2026', 'R2026000001', 'R5')['fiscal_record']['hash'], 'seq' => 2],
        ], $series);
    }

    public function test_head_of_an_empty_store_has_no_series_as_a_json_object(): void
    {
        $request = (string) json_encode(['journal_id' => self::JOURNAL, 'action' => 'head']);
        [$status, $body] = (new JournalStore($this->storage))->respond($request, 'HMAC-SHA256 ' . hash_hmac('sha256', $request, self::SECRET), self::SECRET);

        self::assertSame(200, $status);
        // What the controller sends: series is {} even with no line.
        self::assertSame('{"journal_id":"' . self::JOURNAL . '","last_seq":0,"contiguous_seq":0,"series":{}}', json_encode($body));
    }

    public function test_a_second_journal_is_kept_apart_from_the_first(): void
    {
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(1, 'A26', 'A26000001')]);
        $this->post(['journal_id' => self::JOURNAL, 'line' => self::line(2, 'A26', 'A26000002')]);

        // seq 1 of another journal is another position, not a conflict.
        $response = $this->post(['journal_id' => self::OTHER_JOURNAL, 'line' => self::line(1, 'A26', 'A26000003')]);
        self::assertSame(201, $response[0]);

        [, $first] = $this->post(['journal_id' => self::JOURNAL, 'action' => 'head']);
        [, $second] = $this->post(['journal_id' => self::OTHER_JOURNAL, 'action' => 'head']);
        self::assertSame(2, $first['last_seq']);
        self::assertSame('A26000002', ((array) $first['series'])['A26']['number']);
        self::assertSame(1, $second['last_seq']);
        self::assertSame('A26000003', ((array) $second['series'])['A26']['number']);
    }

    public function test_a_document_already_stored_at_another_position_is_a_conflict(): void
    {
        $line = self::line(1, 'A26', 'A26000001');
        $this->post(['journal_id' => self::JOURNAL, 'line' => $line]);
        $moved = $line;
        $moved['seq'] = 2;
        $moved['line_hash'] = self::hashOf($moved);

        [$status, $body] = $this->post(['journal_id' => self::JOURNAL, 'line' => $moved]);

        self::assertSame(409, $status);
        self::assertArrayHasKey('hash', $body['details']);
        self::assertCount(1, $this->storage->rows);
    }

    public function test_a_document_stored_under_another_journal_is_stored_again_under_the_new_one(): void
    {
        $line = self::line(1, 'A26', 'A26000001');
        $this->post(['journal_id' => self::JOURNAL, 'line' => $line]);

        // A new journal (the TPV lost the old one, or backfills) uploads the same document.
        $response = $this->post(['journal_id' => self::OTHER_JOURNAL, 'line' => $line]);

        self::assertSame([201, ['stored' => true, 'duplicate' => false]], $response);
        self::assertCount(2, $this->storage->rows);
        [, $head] = $this->post(['journal_id' => self::OTHER_JOURNAL, 'action' => 'head']);
        self::assertSame(['number' => 'A26000001', 'hash' => $line['fiscal_record']['hash'], 'seq' => 1], ((array) $head['series'])['A26']);
    }

    public function test_a_missing_or_invalid_signature_gets_the_answer_of_the_other_signed_endpoints(): void
    {
        $body = (string) json_encode(['journal_id' => self::JOURNAL, 'line' => self::line(1, 'A26', 'A26000001')]);
        $store = new JournalStore($this->storage);

        self::assertSame([401, ['error' => 'Unauthorized']], $store->respond($body, '', self::SECRET));
        self::assertSame([401, ['error' => 'Unauthorized']], $store->respond($body, 'HMAC-SHA256 ' . hash_hmac('sha256', $body, 'another-secret'), self::SECRET));
        self::assertSame([401, ['error' => 'Unauthorized']], $store->respond($body, 'HMAC-SHA256 ' . hash_hmac('sha256', $body . ' ', self::SECRET), self::SECRET));
        // An empty shop secret never accepts a request signed with an empty key.
        self::assertSame([401, ['error' => 'Unauthorized']], $store->respond($body, 'HMAC-SHA256 ' . hash_hmac('sha256', $body, ''), ''));
        self::assertSame([], $this->storage->rows);
    }

    public function test_an_oversized_body_is_rejected_before_anything_else(): void
    {
        $line = self::line(1, 'A26', 'A26000001');
        $line['rows']['padding'] = str_repeat('x', JournalStore::MAX_BODY_BYTES);
        $line['line_hash'] = self::hashOf($line);
        $body = (string) json_encode(['journal_id' => self::JOURNAL, 'line' => $line]);

        $response = (new JournalStore($this->storage))->respond($body, 'HMAC-SHA256 ' . hash_hmac('sha256', $body, self::SECRET), self::SECRET);

        self::assertSame([413, ['error' => 'Payload too large']], $response);
        self::assertSame([], $this->storage->rows);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_invalid_fields_are_refused_with_their_names(array $body, string $field): void
    {
        [$status, $answer] = $this->post($body);

        self::assertSame(422, $status);
        self::assertSame('Validation failed', $answer['error']);
        self::assertArrayHasKey($field, $answer['details']);
        self::assertSame([], $this->storage->rows);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): iterable
    {
        $line = self::line(1, 'A26', 'A26000001');
        $with = static function (array $changes) use ($line): array {
            $changed = array_replace_recursive($line, $changes);
            $changed['line_hash'] = self::hashOf($changed);

            return ['journal_id' => self::JOURNAL, 'line' => $changed];
        };

        yield 'journal_id is not a uuid' => [['journal_id' => "1' OR '1'='1", 'line' => $line], 'journal_id'];
        yield 'journal_id is a path' => [['journal_id' => '../../config/settings.inc', 'action' => 'head'], 'journal_id'];
        yield 'journal_id missing' => [['line' => $line], 'journal_id'];
        yield 'neither line nor head' => [['journal_id' => self::JOURNAL], 'line'];
        yield 'unknown action' => [['journal_id' => self::JOURNAL, 'action' => 'drop'], 'action'];
        yield 'line and action together' => [['journal_id' => self::JOURNAL, 'action' => 'head', 'line' => $line], 'action'];
        yield 'line is not an object' => [['journal_id' => self::JOURNAL, 'line' => 'x'], 'line'];
        yield 'series used as SQL' => [$with(['series' => "A26'; DROP TABLE x;--"]), 'line.series'];
        yield 'series too long' => [$with(['series' => str_repeat('A', 17)]), 'line.series'];
        yield 'seq is a string' => [$with(['seq' => '1']), 'line.seq'];
        yield 'seq is zero' => [$with(['seq' => 0]), 'line.seq'];
        yield 'version 2' => [$with(['v' => 2]), 'line.v'];
        yield 'unknown kind' => [$with(['kind' => 'F1']), 'line.kind'];
        yield 'number with markup' => [$with(['fiscal_record' => ['number' => 'A26<b>1</b>']]), 'line.fiscal_record.number'];
        yield 'number with a space' => [$with(['fiscal_record' => ['number' => 'A26 0001']]), 'line.fiscal_record.number'];
        yield 'counter is a string' => [$with(['counter' => 'A']), 'line.counter'];
        yield 'number too long' => [$with(['fiscal_record' => ['number' => str_repeat('9', 61)]]), 'line.fiscal_record.number'];
        yield 'invoice_type too long' => [$with(['fiscal_record' => ['invoice_type' => 'F2X']]), 'line.fiscal_record.invoice_type'];
        yield 'hash not hex' => [$with(['fiscal_record' => ['hash' => str_repeat('Z', 64)]]), 'line.fiscal_record.hash'];
        yield 'prev not a hash' => [$with(['prev' => 'abc']), 'line.prev'];
        yield 'backfill not a boolean' => [$with(['backfill' => 'no']), 'line.backfill'];
        yield 'rows not an object' => [$with(['rows' => 'x']), 'line.rows'];
        yield 'line_hash not a hash' => [['journal_id' => self::JOURNAL, 'line' => ['line_hash' => 'x'] + $line], 'line.line_hash'];
    }

    public function test_a_body_that_is_not_json_is_refused(): void
    {
        $body = 'not json';
        $response = (new JournalStore($this->storage))->respond($body, 'HMAC-SHA256 ' . hash_hmac('sha256', $body, self::SECRET), self::SECRET);

        self::assertSame([422, ['error' => 'Validation failed', 'details' => ['body' => 'Invalid JSON']]], $response);
    }

    public function test_the_controller_reuses_the_signed_endpoints_checks_and_logs_no_document(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/journal.php');

        self::assertStringContainsString('class TpvconnectJournalModuleFrontController extends ModuleFrontController', $controller);
        self::assertStringContainsString("Tpvconnect::checkRateLimit('journal-' . \$this->getClientIp())", $controller);
        self::assertStringContainsString("file_get_contents('php://input', false, null, 0, JournalStore::MAX_BODY_BYTES + 1)", $controller);
        self::assertStringContainsString("(string) Configuration::get('TPVCONNECT_SSO_SECRET')", $controller);
        self::assertStringNotContainsString('$rawBody', substr($controller, (int) strpos($controller, 'PrestaShopLogger')));
    }

    /**
     * A signed request through JournalStore::respond(), as the controller sends it.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(array $data): array
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        [$status, $answer] = (new JournalStore($this->storage))->respond($body, 'HMAC-SHA256 ' . hash_hmac('sha256', $body, self::SECRET), self::SECRET);

        // What the TPV receives: the answer through JSON.
        return [$status, json_decode((string) json_encode($answer), true)];
    }

    /** @return array<string, mixed> a D2 line with its line_hash */
    private static function line(int $seq, string $series, string $number, string $kind = 'F2'): array
    {
        $line = [
            'v' => 1,
            'seq' => $seq,
            'journaled_at' => '2026-11-14T18:02:11+01:00',
            'kind' => $kind,
            'series' => $series,
            'counter' => ['table' => 'ticket_sequence', 'letter' => 'A', 'year' => 2026, 'number' => $seq],
            'fiscal_record' => [
                'id' => 900 + $seq,
                'invoice_type' => $kind,
                'number' => $number,
                'hash' => strtoupper(hash('sha256', $number)),
                'previous_hash' => '',
                'generated_at' => '2026-11-14T18:02:10+01:00',
                'total' => '12.50',
            ],
            'rows' => ['order_meta' => ['id' => 1500 + $seq, 'note' => 'Año <3 € / café', 'discount' => 0.0], 'sale_line' => []],
            'backfill' => false,
            'prev' => $seq === 1 ? '' : hash('sha256', 'previous ' . $seq),
        ];
        $line['line_hash'] = self::hashOf($line);

        return $line;
    }

    /** @param array<string, mixed> $line */
    private static function hashOf(array $line): string
    {
        unset($line['line_hash']);

        return hash('sha256', (string) json_encode(self::sortKeys($line), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Plain PHP canonical form: object keys sorted by byte order at every depth, lists kept. */
    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeys($item);
        }

        return $value;
    }

    /** @return iterable<string, array{0: string}> */
    public static function vectors(): iterable
    {
        yield 'ticket F2' => ['journal-line-vector.json'];
        yield 'coupon sale F2, counter null' => ['journal-line-vector-coupon.json'];
    }

    /** @return array{line_without_hash: array<string, mixed>, canonical_json: string, line_hash: string} */
    private function vector(string $file): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $file), true);
    }
}
