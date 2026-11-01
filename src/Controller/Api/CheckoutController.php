<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;

#[AsController]
class CheckoutController
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly CashSessionService $cashSessionService,
    ) {
    }

    #[Route('/checkout/coupon-preview', methods: ['POST'])]
    public function couponPreview(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $cartData = is_array($body['cart_data'] ?? null) ? $body['cart_data'] : [];
        $couponCode = is_string($body['coupon_code'] ?? null) ? $body['coupon_code'] : '';

        try {
            $preview = $this->checkoutService->previewCoupon((int) $session->get('user_id'), $cartData, $couponCode);
            return new JsonResponse(['data' => $preview], Response::HTTP_OK);
        } catch (NoOpenCashSessionException $e) {
            return new JsonResponse([
                'error' => 'No hay caja abierta. Abre la caja antes de validar un cupón.',
                'code' => 'cash_session_closed',
                'retry' => false,
            ], Response::HTTP_CONFLICT);
        } catch (CheckoutException $e) {
            if ($e->getCode() === 503) {
                return new JsonResponse(['error' => $e->getMessage(), 'code' => 'coupon_unavailable', 'retry' => false], Response::HTTP_SERVICE_UNAVAILABLE);
            }
            if ($e->getCode() === 422) {
                return new JsonResponse(['error' => $e->getMessage(), 'retry' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            return new JsonResponse(['error' => 'Coupon validation could not be completed', 'code' => 'coupon_reconcile_required', 'retry' => false], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Coupon validation could not be completed', 'code' => 'coupon_reconcile_required', 'retry' => false], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            $body = [];
        }

        $paymentMethod = isset($body['payment_method']) && \is_string($body['payment_method']) ? $body['payment_method'] : '';
        $amountTendered = isset($body['amount_tendered']) ? (float) $body['amount_tendered'] : 0.0;
        $cartData = isset($body['cart_data']) && \is_array($body['cart_data']) ? $body['cart_data'] : [];

        if ($paymentMethod === '') {
            return new JsonResponse(
                ['error' => 'Missing payment_method', 'retry' => false],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $userId = (int) $session->get('user_id');

        try {
            $this->cashSessionService->requireOpenSession($userId);
        } catch (NoOpenCashSessionException $e) {
            return new JsonResponse(
                [
                    'error' => 'No hay caja abierta. Abre la caja antes de cobrar.',
                    'code' => 'cash_session_closed',
                    'retry' => false,
                ],
                Response::HTTP_CONFLICT
            );
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => 'Internal server error', 'retry' => true],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $result = $this->checkoutService->createOrder($userId, $cartData, $paymentMethod, $amountTendered);

            $result['print_status'] = 'dispatched';

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (NoOpenCashSessionException $e) {
            return new JsonResponse(
                [
                    'error' => 'No hay caja abierta. Abre la caja antes de cobrar.',
                    'code' => 'cash_session_closed',
                    'retry' => false,
                ],
                Response::HTTP_CONFLICT
            );
        } catch (CheckoutException $e) {
            if ($e->getCode() === 409) {
                return new JsonResponse(
                    ['error' => $e->getMessage(), 'code' => 'coupon_reconcile_required', 'retry' => false],
                    Response::HTTP_CONFLICT
                );
            }
            if ($e->getCode() === 503) {
                return new JsonResponse(
                    ['error' => $e->getMessage(), 'code' => 'coupon_unavailable', 'retry' => false],
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }
            if ($e->getCode() === 422) {
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
}
