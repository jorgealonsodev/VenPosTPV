<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalReconciler;

/**
 * Ajustes → VERI*FACTU → «Diario fiscal» (fiscal-journal D9), admins only and
 * read-only: the state written by the every-minute task (tpv:fiscal:journal), or
 * the lag read now from the database when that state is missing or stale, and
 * `guard`: whether the till is blocked because the database is behind the journal
 * (FiscalJournalGuard::report, read now: a restore shows at once).
 */
#[AsController]
final class FiscalJournalController
{
    public function __construct(
        private readonly FiscalJournalReconciler $reconciler,
        private readonly FiscalJournalGuard $guard,
    ) {
    }

    #[Route('/fiscal/journal', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }

        try {
            return new JsonResponse(['data' => $this->reconciler->current()->toArray() + ['guard' => $this->guard->report()]]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'No se pudo leer el estado del diario fiscal'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
