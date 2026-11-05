<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;

/** The sale ticket as PDF by email, for tills without a printer. */
#[AsController]
final class TicketEmailController
{
    public function __construct(private readonly TicketMailer $mailer)
    {
    }

    #[Route('/ticket/email-enabled', methods: ['GET'])]
    public function enabled(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['data' => ['enabled' => $this->mailer->isEnabled()]]);
    }

    #[Route('/ticket/sale/{id}/email', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(Request $request, int $id): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $body = json_decode((string) $request->getContent(), true);

        try {
            $this->mailer->sendSale($id, \is_array($body) ? (string) ($body['email'] ?? '') : '');
        } catch (TicketMailException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        }

        return new JsonResponse(['data' => ['sent' => true]]);
    }
}
