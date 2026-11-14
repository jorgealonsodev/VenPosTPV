<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The shop copy of the fiscal journal (fiscal-journal D6, S8, S9) through the TPV
 * Connect module 0.9.0: POST /module/tpvconnect/journal, signed like the other
 * module endpoints (HMAC-SHA256 of the exact body with the shared secret).
 *
 * - send(): one journal line, embedded in the body exactly as the journal file holds
 *   it, so the module hashes the same canonical JSON as the TPV (CanonicalJson).
 * - head(): the positions the shop holds for one journal (last_seq, contiguous_seq,
 *   the last document of every series).
 *
 * Never throws: every answer is a status the caller acts on. A 404 or a body that
 * is not JSON is a module older than MIN_MODULE_VERSION (S9).
 */
class PsJournalClient
{
    public const MIN_MODULE_VERSION = '0.9.0';

    /** The line was stored (201). */
    public const STORED = 'stored';
    /** The shop already had this very line (200): success, nothing changed. */
    public const DUPLICATE = 'duplicate';
    /** The module has no journal endpoint (404, or not JSON): wait, never an incident (S9). */
    public const UNAVAILABLE = 'unavailable';
    /** Another line at that position, or the document at another position (409): an incident, never retried blindly. */
    public const CONFLICT = 'conflict';
    /** The module refused this line for good (400 line_hash, 413 too large, 422 invalid). */
    public const REJECTED = 'rejected';
    /** Network, timeouts, 401, 429, 5xx: the outcome is unknown, sending again is safe (the module dedupes). */
    public const RETRY = 'retry';
    /** head(): the answer. */
    public const OK = 'ok';
    /** head(): no usable answer (network, 5xx, a body of another shape or another journal). */
    public const ERROR = 'error';

    /** The characters the module accepts in a document number (JournalStore::NUMBER). */
    private const NUMBER = '/^[A-Za-z0-9._\/-]{1,60}$/D';

    private const TIMEOUT_SECONDS = 15;

    private readonly string $shopUrl;

    public function __construct(
        string $shopUrl,
        private readonly string $sharedSecret,
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->shopUrl = rtrim($shopUrl, '/');
    }

    /**
     * Whether the module can store a document with this number (open point O6: a
     * shop's order reference, the number of a coupon sale, may hold other characters).
     */
    public static function fitsNumber(string $number): bool
    {
        return preg_match(self::NUMBER, $number) === 1;
    }

    /**
     * @param string $line the journal line, one JSON object as written in the journal file
     *
     * @return array{status: string, http: ?int, error: ?string}
     */
    public function send(string $journalId, string $line): array
    {
        $body = '{"journal_id":' . json_encode($journalId, \JSON_THROW_ON_ERROR) . ',"line":' . $line . '}';
        [$status, $content, $error] = $this->post($body);
        if ($status === null) {
            return ['status' => self::RETRY, 'http' => null, 'error' => $error];
        }
        $data = json_decode($content, true);
        if ($status === 404 || !\is_array($data)) {
            return ['status' => self::UNAVAILABLE, 'http' => $status, 'error' => sprintf('The shop has no journal endpoint (HTTP %d): TPV Connect %s or later is needed', $status, self::MIN_MODULE_VERSION)];
        }
        $detail = self::detail($status, $data);

        return match (true) {
            $status === 201 => ['status' => self::STORED, 'http' => 201, 'error' => null],
            $status === 200 && ($data['duplicate'] ?? null) === true => ['status' => self::DUPLICATE, 'http' => 200, 'error' => null],
            $status === 409 => ['status' => self::CONFLICT, 'http' => 409, 'error' => $detail],
            \in_array($status, [400, 413, 422], true) => ['status' => self::REJECTED, 'http' => $status, 'error' => $detail],
            default => ['status' => self::RETRY, 'http' => $status, 'error' => $detail],
        };
    }

    /**
     * @return array{status: string, head: ?array{journal_id: string, last_seq: int, contiguous_seq: int, series: array<string, array{number: string, hash: string, seq: int}>}, error: ?string}
     */
    public function head(string $journalId): array
    {
        $body = '{"journal_id":' . json_encode($journalId, \JSON_THROW_ON_ERROR) . ',"action":"head"}';
        [$status, $content, $error] = $this->post($body);
        if ($status === null) {
            return ['status' => self::ERROR, 'head' => null, 'error' => $error];
        }
        $data = json_decode($content, true);
        if ($status === 404 || !\is_array($data)) {
            return ['status' => self::UNAVAILABLE, 'head' => null, 'error' => sprintf('The shop has no journal endpoint (HTTP %d)', $status)];
        }
        if ($status !== 200) {
            return ['status' => self::ERROR, 'head' => null, 'error' => self::detail($status, $data)];
        }
        $head = self::validHead($journalId, $data);

        return $head === null
            ? ['status' => self::ERROR, 'head' => null, 'error' => 'The shop answered the journal head in another shape']
            : ['status' => self::OK, 'head' => $head, 'error' => null];
    }

    /** @return array{0: ?int, 1: string, 2: ?string} status (null when nothing was answered), body, error */
    private function post(string $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->shopUrl . '/module/tpvconnect/journal', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'HMAC-SHA256 ' . hash_hmac('sha256', $body, $this->sharedSecret),
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);

            return [$response->getStatusCode(), $response->getContent(false), null];
        } catch (\Throwable $e) {
            return [null, '', 'The shop could not be reached: ' . $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $data */
    private static function detail(int $status, array $data): string
    {
        $text = \is_string($data['error'] ?? null) ? $data['error'] : 'error';
        if (isset($data['details'])) {
            $text .= ' ' . json_encode($data['details'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }

        return sprintf('The shop answered HTTP %d: %s', $status, $text);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{journal_id: string, last_seq: int, contiguous_seq: int, series: array<string, array{number: string, hash: string, seq: int}>}|null
     */
    private static function validHead(string $journalId, array $data): ?array
    {
        if (!\is_string($data['journal_id'] ?? null) || strcasecmp($data['journal_id'], $journalId) !== 0
            || !\is_int($data['last_seq'] ?? null) || !\is_int($data['contiguous_seq'] ?? null) || !\is_array($data['series'] ?? null)) {
            return null;
        }
        $series = [];
        foreach ($data['series'] as $name => $entry) {
            if (!\is_array($entry) || !\is_string($entry['number'] ?? null) || !\is_string($entry['hash'] ?? null) || !\is_int($entry['seq'] ?? null)) {
                return null;
            }
            $series[(string) $name] = ['number' => $entry['number'], 'hash' => $entry['hash'], 'seq' => $entry['seq']];
        }

        return ['journal_id' => $data['journal_id'], 'last_seq' => $data['last_seq'], 'contiguous_seq' => $data['contiguous_seq'], 'series' => $series];
    }
}
