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
final class CashCloseController
{
    public function __construct(private readonly CashSessionService $cashSessionService)
    {
    }

    #[Route('/cash/close', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $countedCash = (float) ($body['counted_cash'] ?? -1);
        if ($countedCash < 0) {
            return new JsonResponse(['error' => 'counted_cash must be >= 0'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $data = $this->cashSessionService->closeSession((int) $session->get('user_id'), $countedCash);

            return new JsonResponse(['data' => $data], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }
}
