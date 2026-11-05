<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\VerifactuSettings;

#[AsController]
final class FiscalController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?VerifactuSettings $verifactu = null,
    ) {
    }

    /** Admin only: {enabled} — whether tickets carry the VERI*FACTU QR and legend. */
    #[Route('/fiscal/settings', methods: ['GET'])]
    public function settings(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return $this->denied();
        }

        return new JsonResponse(['data' => [
            'enabled' => $this->verifactu()->isEnabled(),
            'available' => $this->verifactu()->canEnable(),
            'unavailable_reason' => $this->verifactu()->canEnable() ? null : VerifactuSettings::UNAVAILABLE_MESSAGE,
        ]]);
    }

    /** Admin only: {enabled: bool}. The fiscal records are written either way. */
    #[Route('/fiscal/settings', methods: ['PUT'])]
    public function saveSettings(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return $this->denied();
        }
        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body) || !\is_bool($body['enabled'] ?? null)) {
            return new JsonResponse(['error' => 'Indica si VERI*FACTU está activado (enabled: true o false)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->verifactu()->setEnabled($body['enabled']);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['data' => ['enabled' => $body['enabled']]]);
    }

    /**
     * Admin only: recomputes the whole chain of fiscal records.
     * {ok, records, broken_id, reason, protected}; protected says whether MySQL
     * itself refuses changes (triggers) or only the application does.
     */
    #[Route('/fiscal/chain', methods: ['GET'])]
    public function chain(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return $this->denied();
        }

        try {
            return new JsonResponse(['data' => (new FiscalChainVerifier($this->connection))->verify()]);
        } catch (TableNotFoundException) {
            return new JsonResponse(['error' => 'Falta aplicar la migración de registros fiscales'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function verifactu(): VerifactuSettings
    {
        return $this->verifactu ?? new VerifactuSettings($this->connection);
    }

    private function denied(): JsonResponse
    {
        return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
    }
}
