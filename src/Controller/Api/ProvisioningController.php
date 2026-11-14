<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cron\CronRunner;
use VenPosTPV\Service\Provisioning\ProvisioningRunner;

/**
 * Ajustes → Acerca de → «Estado de la instalación»: every provisioning step with
 * its state and, when it is not done, its reason (admins only). Read-only: the
 * steps run from the minute task, the installer and tpv:provision. «cron» is the
 * state of that minute task (tpv:cron) and of each of its jobs.
 */
#[AsController]
final class ProvisioningController
{
    public function __construct(
        private readonly ProvisioningRunner $runner,
        private readonly ?CronRunner $cron = null,
    ) {
    }

    #[Route('/settings/provisioning', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }

        $data = $this->runner->status()->toArray();
        if ($this->cron !== null) {
            $data['cron'] = $this->cron->status();
        }

        return new JsonResponse(['data' => $data]);
    }
}
