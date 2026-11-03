<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Incident\DiscardNotReady;
use VenPosTPV\Service\Incident\OutboxIncidents;

/**
 * Uploads to PrestaShop still to do and the incidents (PRD I1-I3). The counts are
 * for everyone logged in (sync indicator, cash close); the incident list and its
 * retries and discards are for administrators only. The POSTs only accept a JSON body,
 * which a cross-site form cannot send (same reasoning as DeviceController).
 */
#[AsController]
final class IncidentController
{
    public function __construct(private readonly OutboxIncidents $incidents)
    {
    }

    #[Route('/sync/outbox-status', methods: ['GET'])]
    public function outboxStatus(Request $request): JsonResponse
    {
        if (!$this->isLoggedIn($request)) {
            return $this->unauthorized();
        }

        return new JsonResponse(['data' => $this->incidents->counts()]);
    }

    #[Route('/incidents', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        return new JsonResponse(['data' => $this->incidents->list()]);
    }

    #[Route('/incidents/{id}/retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(int $id, Request $request): JsonResponse
    {
        if (($denied = $this->denied($request) ?? $this->notJson($request)) !== null) {
            return $denied;
        }
        if (!$this->incidents->retry($id)) {
            return $this->notIncident();
        }

        return new JsonResponse(['data' => ['retried' => [$id]]]);
    }

    #[Route('/incidents/order/{orderId}/retry', requirements: ['orderId' => '\d+'], methods: ['POST'])]
    public function retryOrder(int $orderId, Request $request): JsonResponse
    {
        if (($denied = $this->denied($request) ?? $this->notJson($request)) !== null) {
            return $denied;
        }
        $retried = $this->incidents->retryOrder($orderId);
        if ($retried === []) {
            return $this->notIncident();
        }

        return new JsonResponse(['data' => ['retried' => $retried]]);
    }

    /**
     * Gives up uploading an incident PrestaShop will never accept, with what could
     * never upload behind it. Body: {"reason": "..."} (optional, up to 255 characters).
     * The local sale, its ticket and the cash do not change.
     */
    #[Route('/incidents/{id}/discard', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function discard(int $id, Request $request): JsonResponse
    {
        if (($denied = $this->denied($request) ?? $this->notJson($request)) !== null) {
            return $denied;
        }
        try {
            $body = json_decode($request->getContent() ?: '{}', true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        $reason = \is_array($body) ? ($body['reason'] ?? null) : false;
        if ($reason !== null && !\is_string($reason)) {
            return new JsonResponse(['error' => 'El motivo debe ser un texto', 'code' => 'invalid_reason'], Response::HTTP_BAD_REQUEST);
        }
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        try {
            $discarded = $this->incidents->discard($id, (int) $request->getSession()->get('user_id'), $reason);
        } catch (DiscardNotReady) {
            return new JsonResponse(
                ['error' => 'Falta aplicar la migración de la base de datos para descartar incidencias', 'code' => 'discard_not_ready'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }
        if ($discarded === null) {
            return new JsonResponse(
                ['error' => 'Ya no hay ninguna incidencia que descartar: se reintentó, se subió o se descartó mientras tanto', 'code' => 'not_incident'],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse(['data' => ['discarded' => $discarded]]);
    }

    #[Route('/incidents/discarded', methods: ['GET'])]
    public function discardedList(Request $request): JsonResponse
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        return new JsonResponse(['data' => $this->incidents->discarded()]);
    }

    private function isLoggedIn(Request $request): bool
    {
        return $request->getSession()->get('user_id') !== null;
    }

    /** 401 without a session, 403 for anyone but an administrator. */
    private function denied(Request $request): ?JsonResponse
    {
        if (!$this->isLoggedIn($request)) {
            return $this->unauthorized();
        }
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function notJson(Request $request): ?JsonResponse
    {
        if ($request->getContentTypeFormat() === 'json') {
            return null;
        }

        return new JsonResponse(['error' => 'Se espera un cuerpo JSON'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function notIncident(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Ya no hay ninguna incidencia que reintentar: se reintentó o se subió mientras tanto', 'code' => 'not_incident'],
            Response::HTTP_CONFLICT
        );
    }
}
