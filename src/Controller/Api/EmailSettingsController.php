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

/** Ajustes → Email: the shop's outgoing mail account and the backup alert address (admins only). */
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

        return new JsonResponse(['data' => $this->publicData()]);
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

        // The backup alert address is optional in the request: an older client
        // that does not send it leaves it as it is.
        $alertEmail = null;
        try {
            $settings = MailSettings::validated($body);
            if (\array_key_exists('backup_alert_email', $body)) {
                $alertEmail = MailSettingsStore::validBackupAlertEmail($body['backup_alert_email']);
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->store->save($settings, $password);
        if ($alertEmail !== null) {
            $this->store->saveBackupAlertEmail($alertEmail);
        }

        return new JsonResponse(['data' => $this->publicData()]);
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

    /** @return array<string, mixed> the account without its password, plus the backup alert address */
    private function publicData(): array
    {
        return $this->store->load()->toPublicArray() + ['backup_alert_email' => $this->store->backupAlertEmail()];
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
