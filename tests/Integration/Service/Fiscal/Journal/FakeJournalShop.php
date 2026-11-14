<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\PrestaShop\PsJournalClient;

/**
 * A shop for the journal upload tests: every request goes to the module's own
 * JournalStore (fixtures/fake_journal_module.php, a PHP process per request, its
 * table in a JSON file), or answers as a shop with an older module (404 page) or
 * one that cannot be reached. Never contacts any real shop.
 */
final class FakeJournalShop
{
    public const SECRET = 'journal-test-secret';
    public const MODULE = 'module';
    public const OLD_MODULE = 'old';
    public const DOWN = 'down';

    public string $mode = self::MODULE;
    /** @var list<int> statuses to answer instead of the module, once each, in order */
    public array $failNext = [];
    /** @var list<array{action: ?string, seq: ?int, number: ?string, status: int}> every request, in order */
    public array $requests = [];

    public function __construct(public readonly string $store)
    {
    }

    public function client(): PsJournalClient
    {
        return new PsJournalClient('https://tienda.tutienda.com', self::SECRET, new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->answer($url, $options)));
    }

    /** @return list<array<string, mixed>> the stored rows */
    public function rows(): array
    {
        return is_file($this->store) ? json_decode((string) file_get_contents($this->store), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /** @param list<array<string, mixed>> $rows */
    public function setRows(array $rows): void
    {
        file_put_contents($this->store, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<int> the seq of every line posted, in order */
    public function sentSeqs(): array
    {
        return array_values(array_map(static fn (array $r): int => (int) $r['seq'], array_filter($this->requests, static fn (array $r): bool => $r['seq'] !== null)));
    }

    /** @param array<string, mixed> $options */
    private function answer(string $url, array $options): MockResponse
    {
        $body = (string) $options['body'];
        $data = json_decode($body, true);
        $entry = ['action' => $data['action'] ?? null, 'seq' => $data['line']['seq'] ?? null, 'number' => $data['line']['fiscal_record']['number'] ?? null, 'status' => 0];
        if ($url !== 'https://tienda.tutienda.com/module/tpvconnect/journal') {
            throw new \LogicException('unexpected URL ' . $url);
        }
        if ($this->mode === self::DOWN) {
            $this->requests[] = $entry;

            throw new TransportException('Connection timed out');
        }
        if ($this->mode === self::OLD_MODULE) {
            $this->requests[] = ['status' => 404] + $entry;

            return new MockResponse('<!doctype html><title>Página no encontrada</title>', ['http_code' => 404]);
        }
        if ($this->failNext !== []) {
            $status = array_shift($this->failNext);
            $this->requests[] = ['status' => $status] + $entry;

            return new MockResponse('{"error":"Journal storage failed"}', ['http_code' => $status]);
        }
        $auth = '';
        foreach ($options['headers'] as $header) {
            if (str_starts_with($header, 'Authorization: ')) {
                $auth = substr($header, \strlen('Authorization: '));
            }
        }
        $process = proc_open([\PHP_BINARY, __DIR__ . '/fixtures/fake_journal_module.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['store' => $this->store, 'secret' => self::SECRET, 'body' => $body, 'auth' => $auth], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $response = json_decode((string) $out, true);
        if (!\is_array($response)) {
            throw new \RuntimeException('fake module failed: ' . $out . $err);
        }
        $this->requests[] = ['status' => (int) $response['status']] + $entry;

        return new MockResponse((string) $response['body'], ['http_code' => (int) $response['status'], 'response_headers' => ['Content-Type: application/json']]);
    }
}
