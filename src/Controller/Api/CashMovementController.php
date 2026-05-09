<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cash\CashSessionService;

#[AsController]
final class CashMovementController
{
    public function __construct(private readonly CashSessionService $cashSessionService)
    {
    }

    #[Route('/cash/movement', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $type = (string) ($body['type'] ?? '');
        $amount = (float) ($body['amount'] ?? 0);
        $concept = trim((string) ($body['concept'] ?? ''));

        if (!in_array($type, ['cash_in', 'cash_out'], true) || $amount <= 0 || $concept === '') {
            return new JsonResponse(['error' => 'Invalid movement payload'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $data = $this->cashSessionService->addMovement((int) $session->get('user_id'), $type, $amount, $concept);

            return new JsonResponse(['data' => $data], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }
}
