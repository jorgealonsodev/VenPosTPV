<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Numbering\NumberingSettings;

/**
 * Admin only: where the ticket series of each till and the credit-note series of
 * the year continue (Ajustes → Cajas), forward only and audited.
 */
#[AsController]
final class NumberingController
{
    private readonly NumberingSettings $numbering;

    public function __construct(
        private readonly Connection $connection,
        ?NumberingSettings $numbering = null,
    ) {
        $this->numbering = $numbering ?? new NumberingSettings($connection);
    }

    /** {year, tills: [{terminal_id, name, letter, series, last_number, next_number, next_code}], credit_notes, changes} */
    #[Route('/numbering', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->denied();
        }

        return new JsonResponse(['data' => $this->numbering->overview($this->shopId($request), new \DateTimeImmutable())]);
    }

    /** Body {next_number, reason}: the till's letter continues at next_number this year. */
    #[Route('/numbering/tickets/{terminalId}', methods: ['PUT'], requirements: ['terminalId' => '\d+'])]
    public function setTicket(int $terminalId, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->denied();
        }
        $exists = $this->connection->fetchOne('SELECT 1 FROM terminal WHERE id = ?', [$terminalId]);
        if ($exists === false) {
            return new JsonResponse(['error' => 'Caja no encontrada'], Response::HTTP_NOT_FOUND);
        }
        $body = $this->body($request);

        return $this->changed(fn (): array => $this->numbering->setTicketNext($terminalId, $body['next_number'] ?? null, $body['reason'] ?? null, $this->userId($request), new \DateTimeImmutable()));
    }

    /** Body {next_number, reason}: the credit notes continue at next_number this year. */
    #[Route('/numbering/credit-notes', methods: ['PUT'])]
    public function setCreditNote(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->denied();
        }
        $body = $this->body($request);

        return $this->changed(fn (): array => $this->numbering->setCreditNoteNext($body['next_number'] ?? null, $body['reason'] ?? null, $this->userId($request), new \DateTimeImmutable()));
    }

    /** @param callable(): array<string, mixed> $change */
    private function changed(callable $change): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $change()]);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    private function isAdmin(Request $request): bool
    {
        return $request->getSession()->get('role') === 'admin';
    }

    private function userId(Request $request): ?int
    {
        $id = (int) ($request->getSession()->get('user_id') ?? 0);

        return $id > 0 ? $id : null;
    }

    private function shopId(Request $request): int
    {
        $shopId = (int) ($request->getSession()->get('shop_id') ?? 0);

        return $shopId > 0 ? $shopId : 1;
    }

    private function denied(): JsonResponse
    {
        return new JsonResponse(['error' => 'Solo un administrador puede cambiar la numeración'], Response::HTTP_FORBIDDEN);
    }
}
