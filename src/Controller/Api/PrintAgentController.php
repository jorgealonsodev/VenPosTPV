<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\AgentReleasePolicy;

/**
 * The print agent of a till, from Ajustes → Cajas (docs/prd/agente-impresion.md
 * E1-E6, S3, U1): its status, a pairing code (administrators), disconnecting it
 * (administrators) and a test print. Administrator writes accept JSON only (415),
 * so a cross-site form can never trigger them. The code is shown once and never
 * logged (E8).
 */
#[AsController]
final class PrintAgentController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AgentRegistry $agents,
        private readonly AgentReleasePolicy $releases,
        private readonly PrinterService $printerService,
    ) {
    }

    /** {connected, last_seen, last_ip, version, printers, update_available, ...} */
    #[Route('/terminals/{id}/print-agent', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function status(Request $request, int $id): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return self::noStore(new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED));
        }
        if (!$this->terminalExists($id)) {
            return self::noStore(new JsonResponse(['error' => 'Caja no encontrada'], Response::HTTP_NOT_FOUND));
        }

        try {
            $status = $this->agents->statusOf($id);
        } catch (TableNotFoundException) {
            return self::unavailable();
        }

        return self::noStore(new JsonResponse($status + [
            'update_available' => $this->releases->isUpdateAvailable($status['version']),
            'latest_version' => $this->releases->latestVersion(),
            'download_url' => $this->releases->downloadUrl(self::osFamily($status['os'])),
        ]));
    }

    /** E2: a 6-digit code for 10 minutes; generating another one voids the previous. */
    #[Route('/terminals/{id}/print-agent/pairing-code', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pairingCode(Request $request, int $id): JsonResponse
    {
        $denied = $this->adminJsonOnly($request);
        if ($denied !== null) {
            return $denied;
        }
        if (!$this->terminalExists($id)) {
            return self::noStore(new JsonResponse(['error' => 'Caja no encontrada'], Response::HTTP_NOT_FOUND));
        }

        $userId = (int) $request->getSession()->get('user_id');
        try {
            $created = $this->agents->createPairingCode($id, $userId > 0 ? $userId : null);
        } catch (TableNotFoundException) {
            return self::unavailable();
        }
        $this->audit($userId, $id, 'print_agent_pairing_code', $request);

        return self::noStore(new JsonResponse(
            ['code' => $created['code'], 'expires_at' => $created['expires_at']->format(\DATE_ATOM)],
            Response::HTTP_CREATED
        ));
    }

    /** E5: disconnects the till's agent; its key stops working at once. */
    #[Route('/terminals/{id}/print-agent', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revoke(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return self::forbidden();
        }
        if (!$this->terminalExists($id)) {
            return self::noStore(new JsonResponse(['error' => 'Caja no encontrada'], Response::HTTP_NOT_FOUND));
        }

        try {
            $this->agents->revoke($id);
        } catch (TableNotFoundException) {
            return self::unavailable();
        }
        $this->audit((int) $request->getSession()->get('user_id'), $id, 'print_agent_revoked', $request);

        return self::noStore(new Response('', Response::HTTP_NO_CONTENT));
    }

    /** A test ticket on the till's print agent (Ajustes → Cajas). */
    #[Route('/terminals/{id}/print-agent/test-print', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function testPrint(Request $request, int $id): JsonResponse
    {
        $denied = $this->adminJsonOnly($request);
        if ($denied !== null) {
            return $denied;
        }
        if (!$this->terminalExists($id)) {
            return self::noStore(new JsonResponse(['error' => 'Caja no encontrada'], Response::HTTP_NOT_FOUND));
        }
        if (!$this->printerService->usesAgent($id)) {
            return self::noStore(new JsonResponse(['error' => 'La impresora de esta caja no es el agente de impresión', 'code' => 'printer_not_agent'], Response::HTTP_CONFLICT));
        }

        try {
            $jobId = $this->printerService->printTestTicket($id, (int) $request->getSession()->get('user_id'));
        } catch (TableNotFoundException) {
            return self::unavailable();
        }

        return self::noStore(new JsonResponse(['print_status' => 'queued', 'print_job_id' => $jobId], Response::HTTP_CREATED));
    }

    private function adminJsonOnly(Request $request): ?JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return self::forbidden();
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return self::noStore(new JsonResponse(['error' => 'Se espera un cuerpo JSON'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE));
        }

        return null;
    }

    private function isAdmin(Request $request): bool
    {
        return $request->getSession()->get('role') === 'admin';
    }

    private function terminalExists(int $id): bool
    {
        return $this->connection->fetchOne('SELECT id FROM terminal WHERE id = ?', [$id]) !== false;
    }

    /** Who paired or disconnected, without the code (E8). */
    private function audit(int $userId, int $terminalId, string $action, Request $request): void
    {
        try {
            $this->connection->insert('audit_log', [
                'user_id' => $userId > 0 ? $userId : null,
                'terminal_id' => $terminalId,
                'action' => $action,
                'target' => (string) $terminalId,
                'payload' => '{}',
                'ip' => $request->getClientIp(),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best-effort logging
        }
    }

    private static function osFamily(?string $os): string
    {
        return $os !== null && stripos($os, 'win') !== false ? 'windows' : ($os !== null && $os !== '' ? 'linux' : '');
    }

    private static function forbidden(): JsonResponse
    {
        return self::noStore(new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN));
    }

    private static function unavailable(): JsonResponse
    {
        return self::noStore(new JsonResponse(['error' => 'El TPV aún no admite el agente de impresión', 'code' => 'print_agent_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE));
    }

    /**
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    private static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
