<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\Print\TicketVerificationService;

#[AsController]
final class TicketController
{
    public function __construct(
        private readonly PrinterService $printerService,
        private readonly TicketDataResolver $ticketDataResolver,
        private readonly TicketPdfGenerator $pdfGenerator,
        private readonly TicketVerificationService $verificationService,
    ) {
    }

    #[Route('/ticket/{id}/pdf', methods: ['GET'])]
    public function pdf(Request $request, int $id): Response
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $ticketData = $this->ticketDataResolver->resolve($id);
        if ($ticketData === null) {
            return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        $giftMode = $request->query->getBoolean('gift_mode');
        $pdf = $this->pdfGenerator->generate($ticketData, $giftMode);

        return new StreamedResponse(
            function () use ($pdf): void {
                echo $pdf;
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="ticket-%s.pdf"', $ticketData->reference),
            ]
        );
    }

    #[Route('/ticket/reprint', methods: ['POST'])]
    public function reprint(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $orderId = isset($body['ps_order_id']) ? (int) $body['ps_order_id'] : 0;
        $giftMode = isset($body['gift_mode']) && $body['gift_mode'] === true;

        if ($orderId <= 0) {
            return new JsonResponse(['error' => 'Missing ps_order_id'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $terminalId = (int) ($session->get('terminal_id') ?? 1);
            $result = $this->printerService->reprintTicket($orderId, $terminalId, $giftMode);

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/ticket/verify', methods: ['GET'])]
    public function verify(Request $request): JsonResponse
    {
        $token = $request->query->get('token', '');
        if ($token === '') {
            return new JsonResponse(['error' => 'Missing token'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $payload = $this->verificationService->verifyToken($token);

            return new JsonResponse([
                'data' => [
                    'order_id' => $payload['order_id'] ?? null,
                    'reference' => $payload['reference'] ?? null,
                    'total' => $payload['total'] ?? null,
                    'date' => isset($payload['iat']) ? date('Y-m-d H:i:s', (int) $payload['iat']) : null,
                ],
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Invalid or expired token'], Response::HTTP_UNAUTHORIZED);
        }
    }
}
