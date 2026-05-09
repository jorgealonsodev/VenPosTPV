<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Print\PrinterService;

#[AsController]
final class DrawerOpenController
{
    public function __construct(private readonly PrinterService $printerService)
    {
    }

    #[Route('/drawer/open', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $terminalId = (int) ($session->get('terminal_id') ?? 0);
        if ($terminalId <= 0) {
            return new JsonResponse(['error' => 'Terminal not resolved'], Response::HTTP_BAD_REQUEST);
        }

        $this->printerService->openDrawer($terminalId);

        return new JsonResponse(['success' => true], Response::HTTP_OK);
    }
}
