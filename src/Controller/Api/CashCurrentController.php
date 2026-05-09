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
final class CashCurrentController
{
    public function __construct(private readonly CashSessionService $cashSessionService)
    {
    }

    #[Route('/cash/current', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $this->cashSessionService->getCurrentSession((int) $session->get('user_id'));
        if ($data === null) {
            return new JsonResponse(['data' => ['open' => false]], Response::HTTP_OK);
        }

        return new JsonResponse(['data' => ['open' => true] + $data], Response::HTTP_OK);
    }
}
