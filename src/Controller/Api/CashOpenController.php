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
final class CashOpenController
{
    public function __construct(private readonly CashSessionService $cashSessionService)
    {
    }

    #[Route('/cash/open', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        $openingCash = (float) ($body['opening_cash'] ?? 0);
        if ($openingCash < 0) {
            return new JsonResponse(['error' => 'opening_cash must be >= 0'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $data = $this->cashSessionService->openSession((int) $session->get('user_id'), $openingCash);

            return new JsonResponse(['data' => $data], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }
}
