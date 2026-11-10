<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Transfer\TransferPayments;

/**
 * «Tickets → Pendientes de pago»: GET /api/transfers/pending lists the sales paid by
 * transfer whose money has not been seen in the bank yet ({data: [...]}); POST
 * /api/transfers/{id}/received marks one received (admins only).
 */
#[AsController]
final class TransferController
{
    public const ADMIN_ONLY_MESSAGE = 'Solo un administrador puede confirmar que ha llegado una transferencia';

    public function __construct(private readonly TransferPayments $transfers)
    {
    }

    #[Route('/transfers/pending', methods: ['GET'])]
    public function pending(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['data' => $this->transfers->pending()]);
    }

    #[Route('/transfers/{id}/received', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function received(Request $request, int $id): JsonResponse
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');
        if (!\is_int($userId) || $userId <= 0) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if ($session->get('role') !== 'admin') {
            return new JsonResponse(['error' => self::ADMIN_ONLY_MESSAGE], Response::HTTP_FORBIDDEN);
        }

        return match ($this->transfers->markReceived($id, $userId)) {
            'received' => new JsonResponse(['data' => ['id' => $id, 'status' => TransferPayments::RECEIVED]]),
            'already_received' => new JsonResponse(['error' => 'Esta transferencia ya estaba marcada como recibida'], Response::HTTP_CONFLICT),
            default => new JsonResponse(['error' => 'No se encuentra la transferencia'], Response::HTTP_NOT_FOUND),
        };
    }
}
