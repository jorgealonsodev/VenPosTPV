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

#[AsController]
final class FiscalController
{
    public function __construct(private readonly Connection $connection)
    {
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
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }

        try {
            return new JsonResponse(['data' => (new FiscalChainVerifier($this->connection))->verify()]);
        } catch (TableNotFoundException) {
            return new JsonResponse(['error' => 'Falta aplicar la migración de registros fiscales'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
