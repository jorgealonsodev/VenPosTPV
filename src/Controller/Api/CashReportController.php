<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Print\CashReport;
use VenPosTPV\Service\Print\CashReportService;

/**
 * Informe X: the open till, printed or as PDF, without closing it. Informe Z: the
 * report of a close, printed right after closing and consultable later. Any user of
 * the shop gets a Z by its session (the cashier who just closed has no other way
 * to get theirs); only administrators list the closes (Ajustes → Cierres de caja).
 */
#[AsController]
final class CashReportController
{
    public function __construct(
        private readonly CashReportService $reports,
        private readonly CashSessionService $cashSessionService,
    ) {
    }

    /** {data: {print_status: 'queued'|'printed', print_job_id?}} or {data: {fallback: 'pdf'}} */
    #[Route('/cash/report-x/print', methods: ['POST'])]
    public function printX(Request $request): JsonResponse
    {
        if (($userId = $this->userId($request)) === null) {
            return $this->unauthorized();
        }
        $report = $this->currentX($userId);
        if ($report === null) {
            return $this->noOpenSession();
        }

        return $this->print($report, $report->terminalId, $userId);
    }

    #[Route('/cash/report-x/pdf', methods: ['GET'])]
    public function pdfX(Request $request): Response
    {
        if (($userId = $this->userId($request)) === null) {
            return $this->unauthorized();
        }
        $report = $this->currentX($userId);

        return $report === null ? $this->noOpenSession() : $this->pdf($report);
    }

    /** Admin only: the last 50 closes of this shop, newest first. */
    #[Route('/cash/z', methods: ['GET'])]
    public function listZ(Request $request): JsonResponse
    {
        if ($this->userId($request) === null) {
            return $this->unauthorized();
        }
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $this->cashSessionService->listClosedSessions(50)]);
    }

    #[Route('/cash/z/{sessionId}/pdf', methods: ['GET'], requirements: ['sessionId' => '\d+'])]
    public function pdfZ(Request $request, int $sessionId): Response
    {
        if ($this->userId($request) === null) {
            return $this->unauthorized();
        }
        $report = $this->reports->closedZ($sessionId);

        return $report === null ? $this->zNotFound() : $this->pdf($report);
    }

    /** Printed on the till that asks for it (like a reprint), else on the closed till. */
    #[Route('/cash/z/{sessionId}/print', methods: ['POST'], requirements: ['sessionId' => '\d+'])]
    public function printZ(Request $request, int $sessionId): JsonResponse
    {
        if (($userId = $this->userId($request)) === null) {
            return $this->unauthorized();
        }
        $report = $this->reports->closedZ($sessionId);
        if ($report === null) {
            return $this->zNotFound();
        }
        $device = $request->attributes->get(DeviceContext::ATTRIBUTE);

        return $this->print($report, \is_int($device) && $device > 0 ? $device : $report->terminalId, $userId);
    }

    private function currentX(int $userId): ?CashReport
    {
        try {
            return $this->reports->currentX($userId);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function print(CashReport $report, int $terminalId, int $userId): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $this->reports->print($report, $terminalId, $userId)]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'No se ha podido imprimir el informe'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function pdf(CashReport $report): Response
    {
        return new Response($this->reports->pdf($report), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $report->fileName()),
        ]);
    }

    private function userId(Request $request): ?int
    {
        $userId = $request->getSession()->get('user_id');

        return $userId === null ? null : (int) $userId;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function noOpenSession(): JsonResponse
    {
        return new JsonResponse(['error' => 'No hay ninguna caja abierta en este equipo'], Response::HTTP_CONFLICT);
    }

    private function zNotFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'Cierre de caja no encontrado'], Response::HTTP_NOT_FOUND);
    }
}
