<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;

/**
 * Ajustes → Acerca de → «Estado de la instalación»: every provisioning step with
 * its state and, when it is not done, its reason (admins only). Read-only: the
 * steps run from the minute task, the installer and tpv:provision.
 */
#[AsController]
final class ProvisioningController
{
    public function __construct(private readonly ProvisioningRunner $runner)
    {
    }

    #[Route('/settings/provisioning', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $this->runner->status()->toArray()]);
    }
}
