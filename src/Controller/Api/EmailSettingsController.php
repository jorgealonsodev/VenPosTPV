<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Mail\MailSettings;
use VenPosTPV\Service\Mail\MailSettingsStore;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;

/** Ajustes → Email: the shop's outgoing mail account (admins only). */
#[AsController]
final class EmailSettingsController
{
    public function __construct(
        private readonly MailSettingsStore $store,
        private readonly TicketMailer $mailer,
    ) {
    }

    #[Route('/settings/email', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        return new JsonResponse(['data' => $this->store->load()->toPublicArray()]);
    }

    #[Route('/settings/email', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // A typed password replaces the stored one; empty or absent keeps it.
        $password = \is_string($body['password'] ?? null) && $body['password'] !== '' ? $body['password'] : null;
        if (($body['clear_password'] ?? false) === true) {
            $password = '';
        }

        try {
            $settings = MailSettings::validated($body);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->store->save($settings, $password);

        return new JsonResponse(['data' => $this->store->load()->toPublicArray()]);
    }

    #[Route('/settings/email/test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = json_decode((string) $request->getContent(), true);

        try {
            $this->mailer->sendTest(\is_array($body) ? (string) ($body['to'] ?? '') : '');
        } catch (TicketMailException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        }

        return new JsonResponse(['data' => ['sent' => true]]);
    }

    private function isAdmin(Request $request): bool
    {
        return $request->getSession()->get('role') === 'admin';
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
    }
}
