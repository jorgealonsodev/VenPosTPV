<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;

/**
 * Print jobs seen from the POS (docs/prd/agente-impresion.md C1, S4): the status of
 * one job, which the payment screen polls, and the 30-day history. Never the data
 * of a job. An administrator sees every till; anyone else only the till of their
 * session (another till's job is "not found").
 */
#[AsController]
final class PrintJobController
{
    public function __construct(private readonly PrintJobQueue $jobs)
    {
    }

    /** {status, error, ...} */
    #[Route('/print-jobs/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $job = $this->jobs->find($id);
        } catch (TableNotFoundException) {
            return self::unavailable();
        }
        if ($job === null || !$this->maySee($request, (int) $job['terminal_id'])) {
            return new JsonResponse(['error' => 'Trabajo no encontrado'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($job);
    }

    /** ?terminal_id&status&cursor&limit≤50 → {items, next_cursor} */
    #[Route('/print-jobs', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $query = $request->query;
        $terminalId = self::positiveInt($query->get('terminal_id'));
        if ($session->get('role') !== 'admin') {
            $own = self::positiveInt($session->get('terminal_id'));
            if ($own === null || ($terminalId !== null && $terminalId !== $own)) {
                return new JsonResponse(['items' => [], 'next_cursor' => null]);
            }
            $terminalId = $own;
        }

        $status = $query->get('status');
        $status = \is_string($status) && $status !== '' ? $status : null;
        if ($status !== null && !\in_array($status, PrintJobQueue::STATUSES, true)) {
            return new JsonResponse(['error' => 'Estado no válido'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $limit = self::positiveInt($query->get('limit')) ?? 50;

        try {
            return new JsonResponse($this->jobs->history($terminalId, $status, self::positiveInt($query->get('cursor')), min(50, $limit)));
        } catch (TableNotFoundException) {
            return self::unavailable();
        }
    }

    private function maySee(Request $request, int $terminalId): bool
    {
        $session = $request->getSession();

        return $session->get('role') === 'admin' || self::positiveInt($session->get('terminal_id')) === $terminalId;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, \FILTER_VALIDATE_INT);

        return \is_int($int) && $int > 0 ? $int : null;
    }

    private static function unavailable(): JsonResponse
    {
        return new JsonResponse(['error' => 'El TPV aún no admite el agente de impresión', 'code' => 'print_agent_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
