<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cart\ParkedTicketService;

#[AsController]
class ParkedTicketController
{
    public function __construct(private readonly ParkedTicketService $parkedTicketService)
    {
    }

    #[Route('/parked-tickets', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['data' => $this->parkedTicketService->list()], Response::HTTP_OK);
    }

    #[Route('/parked-tickets', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');
        if ($userId === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $input = [
            'client_ref' => $body['client_ref'] ?? null,
            'terminal_id' => $session->get('terminal_id') ?? ($body['terminal_id'] ?? 1),
            'user_id' => (int) $userId,
            'cashier_name' => $body['cashier_name'] ?? $session->get('cashier_name', 'Cajero'),
            'note' => $body['note'] ?? null,
            'customer' => $body['customer'] ?? null,
            'lines' => $body['lines'] ?? [],
            'discount' => $body['discount'] ?? null,
            'created_at' => $body['created_at'] ?? null,
        ];

        try {
            $ticket = $this->parkedTicketService->park($input);

            return new JsonResponse(['data' => $ticket], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/parked-tickets/clear', methods: ['POST'])]
    public function clear(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $deletedCount = $this->parkedTicketService->clearAll();

        return new JsonResponse(['data' => ['deleted_count' => $deletedCount]], Response::HTTP_OK);
    }

    #[Route('/parked-tickets/{identifier}', methods: ['DELETE'])]
    public function delete(Request $request, string $identifier): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $deleted = $this->parkedTicketService->delete($identifier);

        return new JsonResponse(['data' => ['deleted' => $deleted]], Response::HTTP_OK);
    }
}
