<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\PrintAgent;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use VenPosTPV\Controller\Api\AgentApiController;
use VenPosTPV\Controller\Api\PrintAgentController;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\AgentReleasePolicy;
use VenPosTPV\Service\PrintAgent\DrawerAudit;
use VenPosTPV\Service\PrintAgent\LongPollLink;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Service\PrintAgent\RateLimiter;
use VenPosTPV\Tests\Support\JsonKeys;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/agente-impresion.md E1-E9, S1, U1/U4 and the agent API v1 table, through
 * the controllers on MySQL. Agent requests carry no session at all: any session
 * access would throw, which proves E9.
 */
final class AgentApiMySqlIntegrationTest extends TestCase
{
    use PrintAgentFixtures;

    private const SECRET = 'agent-api-test-secret';
    private const ADMIN = ['user_id' => 1, 'role' => 'admin'];

    private ?Connection $connection = null;
    private MockClock $clock;
    private AgentRegistry $registry;
    private PrintJobQueue $queue;
    private int $tillA = 0;
    private int $tillB = 0;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->cleanPrintAgentFixtures($this->connection);
        $this->clock = new MockClock('2026-11-03 10:00:00');
        $this->registry = new AgentRegistry($this->connection, self::SECRET, $this->clock);
        $this->queue = new PrintJobQueue($this->connection, $this->clock);
        $this->tillA = $this->till($this->connection, 'PC-CAJA1');
        $this->tillB = $this->till($this->connection, 'PC-CAJA2');
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanPrintAgentFixtures($this->connection);
            $this->connection->close();
        }
    }

    private function api(?AgentReleasePolicy $releases = null, ?LongPollLink $link = null): AgentApiController
    {
        return new AgentApiController($this->registry, $this->queue, new RateLimiter($this->connection, $this->clock), $releases ?? new AgentReleasePolicy(), $this->clock, $link ?? self::link());
    }

    /**
     * A long poll's client: each second of the wait asks `$onSecond` (with the second
     * number) whether the client is still there; it writes the space like the real one
     * but does not flush the test's output buffer.
     *
     * @param (callable(int): bool)|null $onSecond
     */
    private static function link(?callable $onSecond = null): LongPollLink
    {
        return new class ($onSecond) extends LongPollLink {
            private int $second = 0;

            /** @param (callable(int): bool)|null $onSecond */
            public function __construct(private $onSecond)
            {
            }

            public function hold(): void
            {
            }

            public function alive(): bool
            {
                echo ' ';

                return $this->onSecond === null || ($this->onSecond)(++$this->second);
            }
        };
    }

    /** The body as the client receives it (a long poll streams it). */
    private static function content(Response $response): string
    {
        if (!$response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function pos(?AgentReleasePolicy $releases = null): PrintAgentController
    {
        $printer = new PrinterService($this->connection, $this->createMock(TicketDataResolver::class), new EscPosTicketBuilder(), $this->createMock(TicketPdfGenerator::class), printJobs: $this->queue);

        return new PrintAgentController($this->connection, $this->registry, $releases ?? new AgentReleasePolicy(), $printer);
    }

    /** @param array<string, mixed>|null $body */
    private function agentRequest(string $method, string $path, ?string $key, ?array $body = null, array $query = [], string $ip = '10.0.0.5'): Request
    {
        $server = ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'];
        if ($key !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        }

        return Request::create($path, $method, $query, [], [], $server, $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null);
    }

    /** @param array<string, mixed> $session */
    private function posRequest(string $method, string $path, array $session, bool $json = true): Request
    {
        $request = Request::create($path, $method, [], [], [], $json ? ['CONTENT_TYPE' => 'application/json'] : [], $json ? '{}' : '');
        $store = new Session(new MockArraySessionStorage());
        foreach ($session as $k => $v) {
            $store->set($k, $v);
        }
        $request->setSession($store);

        return $request;
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        return json_decode(self::content($response), true, 512, JSON_THROW_ON_ERROR);
    }

    private function code(int $till): string
    {
        $response = $this->pos()->pairingCode($this->posRequest('POST', '/api/terminals/' . $till . '/print-agent/pairing-code', self::ADMIN), $till);
        $this->assertSame(201, $response->getStatusCode());

        return self::body($response)['code'];
    }

    private function pairAgent(int $till, string $ip = '10.0.0.5'): string
    {
        $response = $this->api()->pair($this->agentRequest('POST', '/api/agent/v1/pair', null, ['code' => $this->code($till), 'hostname' => 'PC-CAJA', 'os' => 'windows', 'version' => '1.0.0'], [], $ip));
        $this->assertSame(201, $response->getStatusCode());

        return self::body($response)['key'];
    }

    public function testPairingCodesAreAdminOnlyJsonOnlyStoredAsHashAndOnePerTill(): void
    {
        $pos = $this->pos();
        $this->assertSame(403, $pos->pairingCode($this->posRequest('POST', '/x', ['user_id' => 2, 'role' => 'cashier']), $this->tillA)->getStatusCode());
        $this->assertSame(415, $pos->pairingCode($this->posRequest('POST', '/x', self::ADMIN, false), $this->tillA)->getStatusCode(), 'JSON only');
        $this->assertSame(404, $pos->pairingCode($this->posRequest('POST', '/x', self::ADMIN), 999999)->getStatusCode());

        $response = $pos->pairingCode($this->posRequest('POST', '/x', self::ADMIN), $this->tillA);
        $created = self::body($response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertMatchesRegularExpression('/\A\d{6}\z/', $created['code']);
        $this->assertSame((new \DateTimeImmutable('2026-11-03 10:10:00', $this->clock->now()->getTimezone()))->format(\DATE_ATOM), $created['expires_at'], '10 minutes');

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM agent_pairing WHERE terminal_id = ?', [$this->tillA]);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['created_by_user_id'], 'tied to the admin (E2)');
        $this->assertNotSame($created['code'], $rows[0]['code_hash']);
        $this->assertStringNotContainsString($created['code'], json_encode($rows, JSON_THROW_ON_ERROR) . json_encode($this->connection->fetchAllAssociative('SELECT * FROM audit_log WHERE terminal_id = ?', [$this->tillA]), JSON_THROW_ON_ERROR), 'the code is never stored or logged (E8)');
        $this->assertNotSame(hash('sha256', $created['code']), $rows[0]['code_hash'], 'not a plain hash of a 6-digit code');

        $second = self::body($pos->pairingCode($this->posRequest('POST', '/x', self::ADMIN), $this->tillA))['code'];
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM agent_pairing WHERE terminal_id = ?', [$this->tillA]), 'one live code per till');
        $pair = $this->api()->pair($this->agentRequest('POST', '/api/agent/v1/pair', null, ['code' => $created['code'], 'hostname' => 'h', 'os' => 'linux', 'version' => '1.0.0']));
        if ($created['code'] !== $second) {
            $this->assertSame(422, $pair->getStatusCode(), 'the previous code is void');
        }
    }

    public function testPairingGivesA256BitKeyOnceAndStoresOnlyItsHash(): void
    {
        $code = $this->code($this->tillA);
        $request = $this->agentRequest('POST', '/api/agent/v1/pair', null, ['code' => $code, 'hostname' => 'PC-CAJA1', 'os' => 'windows', 'version' => '1.0.0']);

        $response = $this->api()->pair($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), 'E3');
        $this->assertFalse($response->headers->has('Set-Cookie'), 'E9');
        $paired = self::body($response);
        $this->assertSame(['key', 'terminal_id', 'terminal_name'], array_keys($paired));
        $this->assertSame($this->tillA, $paired['terminal_id']);
        $this->assertSame('PC-CAJA1', $paired['terminal_name']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $paired['key']);
        $this->assertSame(32, \strlen(base64_decode(strtr($paired['key'], '-_', '+/') . '=', true)), '256 bits');

        $agent = $this->connection->fetchAssociative('SELECT * FROM print_agent WHERE terminal_id = ?', [$this->tillA]);
        $this->assertSame(hash('sha256', $paired['key']), $agent['key_hash']);
        $this->assertStringNotContainsString($paired['key'], json_encode($agent, JSON_THROW_ON_ERROR));
        $this->assertSame(['PC-CAJA1', 'windows', '1.0.0', '10.0.0.5', 1], [$agent['hostname'], $agent['os'], $agent['agent_version'], $agent['last_ip'], (int) $agent['paired_by_user_id']]);
        $this->assertNotNull($this->connection->fetchOne('SELECT used_at FROM agent_pairing WHERE terminal_id = ?', [$this->tillA]), 'the code is used in the same transaction');

        $again = $this->api()->pair($this->agentRequest('POST', '/api/agent/v1/pair', null, ['code' => $code, 'hostname' => 'x', 'os' => 'linux', 'version' => '1.0.0'], [], '10.0.0.6'));
        $this->assertSame(422, $again->getStatusCode(), 'single use');
        $this->assertSame('pairing_code_invalid', self::body($again)['code']);
    }

    public function testAnExpiredCodeFails(): void
    {
        $expired = $this->code($this->tillA);
        $this->clock->sleep(600);
        $this->assertSame(422, $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => $expired, 'hostname' => 'h', 'os' => 'o', 'version' => '1.0.0']))->getStatusCode(), 'expired after 10 minutes');
    }

    public function testFailuresFromAnywhereVoidTheLiveCodesOnlyAt20InTenMinutes(): void
    {
        $fail = function (int $n, string $net): void {
            for ($i = 0; $i < $n; ++$i) {
                // Different IPs, so the per-IP limit (5 per minute) does not stop them.
                $response = $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => 'abcdef', 'hostname' => 'h', 'os' => 'o', 'version' => '1.0.0'], [], $net . $i));
                $this->assertSame(422, $response->getStatusCode());
            }
        };
        $pair = fn (string $code, string $ip): int => $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => $code, 'hostname' => 'h', 'os' => 'o', 'version' => '1.0.0'], [], $ip))->getStatusCode();

        $fail(10, '10.1.0.');
        $this->clock->sleep(601);
        $code = $this->code($this->tillA);
        $fail(9, '10.3.0.');
        $this->assertSame(201, $pair($code, '10.2.0.1'), 'E7: 5 failures no longer void the code, and those of 10 minutes ago do not count');

        $code = $this->code($this->tillB);
        $fail(10, '10.4.0.');
        $this->assertSame(201, $pair($this->code($this->tillA), '10.2.0.3'), '19 failures in 10 minutes: still not voided');
        $fail(1, '10.5.0.');
        $this->assertSame(422, $pair($code, '10.2.0.2'), 'the 20th failure in 10 minutes voids every live code');
    }

    public function testTwentyFailuresInTenMinutesFromAllIpsVoidTheLiveCodes(): void
    {
        $code = $this->code($this->tillB);
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($i = 0; $i < 20; ++$i) {
            $response = $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => $wrong, 'hostname' => 'h', 'os' => 'o', 'version' => '1.0.0'], [], '10.1.0.' . $i));
            $this->assertSame(422, $response->getStatusCode());
            $this->clock->sleep(25);
        }

        $response = $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => $code, 'hostname' => 'h', 'os' => 'o', 'version' => '1.0.0'], [], '10.2.0.1'));
        $this->assertSame(422, $response->getStatusCode(), '20 failures in 10 minutes void the code (E7)');
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM print_agent WHERE terminal_id = ?', [$this->tillB]));

        $fresh = $this->code($this->tillB);
        $this->assertSame(201, $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => $fresh, 'hostname' => 'h', 'os' => 'o', 'version' => '1.0.0'], [], '10.2.0.2'))->getStatusCode(), 'a code made afterwards works');
    }

    public function testPairingAllowsFiveAttemptsPerMinuteAndIp(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(422, $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => 'abc']))->getStatusCode());
        }
        $limited = $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => $this->code($this->tillA)]));
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame('60', $limited->headers->get('Retry-After'));
        $this->assertSame(201, $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => $this->code($this->tillA)], [], '10.9.9.9'))->getStatusCode(), 'per IP');

        $this->clock->sleep(60);
        $this->assertSame(422, $this->api()->pair($this->agentRequest('POST', '/p', null, ['code' => 'abc']))->getStatusCode(), 'a new minute');
    }

    public function testAnInvalidKeyGets401AndThen429AfterTenFailuresPerMinute(): void
    {
        $key = $this->pairAgent($this->tillA, '10.7.7.7');
        foreach ([null, 'short', str_repeat('A', 43)] as $bad) {
            $response = $this->api()->jobs($this->agentRequest('GET', '/api/agent/v1/jobs', $bad));
            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame('agent_key_invalid', self::body($response)['code']);
            $this->assertSame('Vuelve a conectar el agente desde Ajustes → Cajas', self::body($response)['error']);
        }
        for ($i = 0; $i < 7; ++$i) {
            $this->assertSame(401, $this->api()->jobs($this->agentRequest('GET', '/j', str_repeat('B', 43)))->getStatusCode());
        }
        $this->assertSame(429, $this->api()->jobs($this->agentRequest('GET', '/j', str_repeat('B', 43)))->getStatusCode(), 'the 11th failure');
        $this->assertSame(200, $this->api()->jobs($this->agentRequest('GET', '/j', $key, null, [], '10.7.7.7'))->getStatusCode(), 'a valid key still works');

        $query = Request::create('/api/agent/v1/jobs', 'GET', ['key' => $key, 'access_token' => $key], [], [], ['REMOTE_ADDR' => '10.8.8.8']);
        $this->assertSame(401, $this->api()->jobs($query)->getStatusCode(), 'only the Authorization header (E9)');
        $cookie = Request::create('/api/agent/v1/jobs', 'GET', [], ['key' => $key], [], ['REMOTE_ADDR' => '10.8.8.8']);
        $this->assertSame(401, $this->api()->jobs($cookie)->getStatusCode(), 'never a cookie');
    }

    public function testEveryCallRecordsTheLastUseAndIp(): void
    {
        $key = $this->pairAgent($this->tillA);
        $this->clock->sleep(300);

        $this->api()->jobs($this->agentRequest('GET', '/j', $key, null, [], '192.168.1.20'));

        $this->assertSame(['2026-11-03 10:05:00', '192.168.1.20'], array_values($this->connection->fetchAssociative('SELECT last_seen_at, last_ip FROM print_agent WHERE terminal_id = ?', [$this->tillA])));
        $status = self::body($this->pos()->status($this->posRequest('GET', '/s', ['user_id' => 2, 'role' => 'cashier'], false), $this->tillA));
        $this->assertTrue($status['connected']);
        $this->assertSame('192.168.1.20', $status['last_ip']);
        $this->assertSame('1.0.0', $status['version']);
        $this->clock->sleep(90);
        $this->assertFalse(self::body($this->pos()->status($this->posRequest('GET', '/s', self::ADMIN, false), $this->tillA))['connected'], 'not connected after 90 s');
        $this->assertSame(401, $this->pos()->status($this->posRequest('GET', '/s', [], false), $this->tillA)->getStatusCode());
    }

    public function testJobsOfTheAgentsTillOnlyAndTheirResult(): void
    {
        $keyA = $this->pairAgent($this->tillA);
        $keyB = $this->pairAgent($this->tillB, '10.0.0.9');
        $jobA = $this->queue->enqueue($this->tillA, 'ticket', "\x1B@A\x80", 'sale');
        $jobB = $this->queue->enqueue($this->tillB, 'ticket', 'B', 'sale');

        $this->clock->sleep(5);
        $response = $this->api()->jobs($this->agentRequest('GET', '/api/agent/v1/jobs', $keyA, null, ['wait' => '0']));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->clock->now()->getTimestamp(), $response->getDate()?->getTimestamp(), 'Date header from the server clock');
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d\z/', self::body($response)['jobs'][0]['expires_at'], 'RFC 3339');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $body = self::body($response);
        $this->assertSame(['jobs', 'poll_after_ms', 'server_time_ms'], array_keys($body));
        $this->assertSame($this->clock->now()->getTimestamp() * 1000, $body['server_time_ms']);
        $this->assertSame([$jobA], array_column($body['jobs'], 'id'), 'E4: only its own till');
        $this->assertSame(['id', 'type', 'target', 'payload_b64', 'sha256', 'claim_token', 'expires_at'], array_keys($body['jobs'][0]));
        $this->assertSame("\x1B@A\x80", base64_decode($body['jobs'][0]['payload_b64'], true));
        $token = $body['jobs'][0]['claim_token'];

        $tokenB = self::body($this->api()->jobs($this->agentRequest('GET', '/j', $keyB, null, [], '10.0.0.9')))['jobs'][0]['claim_token'];
        $cross = $this->api()->result($this->agentRequest('POST', '/r', $keyA, ['claim_token' => $tokenB, 'status' => 'done']), $jobB);
        $this->assertSame(404, $cross->getStatusCode(), 'AC4: another till\'s job does not exist for this key');

        $done = $this->api()->result($this->agentRequest('POST', '/r', $keyA, ['claim_token' => $token, 'status' => 'done']), $jobA);
        $this->assertSame([200, ['status' => 'done']], [$done->getStatusCode(), self::body($done)]);
        $this->assertSame(200, $this->api()->result($this->agentRequest('POST', '/r', $keyA, ['claim_token' => $token, 'status' => 'done']), $jobA)->getStatusCode(), 'repeated');
        $this->assertSame(422, $this->api()->result($this->agentRequest('POST', '/r', $keyA, ['claim_token' => $token, 'status' => 'printed']), $jobA)->getStatusCode());
        $this->assertSame(404, $this->api()->result($this->agentRequest('POST', '/r', $keyA, ['claim_token' => $token, 'status' => 'done']), PHP_INT_MAX)->getStatusCode());
    }

    public function testResultCodesForStaleAndExpiredClaimsAndExtend(): void
    {
        $key = $this->pairAgent($this->tillA);
        $label = $this->queue->enqueue($this->tillA, 'label', 'L', 'label');
        $drawer = $this->queue->enqueue($this->tillA, 'drawer', null, 'drawer');
        $jobs = self::body($this->api()->jobs($this->agentRequest('GET', '/j', $key)))['jobs'];
        [$drawerToken, $labelToken] = [$jobs[0]['claim_token'], $jobs[1]['claim_token']];

        $extend = $this->api()->extend($this->agentRequest('POST', '/e', $key, ['claim_token' => $labelToken]), $label);
        $this->assertSame(200, $extend->getStatusCode());
        $this->assertSame(['lease_until'], array_keys(self::body($extend)));
        $this->assertSame(409, $this->api()->extend($this->agentRequest('POST', '/e', $key, ['claim_token' => 'nope']), $label)->getStatusCode());

        $this->clock->sleep(16);
        $late = $this->api()->result($this->agentRequest('POST', '/r', $key, ['claim_token' => $drawerToken, 'status' => 'done']), $drawer);
        $this->assertSame([410, 'job_expired', false], [$late->getStatusCode(), self::body($late)['code'], self::body($late)['retry']]);
        $this->assertSame(['error', 'code', 'retry'], array_keys(self::body($late)), 'the API error shape');
        $reported = $this->api()->result($this->agentRequest('POST', '/r', $key, ['claim_token' => $drawerToken, 'status' => 'failed', 'error_code' => 'job_expired']), $drawer);
        $this->assertSame([200, ['status' => 'expired']], [$reported->getStatusCode(), self::body($reported)], 'the agent reports an expired job as failed/job_expired');

        $this->clock->sleep(60);
        self::body($this->api()->jobs($this->agentRequest('GET', '/j', $key)));
        $stale = $this->api()->result($this->agentRequest('POST', '/r', $key, ['claim_token' => $labelToken, 'status' => 'done']), $label);
        $this->assertSame([409, 'claim_stale'], [$stale->getStatusCode(), self::body($stale)['code']]);

        $failed = $this->api()->result($this->agentRequest('POST', '/r', $key, ['claim_token' => 'x', 'status' => 'failed', 'error' => 'Authorization: Bearer ' . $key]), $label);
        $this->assertSame(409, $failed->getStatusCode());
    }

    public function testTheLongPollWaitsUpTo20SecondsCheckingEverySecond(): void
    {
        $key = $this->pairAgent($this->tillA);
        $start = $this->clock->now()->getTimestamp();

        $body = self::body($this->api()->jobs($this->agentRequest('GET', '/j', $key, null, ['wait' => '60'])));

        $this->assertSame([], $body['jobs']);
        $this->assertSame(20, $this->clock->now()->getTimestamp() - $start, 'wait is capped at 20 s');
        $this->assertSame(0, $body['poll_after_ms']);
        $this->assertSame(2000, self::body($this->api()->jobs($this->agentRequest('GET', '/j', $key, null, ['wait' => '0'])))['poll_after_ms']);
    }

    public function testAWaitingLongPollStreamsSpacesThenValidJsonAndClaimsWithA30SecondLease(): void
    {
        $key = $this->pairAgent($this->tillA);
        $start = $this->clock->now()->getTimestamp();
        $job = 0;
        $link = self::link(function (int $second) use (&$job): bool {
            if ($second === 3) {
                $job = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
            }

            return true;
        });

        $response = $this->api(null, $link)->jobs($this->agentRequest('GET', '/j', $key, null, ['wait' => '20']));

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame($start, $response->getDate()?->getTimestamp());
        $content = self::content($response);
        $this->assertStringStartsWith('   {', $content, 'one space per second of the wait, then the JSON');
        $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([$job], array_column($body['jobs'], 'id'));
        $this->assertSame(0, $body['poll_after_ms']);
        $this->assertSame(($start + 3) * 1000, $body['server_time_ms'], 'the clock when the body is written');
        $this->assertSame(
            ['claimed', '2026-11-03 10:00:33'],
            array_values($this->connection->fetchAssociative('SELECT status, lease_until FROM print_job WHERE id = ?', [$job])),
            'a long poll claim has a 30 s lease'
        );
    }

    public function testAJobFoundAtOnceByALongPollIsAPlainAnswerWithA30SecondLease(): void
    {
        $key = $this->pairAgent($this->tillA);
        $job = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');

        $response = $this->api()->jobs($this->agentRequest('GET', '/j', $key, null, ['wait' => '20']));

        $this->assertNotInstanceOf(StreamedResponse::class, $response);
        $this->assertSame([$job], array_column(self::body($response)['jobs'], 'id'));
        $this->assertSame('2026-11-03 10:00:30', $this->connection->fetchOne('SELECT lease_until FROM print_job WHERE id = ?', [$job]));
        $short = $this->queue->enqueue($this->tillA, 'ticket', 'T2', 'sale');
        $this->api()->jobs($this->agentRequest('GET', '/j', $key, null, ['wait' => '0']));
        $this->assertSame('2026-11-03 10:01:00', $this->connection->fetchOne('SELECT lease_until FROM print_job WHERE id = ?', [$short]), 'a short poll keeps 60 s');
    }

    public function testAnAgentThatHasGoneDuringTheWaitClaimsNothing(): void
    {
        $key = $this->pairAgent($this->tillA);
        $job = 0;
        $link = self::link(function (int $second) use (&$job): bool {
            if ($second === 2) {
                $job = $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
            }

            // The job arrives in the same second the dead connection is noticed.
            return $second < 2;
        });

        $content = self::content($this->api(null, $link)->jobs($this->agentRequest('GET', '/j', $key, null, ['wait' => '20'])));

        $this->assertSame('  ', $content, 'no body for a client that has gone');
        $this->assertSame(
            ['pending', null, null],
            array_values($this->connection->fetchAssociative('SELECT status, claim_token, claimed_at FROM print_job WHERE id = ?', [$job])),
            'the job stays in the queue for the POS to see the agent is not connected (C1)'
        );
    }

    public function testAKeyRevokedDuringTheWaitGetsNothingAndThen401(): void
    {
        $key = $this->pairAgent($this->tillA);
        $link = self::link(function (int $second): bool {
            if ($second === 2) {
                $this->queue->enqueue($this->tillA, 'ticket', 'T', 'sale');
                $this->registry->revoke($this->tillA);
            }

            return true;
        });

        $body = self::body($this->api(null, $link)->jobs($this->agentRequest('GET', '/j', $key, null, ['wait' => '20'])));

        $this->assertSame([], $body['jobs']);
        $this->assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM print_job WHERE terminal_id = ? AND status = 'pending'", [$this->tillA]));
        $this->assertSame(401, $this->api()->jobs($this->agentRequest('GET', '/j', $key))->getStatusCode());
    }

    public function testRevokingCutsTheAgentAtOnceAndPairingAgainReplacesTheKey(): void
    {
        $first = $this->pairAgent($this->tillA);
        $second = $this->pairAgent($this->tillA, '10.0.0.6');
        $this->assertSame(401, $this->api()->jobs($this->agentRequest('GET', '/j', $first))->getStatusCode(), 'pairing again revokes the previous key (E5)');
        $this->assertSame(200, $this->api()->jobs($this->agentRequest('GET', '/j', $second, null, [], '10.0.0.6'))->getStatusCode());

        $this->assertSame(403, $this->pos()->revoke($this->posRequest('DELETE', '/x', ['user_id' => 2, 'role' => 'cashier'], false), $this->tillA)->getStatusCode());
        $revoke = $this->pos()->revoke($this->posRequest('DELETE', '/x', self::ADMIN, false), $this->tillA);
        $this->assertSame(204, $revoke->getStatusCode());

        $this->assertSame(401, $this->api()->jobs($this->agentRequest('GET', '/j', $second, null, [], '10.0.0.6'))->getStatusCode(), 'the next request is refused (AC3)');
        $this->assertFalse(self::body($this->pos()->status($this->posRequest('GET', '/s', self::ADMIN, false), $this->tillA))['paired']);
    }

    public function testStatusStoresThePrinters(): void
    {
        $key = $this->pairAgent($this->tillA);
        $response = $this->api()->status($this->agentRequest('PUT', '/api/agent/v1/status', $key, [
            'version' => '1.0.1',
            'hostname' => 'PC-CAJA1',
            'printers' => [['target' => 'ticket', 'name' => 'EPSON TM-T20II', 'state' => 'ready', 'detail' => null], 'junk', ['target' => 'label', 'name' => 'Zebra', 'state' => 'on fire']],
        ]));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $status = self::body($this->pos()->status($this->posRequest('GET', '/s', self::ADMIN, false), $this->tillA));
        $this->assertSame(JsonKeys::sorted([['target' => 'ticket', 'name' => 'EPSON TM-T20II', 'state' => 'ready', 'detail' => null], ['target' => 'label', 'name' => 'Zebra', 'state' => 'unknown', 'detail' => null]]), JsonKeys::sorted($status['printers']));
        foreach (AgentApiController::PRINTER_STATES as $state) {
            $this->api()->status($this->agentRequest('PUT', '/s', $key, ['printers' => [['target' => 'ticket', 'name' => 'P', 'state' => $state]]]));
            $this->assertSame($state, self::body($this->pos()->status($this->posRequest('GET', '/s', self::ADMIN, false), $this->tillA))['printers'][0]['state']);
        }
        $this->assertSame('1.0.1', $status['version']);
        $this->assertSame(401, $this->api()->status($this->agentRequest('PUT', '/s', null, []))->getStatusCode());
    }

    public function testVersionsMinimumAndUpdateNotice(): void
    {
        $key = $this->pairAgent($this->tillA);
        $releases = new AgentReleasePolicy('1.1.0', '1.2.0', 'https://tpv.example/agent/{os}/{version}');

        $old = $this->api($releases)->jobs($this->agentRequest('GET', '/j', $key));
        $this->assertSame([426, 'agent_update_required'], [$old->getStatusCode(), self::body($old)['code']]);

        $update = $this->api($releases)->update($this->agentRequest('GET', '/u', $key, null, ['os' => 'windows', 'arch' => 'x86_64', 'version' => '1.0.0']));
        $this->assertSame(200, $update->getStatusCode());
        $this->assertSame(['manifest' => ['os' => 'windows', 'arch' => 'x86_64', 'version' => '1.2.0', 'url' => 'https://tpv.example/agent/windows/1.2.0'], 'signature' => null], self::body($update));
        $this->assertSame(204, $this->api($releases)->update($this->agentRequest('GET', '/u', $key, null, ['os' => 'windows', 'arch' => 'x86_64', 'version' => '1.2.0']))->getStatusCode());
        $this->assertSame(204, $this->api()->update($this->agentRequest('GET', '/u', $key, null, ['version' => '1.0.0']))->getStatusCode(), 'nothing configured');

        $this->assertTrue(self::body($this->pos($releases)->status($this->posRequest('GET', '/s', self::ADMIN, false), $this->tillA))['update_available'], 'U1');
        $this->api($releases)->status($this->agentRequest('PUT', '/s', $key, ['version' => '1.2.0', 'printers' => []]));
        $this->assertSame(200, $this->api($releases)->jobs($this->agentRequest('GET', '/j', $key))->getStatusCode());
        $this->assertFalse(self::body($this->pos($releases)->status($this->posRequest('GET', '/s', self::ADMIN, false), $this->tillA))['update_available']);
    }

    public function testTestPrintQueuesATicketForAnAgentTillOnly(): void
    {
        $response = $this->pos()->testPrint($this->posRequest('POST', '/t', self::ADMIN), $this->tillA);
        $this->assertSame(201, $response->getStatusCode());
        $jobId = self::body($response)['print_job_id'];
        $this->assertSame(['print_status' => 'queued', 'print_job_id' => $jobId], self::body($response));
        $this->assertSame(['ticket', 'test'], array_values($this->connection->fetchAssociative('SELECT type, source FROM print_job WHERE id = ?', [$jobId])));
        $this->assertSame(415, $this->pos()->testPrint($this->posRequest('POST', '/t', self::ADMIN, false), $this->tillA)->getStatusCode());

        $system = $this->till($this->connection, 'Caja CUPS', 'pdf');
        $this->assertSame(409, $this->pos()->testPrint($this->posRequest('POST', '/t', self::ADMIN), $system)->getStatusCode());
    }

    public function testTheDrawerButtonOnAnAgentTillIsAuditedAndFollowsItsJob(): void
    {
        $key = $this->pairAgent($this->tillA);
        $stack = new RequestStack();
        $stack->push($this->posRequest('POST', '/api/drawer/open', ['user_id' => 4, 'terminal_id' => $this->tillA], false));
        $printer = new PrinterService($this->connection, $this->createMock(TicketDataResolver::class), new EscPosTicketBuilder(), $this->createMock(TicketPdfGenerator::class), printJobs: $this->queue, drawerAudit: new DrawerAudit($this->connection, $stack, $this->clock));

        $printer->openDrawer($this->tillA);

        $audit = $this->connection->fetchAssociative('SELECT user_id, reason, job_id, printer_type, result FROM drawer_audit WHERE terminal_id = ?', [$this->tillA]);
        $this->assertSame(['4', 'button', (string) $printer->queuedJobId('drawer'), 'agent', 'queued'], array_map('strval', array_values($audit)));
        $job = self::body($this->api()->jobs($this->agentRequest('GET', '/j', $key)))['jobs'][0];
        $this->assertSame(['drawer', ''], [$job['type'], $job['payload_b64']]);
        $this->api()->result($this->agentRequest('POST', '/r', $key, ['claim_token' => $job['claim_token'], 'status' => 'done']), $job['id']);
        $this->assertSame('done', $this->connection->fetchOne('SELECT result FROM drawer_audit WHERE terminal_id = ?', [$this->tillA]), 'T11: the result of the opening');
    }
}
