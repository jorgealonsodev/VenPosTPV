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
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplayResult;

/**
 * Ajustes → VERI*FACTU → «Diario fiscal» (fiscal-journal D9), admins only and
 * read-only: the state written by the every-minute task (tpv:fiscal:journal), or
 * the lag read now from the database when that state is missing or stale, and
 * `guard`: whether the till is blocked because the database is behind the journal
 * (FiscalJournalGuard::report, read now: a restore shows at once). Any other
 * logged-in user gets only `{"guard": {"state"}}`, which the till asks for when
 * the page loads and after a login, to show «Caja bloqueada» at once.
 *
 * POST /fiscal/journal/replay is the button «Recuperar documentos» (S7, D8.5): the
 * same FiscalReplay as `tpv:fiscal:replay`, a dry run unless the body says
 * `{"apply": true}`. Admins only; being a POST under /api, the cross-site check of
 * OriginCheckListener applies to it. It never runs while the till is not blocked.
 */
#[AsController]
final class FiscalJournalController
{
    public function __construct(
        private readonly FiscalJournalReconciler $reconciler,
        private readonly FiscalJournalGuard $guard,
        private readonly FiscalReplay $replay,
    ) {
    }

    #[Route('/fiscal/journal', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('role') !== 'admin') {
            if ($session->get('user_id') === null) {
                return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
            }
            try {
                return new JsonResponse(['data' => ['guard' => ['state' => $this->guard->check()['state']]]]);
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'No se pudo leer el estado del diario fiscal'], Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        try {
            return new JsonResponse(['data' => $this->reconciler->current()->toArray() + ['guard' => $this->guard->report()]]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'No se pudo leer el estado del diario fiscal'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/fiscal/journal/replay', methods: ['POST'])]
    public function replay(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }

        try {
            $blocked = $this->guard->report()['state'] !== FiscalJournalGuard::OK;
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'No se pudo leer el estado del diario fiscal'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if (!$blocked) {
            return new JsonResponse([
                'error' => 'Nada que recuperar: la base de datos tiene todos los documentos del diario fiscal.',
                'code' => 'nothing_to_replay',
            ], Response::HTTP_CONFLICT);
        }

        $body = json_decode((string) $request->getContent(), true);
        $apply = \is_array($body) && ($body['apply'] ?? null) === true;
        $result = $this->replay->run($apply);
        $data = [
            'status' => $result->status,
            'applied' => $apply && $result->status === FiscalReplayResult::DONE,
            'documents' => $result->documents,
            'message' => $result->message,
        ];

        return match ($result->status) {
            FiscalReplayResult::PENDING, FiscalReplayResult::DONE, FiscalReplayResult::NOTHING => new JsonResponse(['data' => $data]),
            FiscalReplayResult::ERROR => new JsonResponse(['error' => $result->message, 'code' => 'replay_' . $result->status, 'data' => $data], Response::HTTP_INTERNAL_SERVER_ERROR),
            default => new JsonResponse(['error' => $result->message, 'code' => 'replay_' . $result->status, 'data' => $data], Response::HTTP_CONFLICT),
        };
    }
}
