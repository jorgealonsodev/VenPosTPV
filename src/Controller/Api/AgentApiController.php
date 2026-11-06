<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\AgentReleasePolicy;
use VenPosTPV\Service\PrintAgent\LongPollLink;
use VenPosTPV\Service\PrintAgent\PairingCodeInvalidException;
use VenPosTPV\Service\PrintAgent\PrintJobException;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Service\PrintAgent\RateLimiter;

/**
 * API of the print agent, v1 (docs/prd/agente-impresion.md §6 "API del agente").
 *
 * Bearer key only: no session, no cookies (E9); DeviceListener skips these paths
 * and nothing here touches the session. Every answer carries
 * `Cache-Control: no-store`. The key, the pairing code and the Authorization
 * header are never logged (E8). Every authenticated call records the agent's last
 * use and IP (S1).
 *
 * A long poll that finds nothing at once answers 200 straight away and streams:
 * one space per second while it waits (so a client that has gone is noticed and
 * no job is claimed into a dead connection), then the JSON body. Its claims get
 * the 30 s lease of PrintJobQueue::LONG_POLL_LEASE_SECONDS.
 */
#[AsController]
final class AgentApiController
{
    public const MAX_WAIT_SECONDS = 20;
    public const PAIR_ATTEMPTS_PER_MINUTE = 5;
    public const INVALID_KEYS_PER_MINUTE = 10;
    private const MAX_PRINTERS = 10;
    /** Printer states the agent reports (PUT status); anything else is stored as unknown. */
    public const PRINTER_STATES = ['ready', 'paused', 'offline', 'error', 'not_found', 'not_configured', 'unknown'];
    private const RECONNECT_MESSAGE = 'Vuelve a conectar el agente desde Ajustes → Cajas';

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly PrintJobQueue $jobs,
        private readonly RateLimiter $limiter,
        private readonly AgentReleasePolicy $releases,
        private readonly ClockInterface $clock,
        private readonly LongPollLink $link = new LongPollLink(),
    ) {
    }

    /** E3: {code, hostname, os, version} → 201 {key, terminal_id, terminal_name}. */
    #[Route('/agent/v1/pair', methods: ['POST'])]
    public function pair(Request $request): Response
    {
        return $this->guarded(function () use ($request): Response {
            $ip = $request->getClientIp() ?? '';
            if ($this->limiter->hit('agent_pair', $ip) > self::PAIR_ATTEMPTS_PER_MINUTE) {
                return $this->tooManyRequests();
            }

            $body = self::body($request);
            try {
                $paired = $this->agents->pair(
                    self::text($body['code'] ?? null),
                    self::text($body['hostname'] ?? null),
                    self::text($body['os'] ?? null),
                    self::text($body['version'] ?? null),
                    $ip,
                );
            } catch (PairingCodeInvalidException $e) {
                return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, PairingCodeInvalidException::CODE, $e->getMessage());
            }

            return $this->json($paired, Response::HTTP_CREATED);
        });
    }

    /** Long poll: waits up to `wait` seconds (0..20), checking every second, for this till's jobs. */
    #[Route('/agent/v1/jobs', methods: ['GET'])]
    public function jobs(Request $request): Response
    {
        return $this->guarded(function () use ($request): Response {
            $agent = $this->authenticate($request);
            if ($agent instanceof Response) {
                return $agent;
            }
            if ($this->releases->isBelowMinimum($agent['agent_version'])) {
                return $this->error(Response::HTTP_UPGRADE_REQUIRED, 'agent_update_required', 'Esta versión del agente ya no es válida: instala la nueva desde Ajustes → Cajas');
            }

            $wait = filter_var($request->query->get('wait', '0'), \FILTER_VALIDATE_INT);
            $wait = $wait === false ? 0 : max(0, min(self::MAX_WAIT_SECONDS, $wait));
            $terminalId = $agent['terminal_id'];
            $lease = $wait > 0 ? PrintJobQueue::LONG_POLL_LEASE_SECONDS : PrintJobQueue::LEASE_SECONDS;

            $jobs = $this->jobs->claim($terminalId, PrintJobQueue::CLAIM_LIMIT, $lease);
            if ($jobs !== [] || $wait === 0) {
                return $this->json($this->jobsBody($jobs, $wait));
            }

            $deadline = $this->clock->now()->getTimestamp() + $wait;
            $key = $agent['key'];
            $response = new StreamedResponse(function () use ($terminalId, $key, $lease, $wait, $deadline): void {
                $this->link->hold();
                $jobs = [];
                while ($this->clock->now()->getTimestamp() < $deadline) {
                    $this->clock->sleep(1);
                    // The agent has gone: nothing is claimed into a dead connection.
                    if (!$this->link->alive()) {
                        return;
                    }
                    // A key revoked while waiting gets nothing; its next request gets 401 (E5).
                    if (!$this->agents->isActive($terminalId, $key)) {
                        break;
                    }
                    $jobs = $this->jobs->claim($terminalId, PrintJobQueue::CLAIM_LIMIT, $lease);
                    if ($jobs !== []) {
                        break;
                    }
                }
                echo json_encode($this->jobsBody($jobs, $wait), \JSON_THROW_ON_ERROR);
            }, Response::HTTP_OK, [
                'Content-Type' => 'application/json',
                // A proxy such as nginx passes each space on at once, so it notices a client that has gone.
                'X-Accel-Buffering' => 'no',
            ]);

            return $this->stamped($response);
        });
    }

    /**
     * @param list<array<string, mixed>> $jobs
     *
     * @return array{jobs: list<array<string, mixed>>, poll_after_ms: int, server_time_ms: int}
     */
    private function jobsBody(array $jobs, int $wait): array
    {
        return [
            'jobs' => $jobs,
            // Ask again at once after jobs or a long poll; a short poll waits 2 s.
            'poll_after_ms' => $jobs !== [] || $wait > 0 ? 0 : 2000,
            // The server clock when the body is written: the Date header of a streamed
            // answer is up to 20 s older. The agent corrects its clock skew with it.
            'server_time_ms' => (int) $this->clock->now()->format('Uv'),
        ];
    }

    /** {claim_token, status: done|failed, error_code?, error?} → {status}. */
    #[Route('/agent/v1/jobs/{id}/result', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function result(Request $request, int $id): Response
    {
        return $this->guarded(function () use ($request, $id): Response {
            $agent = $this->authenticate($request);
            if ($agent instanceof Response) {
                return $agent;
            }

            $body = self::body($request);
            $token = $body['claim_token'] ?? null;
            $status = $body['status'] ?? null;
            if (!\is_string($token) || $token === '' || !\in_array($status, ['done', 'failed'], true)) {
                return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_request', 'claim_token y status (done o failed) son obligatorios');
            }

            try {
                $current = $this->jobs->complete(
                    $agent['terminal_id'],
                    $id,
                    $token,
                    $status,
                    isset($body['error_code']) && \is_string($body['error_code']) ? $body['error_code'] : null,
                    isset($body['error']) && \is_string($body['error']) ? $body['error'] : null,
                );
            } catch (PrintJobException $e) {
                return $this->error($e->httpStatus, $e->errorCode, $e->getMessage());
            }

            return $this->json(['status' => $current]);
        });
    }

    /** {claim_token} → {lease_until}: more time for a long batch (T4). */
    #[Route('/agent/v1/jobs/{id}/extend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function extend(Request $request, int $id): Response
    {
        return $this->guarded(function () use ($request, $id): Response {
            $agent = $this->authenticate($request);
            if ($agent instanceof Response) {
                return $agent;
            }

            $token = self::body($request)['claim_token'] ?? null;
            if (!\is_string($token) || $token === '') {
                return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_request', 'claim_token es obligatorio');
            }

            try {
                $lease = $this->jobs->extend($agent['terminal_id'], $id, $token);
            } catch (PrintJobException $e) {
                return $this->error($e->httpStatus, $e->errorCode, $e->getMessage());
            }

            return $this->json(['lease_until' => $lease->format(\DATE_ATOM)]);
        });
    }

    /** {version, hostname, printers: [{target, name, state, detail}]} → 204 (S1, S5). */
    #[Route('/agent/v1/status', methods: ['PUT'])]
    public function status(Request $request): Response
    {
        return $this->guarded(function () use ($request): Response {
            $agent = $this->authenticate($request);
            if ($agent instanceof Response) {
                return $agent;
            }

            $body = self::body($request);
            $printers = [];
            foreach (\array_slice(\is_array($body['printers'] ?? null) ? array_values($body['printers']) : [], 0, self::MAX_PRINTERS) as $printer) {
                if (!\is_array($printer)) {
                    continue;
                }
                $printers[] = [
                    'target' => mb_substr(self::text($printer['target'] ?? null), 0, 16),
                    'name' => mb_substr(self::text($printer['name'] ?? null), 0, 255),
                    'state' => \in_array($printer['state'] ?? null, self::PRINTER_STATES, true) ? $printer['state'] : 'unknown',
                    'detail' => isset($printer['detail']) && \is_string($printer['detail']) ? mb_substr(self::text($printer['detail']), 0, 255) : null,
                ];
            }
            $version = self::text($body['version'] ?? null);
            $hostname = self::text($body['hostname'] ?? null);
            $this->agents->updateStatus($agent['terminal_id'], $version !== '' ? $version : null, $hostname !== '' ? $hostname : null, $printers);

            return $this->noContent();
        });
    }

    /**
     * U1 phase 1: whether a newer agent exists, with its download link. 204 when
     * there is nothing new. No signed manifest yet (phase 2, U2): signature is null.
     */
    #[Route('/agent/v1/update', methods: ['GET'])]
    public function update(Request $request): Response
    {
        return $this->guarded(function () use ($request): Response {
            $agent = $this->authenticate($request);
            if ($agent instanceof Response) {
                return $agent;
            }

            $os = mb_substr(self::text($request->query->get('os')), 0, 16);
            $arch = mb_substr(self::text($request->query->get('arch')), 0, 16);
            $version = self::text($request->query->get('version'));
            $version = $version !== '' ? $version : $agent['agent_version'];
            $url = $this->releases->downloadUrl($os, $arch);
            if (!$this->releases->isUpdateAvailable($version) || $url === null) {
                return $this->noContent();
            }

            return $this->json([
                'manifest' => ['os' => $os, 'arch' => $arch, 'version' => $this->releases->latestVersion(), 'url' => $url],
                'signature' => null,
            ]);
        });
    }

    /**
     * @return array{terminal_id: int, agent_version: string|null, hostname: string|null, key: string}|Response
     */
    private function authenticate(Request $request): array|Response
    {
        $key = self::bearer($request);
        $agent = $this->agents->authenticate($key);
        $ip = $request->getClientIp() ?? '';
        if ($agent === null || $key === null) {
            // E7: an IP that keeps sending invalid keys gets 429.
            if ($this->limiter->hit('agent_key', $ip) > self::INVALID_KEYS_PER_MINUTE) {
                return $this->tooManyRequests();
            }

            return $this->keyInvalid();
        }
        $this->agents->touch($agent['terminal_id'], $ip);

        return $agent + ['key' => $key];
    }

    /** E9: only the Authorization header carries the key, never the URL or a cookie. */
    private static function bearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (!\is_string($header) || preg_match('/\ABearer +(\S+)\z/', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }

    /** @param callable(): Response $action */
    private function guarded(callable $action): Response
    {
        try {
            return $action();
        } catch (TableNotFoundException) {
            // Version00000037 not applied yet.
            return $this->error(Response::HTTP_SERVICE_UNAVAILABLE, 'print_agent_unavailable', 'El TPV aún no admite el agente de impresión');
        }
    }

    private function keyInvalid(): Response
    {
        $response = $this->error(Response::HTTP_UNAUTHORIZED, 'agent_key_invalid', self::RECONNECT_MESSAGE);
        $response->headers->set('WWW-Authenticate', 'Bearer');

        return $response;
    }

    private function tooManyRequests(): Response
    {
        $response = $this->error(Response::HTTP_TOO_MANY_REQUESTS, 'rate_limited', 'Demasiados intentos: espera un minuto');
        $response->headers->set('Retry-After', (string) RateLimiter::WINDOW_SECONDS);

        return $response;
    }

    /** The API's usual error shape {error, code, retry}; the agent reads `code`. */
    private function error(int $status, string $code, string $message): JsonResponse
    {
        $retry = \in_array($status, [Response::HTTP_TOO_MANY_REQUESTS, Response::HTTP_SERVICE_UNAVAILABLE], true);

        return $this->json(['error' => $message, 'code' => $code, 'retry' => $retry], $status);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->stamped(new JsonResponse($data, $status));
    }

    private function noContent(): Response
    {
        return $this->stamped(new Response('', Response::HTTP_NO_CONTENT));
    }

    /**
     * no-store, and a Date header from the server clock when the answer leaves (the
     * agent uses it as the server clock).
     *
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    private function stamped(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->setDate($this->clock->now());

        return $response;
    }
}
