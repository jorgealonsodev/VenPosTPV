<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Checkout\PaymentMethodsSettings;

/**
 * Ajustes → Tienda → «Métodos de pago». GET (anyone signed in: the payment window
 * shows only these) {data: {enabled: [...], available: [...]}}; PUT {enabled: [...]}
 * admins only. The checkout refuses a method that is not enabled.
 */
#[AsController]
final class PaymentMethodsController
{
    public function __construct(private readonly PaymentMethodsSettings $settings)
    {
    }

    #[Route('/settings/payment-methods', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->answer();
    }

    #[Route('/settings/payment-methods', methods: ['PUT'])]
    public function save(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
        }
        $body = json_decode((string) $request->getContent(), true);
        try {
            $this->settings->save(\is_array($body) ? ($body['enabled'] ?? null) : null);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->answer();
    }

    private function answer(): JsonResponse
    {
        return new JsonResponse(['data' => ['enabled' => $this->settings->enabled(), 'available' => PaymentMethodsSettings::METHODS]]);
    }
}
