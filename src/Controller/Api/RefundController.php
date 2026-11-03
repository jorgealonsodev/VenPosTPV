<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\RefundService;

#[AsController]
class RefundController
{
    public function __construct(
        private readonly RefundService $refundService,
        private readonly OrderSearchService $orderSearchService,
        private readonly CashSessionService $cashSessionService,
    ) {
    }

    #[Route('/refund', methods: ['POST'])]
    public function refund(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            $body = [];
        }

        $psOrderId = isset($body['ps_order_id']) ? (int) $body['ps_order_id'] : 0;
        $lines = isset($body['lines']) && \is_array($body['lines']) ? $body['lines'] : null;
        $reasonCode = isset($body['reason_code']) && \is_string($body['reason_code']) ? $body['reason_code'] : '';
        $reasonText = isset($body['reason_text']) && \is_string($body['reason_text']) ? $body['reason_text'] : '';
        $cardConfirmed = ($body['card_confirmed'] ?? false) === true;

        if ($psOrderId <= 0) {
            return new JsonResponse(
                ['error' => 'Missing or invalid ps_order_id', 'retry' => false],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $userId = (int) $session->get('user_id');

        try {
            $this->cashSessionService->requireOpenSession($userId);
            $result = $this->refundService->processRefund($userId, $psOrderId, $lines, $reasonCode, $reasonText, $cardConfirmed);

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (RefundException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return new JsonResponse(
                    ['error' => $e->getMessage(), 'retry' => false],
                    Response::HTTP_NOT_FOUND
                );
            }

            if ($code === 409) {
                return new JsonResponse(
                    ['error' => $e->getMessage(), 'retry' => false],
                    Response::HTTP_CONFLICT
                );
            }

            if ($code === 422) {
                return new JsonResponse(
                    ['error' => $e->getMessage(), 'retry' => false],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            return new JsonResponse(
                ['error' => $e->getMessage(), 'retry' => true],
                Response::HTTP_BAD_GATEWAY
            );
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => 'Internal server error', 'retry' => true],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[Route('/orders/search', methods: ['GET'])]
    public function searchOrders(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $query = [];

        $psOrderId = $request->query->get('ps_order_id');
        if ($psOrderId !== null && $psOrderId !== '') {
            $query['ps_order_id'] = (int) $psOrderId;
        }

        $q = $request->query->get('q');
        if ($q !== null && $q !== '' && \is_string($q)) {
            // If q looks like a number, treat as ps_order_id; otherwise as reference
            if (is_numeric($q)) {
                $query['ps_order_id'] = (int) $q;
            } else {
                $query['reference'] = $q;
            }
        }

        $dateFrom = $request->query->get('date_from');
        if ($dateFrom !== null && \is_string($dateFrom) && $dateFrom !== '') {
            $query['date_from'] = $dateFrom;
        }

        $dateTo = $request->query->get('date_to');
        if ($dateTo !== null && \is_string($dateTo) && $dateTo !== '') {
            $query['date_to'] = $dateTo;
        }

        $refunded = $request->query->get('refunded');
        if ($refunded !== null && $refunded !== '' && $refunded !== '0' && $refunded !== 'false') {
            $query['has_refund'] = true;
        }

        try {
            $results = $this->orderSearchService->search($query);

            return new JsonResponse(['data' => $results], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => 'Search failed', 'retry' => false],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
