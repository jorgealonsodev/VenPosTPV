<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Customer\Exception\CustomerException;

/**
 * Customers for the sale screen (PRD C1, C2): searched in the local copy, so it
 * works without PrestaShop, and created at the till with their upload queued.
 */
#[AsController]
final class CustomerController
{
    public function __construct(private readonly CustomerDirectory $customers)
    {
    }

    #[Route('/customers', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $query = $request->query->get('q', '');

        return new JsonResponse(['data' => $this->customers->search(trim(\is_string($query) ? $query : ''))], Response::HTTP_OK);
    }

    #[Route('/customers', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        // Like the device endpoints: only a JSON request (a cross-site form post
        // cannot send one without a CORS preflight).
        if ($request->getContentTypeFormat() !== 'json') {
            return new JsonResponse(['error' => 'Se espera un cuerpo JSON'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        $body = json_decode((string) $request->getContent(), true);

        try {
            $customer = $this->customers->create(\is_array($body) ? $body : []);
        } catch (CustomerException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() === Response::HTTP_CONFLICT ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $customer], $customer['existing'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }
}
