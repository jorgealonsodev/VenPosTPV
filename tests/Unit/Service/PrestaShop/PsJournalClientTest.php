<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\PrestaShop;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Fiscal\Journal\CanonicalJson;
use VenPosTPV\Service\Fiscal\Journal\JournalLocation;
use VenPosTPV\Service\PrestaShop\PsJournalClient;

/**
 * The shop copy of the fiscal journal (fiscal-journal D6, S8, S9): one line per
 * POST /module/tpvconnect/journal, signed like the other module endpoints, and the
 * `head` action. Each module answer maps to what the outbox does with the row.
 */
final class PsJournalClientTest extends TestCase
{
    private const JOURNAL = '6f1c2a4e-1b2c-4d3e-8f90-0123456789ab';
    private const SECRET = 'shared-secret';

    /** @var list<array{method: string, url: string, body: string, headers: array<int, string>}> */
    private array $requests = [];

    /** @param list<MockResponse|\Throwable> $responses */
    private function client(array $responses): PsJournalClient
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) $options['body'], 'headers' => $options['headers']];
            $next = array_shift($responses);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });

        return new PsJournalClient('https://tienda.tutienda.com/', self::SECRET, $mock);
    }

    private static function json(array $body, int $status): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['http_code' => $status, 'response_headers' => ['Content-Type: application/json']]);
    }

    /** @return array{line: array<string, mixed>, raw: string, hash: string} */
    private static function vector(string $file): array
    {
        $vector = json_decode((string) file_get_contents(__DIR__ . '/../../../../modules/tpvconnect/tests/fixtures/' . $file), true, 512, JSON_THROW_ON_ERROR);
        $line = $vector['line_without_hash'] + ['line_hash' => $vector['line_hash']];

        return ['line' => $line, 'raw' => rtrim(JournalLocation::encode($line), "\n"), 'hash' => $vector['line_hash']];
    }

    public function testALineIsPostedVerbatimSignedOverTheExactBody(): void
    {
        $vector = self::vector('journal-line-vector.json');
        $result = $this->client([self::json(['stored' => true, 'duplicate' => false], 201)])->send(self::JOURNAL, $vector['raw']);

        self::assertSame(PsJournalClient::STORED, $result['status']);
        self::assertSame(201, $result['http']);
        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://tienda.tutienda.com/module/tpvconnect/journal', $request['url']);
        self::assertSame('{"journal_id":"' . self::JOURNAL . '","line":' . $vector['raw'] . '}', $request['body'], 'the line goes as written in the journal');
        self::assertContains('Authorization: HMAC-SHA256 ' . hash_hmac('sha256', $request['body'], self::SECRET), $request['headers']);
        self::assertContains('Content-Type: application/json', $request['headers']);
    }

    public function testWhatTheModuleReceivesHashesLikeBothSharedVectors(): void
    {
        foreach (['journal-line-vector.json', 'journal-line-vector-coupon.json'] as $file) {
            $vector = self::vector($file);
            $this->requests = [];
            $this->client([self::json(['stored' => true, 'duplicate' => false], 201)])->send(self::JOURNAL, $vector['raw']);

            $received = json_decode($this->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR)['line'];
            self::assertSame($vector['hash'], CanonicalJson::lineHash($received), $file);
            self::assertSame($vector['hash'], $received['line_hash'], $file);
        }
        self::assertNull(self::vector('journal-line-vector-coupon.json')['line']['counter'], 'the coupon vector has no counter');
    }

    public function testEachModuleAnswerMapsToWhatTheOutboxDoes(): void
    {
        $raw = self::vector('journal-line-vector.json')['raw'];
        $cases = [
            'duplicate is success' => [self::json(['stored' => false, 'duplicate' => true], 200), PsJournalClient::DUPLICATE],
            'module too old (404 page)' => [new MockResponse('<html>Page not found</html>', ['http_code' => 404]), PsJournalClient::UNAVAILABLE],
            'not JSON (shop home page)' => [new MockResponse('<html>Shop</html>', ['http_code' => 200]), PsJournalClient::UNAVAILABLE],
            'conflict' => [self::json(['error' => 'Conflict', 'details' => ['seq' => 'Another line is stored at this position']], 409), PsJournalClient::CONFLICT],
            'line_hash mismatch' => [self::json(['error' => 'line_hash mismatch'], 400), PsJournalClient::REJECTED],
            'too large' => [self::json(['error' => 'Payload too large'], 413), PsJournalClient::REJECTED],
            'invalid' => [self::json(['error' => 'Validation failed', 'details' => ['line.series' => 'x']], 422), PsJournalClient::REJECTED],
            'rate limited' => [self::json(['error' => 'Rate limit exceeded'], 429), PsJournalClient::RETRY],
            'shop error' => [self::json(['error' => 'Journal storage failed'], 500), PsJournalClient::RETRY],
            'bad signature (wrong shared secret)' => [self::json(['error' => 'Unauthorized'], 401), PsJournalClient::UNAUTHORIZED],
            'refused before the module (not JSON)' => [new MockResponse('<html>401</html>', ['http_code' => 401]), PsJournalClient::UNAUTHORIZED],
            'network' => [new TransportException('Connection refused'), PsJournalClient::RETRY],
        ];
        foreach ($cases as $name => [$response, $expected]) {
            $result = $this->client([$response])->send(self::JOURNAL, $raw);
            self::assertSame($expected, $result['status'], $name);
            self::assertIsString($result['error'] ?? '', $name);
        }
        $conflict = $this->client([self::json(['error' => 'Conflict', 'details' => ['seq' => 'Another line is stored at this position']], 409)])->send(self::JOURNAL, $raw);
        self::assertStringContainsString('Another line is stored at this position', (string) $conflict['error'], 'the module details reach the incident');
    }

    public function testHeadAsksForTheJournalAndReturnsItsPositions(): void
    {
        $head = ['journal_id' => self::JOURNAL, 'last_seq' => 7, 'contiguous_seq' => 5, 'series' => ['A26' => ['number' => 'A26000007', 'hash' => str_repeat('a', 64), 'seq' => 7], 'PS' => ['number' => 'XKBKNABJK', 'hash' => str_repeat('b', 64), 'seq' => 3]]];
        $result = $this->client([self::json($head, 200)])->head(self::JOURNAL);

        self::assertSame(PsJournalClient::OK, $result['status']);
        self::assertSame($head, $result['head']);
        self::assertSame('{"journal_id":"' . self::JOURNAL . '","action":"head"}', $this->requests[0]['body']);
        self::assertContains('Authorization: HMAC-SHA256 ' . hash_hmac('sha256', $this->requests[0]['body'], self::SECRET), $this->requests[0]['headers']);

        $empty = $this->client([new MockResponse('{"journal_id":"' . self::JOURNAL . '","last_seq":0,"contiguous_seq":0,"series":{}}', ['http_code' => 200])])->head(self::JOURNAL);
        self::assertSame(['journal_id' => self::JOURNAL, 'last_seq' => 0, 'contiguous_seq' => 0, 'series' => []], $empty['head']);
    }

    public function testAHeadThatCannotBeTrustedIsNeverAnAnswer(): void
    {
        $cases = [
            'module too old' => [new MockResponse('<html>404</html>', ['http_code' => 404]), PsJournalClient::UNAVAILABLE],
            'not JSON' => [new MockResponse('<html>Shop</html>', ['http_code' => 200]), PsJournalClient::UNAVAILABLE],
            'another journal' => [self::json(['journal_id' => '00000000-0000-4000-8000-000000000000', 'last_seq' => 1, 'contiguous_seq' => 1, 'series' => []], 200), PsJournalClient::ERROR],
            'no contiguous_seq' => [self::json(['journal_id' => self::JOURNAL, 'last_seq' => 1, 'series' => []], 200), PsJournalClient::ERROR],
            'bad series entry' => [self::json(['journal_id' => self::JOURNAL, 'last_seq' => 1, 'contiguous_seq' => 1, 'series' => ['A26' => ['number' => 'A26000001']]], 200), PsJournalClient::ERROR],
            'shop error' => [self::json(['error' => 'x'], 500), PsJournalClient::ERROR],
            'network' => [new TransportException('timeout'), PsJournalClient::ERROR],
        ];
        foreach ($cases as $name => [$response, $expected]) {
            $result = $this->client([$response])->head(self::JOURNAL);
            self::assertSame($expected, $result['status'], $name);
            self::assertNull($result['head'], $name);
        }
    }

    public function testTheNumberRuleIsTheModuleOne(): void
    {
        foreach (['A26000124', 'R2026000001', 'F26000003', 'XKBKNABJK', 'a.b_c/d-e', str_repeat('A', 60)] as $number) {
            self::assertTrue(PsJournalClient::fitsNumber($number), $number);
        }
        foreach (['', 'PS 0001', 'PS#1', 'ÑANDU1', '<b>1', str_repeat('A', 61), "A1\n"] as $number) {
            self::assertFalse(PsJournalClient::fitsNumber($number), $number);
        }
    }
}
