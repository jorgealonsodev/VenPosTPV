<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;

#[AsController]
final class CouponController
{
    public function __construct(private readonly CouponCreationService $couponCreationService)
    {
    }

    #[Route('/coupons', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');
        if (!is_int($userId) || $userId <= 0) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!in_array($session->get('role'), ['admin', 'cashier'], true)) {
            return new JsonResponse(['error' => 'Acceso denegado'], Response::HTTP_FORBIDDEN);
        }
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }
        try {
            return new JsonResponse(['data' => $this->couponCreationService->create($body, (int) $session->get('shop_id', 0))], Response::HTTP_CREATED);
        } catch (CouponCreationException $e) {
            return new JsonResponse(['error' => $e->getMessage()], in_array($e->getCode(), [409, 422, 503], true) ? $e->getCode() : 503);
        }
    }
}
