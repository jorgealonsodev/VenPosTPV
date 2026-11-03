<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Exchange\ExchangeService;
use VenPosTPV\Service\Refund\CreditNoteHandOver;
use VenPosTPV\Service\Refund\Exception\RefundException;

/**
 * Product exchanges of TPV sales: POST /api/exchange {ps_order_id, return_lines:
 * [{line_index, quantity, restock}], reason_code, reason_text, new_cart_data (as
 * /api/checkout cart_data), payment_method, amount_tendered, cash_amount?,
 * card_confirmed?}. The response carries credit, new_total and balance, the refund
 * part (refund_id, credit_note_number, print_status) and the sale part (ps_order_id,
 * reference, print_status), or status 'credit_open' with sale_error when only the
 * new sale failed: POST /api/exchange/{id}/retry completes it.
 */
#[AsController]
class ExchangeController
{
    public function __construct(
        private readonly ExchangeService $exchangeService,
        private readonly CreditNoteHandOver $handOver,
    ) {
    }

    #[Route('/exchange', methods: ['POST'])]
    public function exchange(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $psOrderId = isset($body['ps_order_id']) ? (int) $body['ps_order_id'] : 0;
        if ($psOrderId <= 0) {
            return new JsonResponse(['error' => 'Missing or invalid ps_order_id', 'retry' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cartData = \is_array($body['new_cart_data'] ?? null) ? $body['new_cart_data'] : [];
        if (isset($body['cash_amount']) && is_numeric($body['cash_amount'])) {
            $cartData['cash_amount'] = (float) $body['cash_amount'];
        }

        return $this->respond($request, fn (): array => $this->exchangeService->processExchange(
            (int) $session->get('user_id'),
            $psOrderId,
            \is_array($body['return_lines'] ?? null) ? array_values($body['return_lines']) : [],
            \is_string($body['reason_code'] ?? null) ? $body['reason_code'] : '',
            \is_string($body['reason_text'] ?? null) ? $body['reason_text'] : '',
            $cartData,
            \is_string($body['payment_method'] ?? null) ? $body['payment_method'] : '',
            isset($body['amount_tendered']) && is_numeric($body['amount_tendered']) ? (float) $body['amount_tendered'] : 0.0,
            ($body['card_confirmed'] ?? false) === true,
        ));
    }

    #[Route('/exchange/{id}/retry', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function retry(Request $request, int $id): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->respond($request, fn (): array => $this->exchangeService->retry((int) $session->get('user_id'), $id));
    }

    /** @param callable(): array<string, mixed> $operation */
    private function respond(Request $request, callable $operation): JsonResponse
    {
        try {
            $result = $operation();
        } catch (RefundException $e) {
            $status = match ($e->getCode()) {
                404 => Response::HTTP_NOT_FOUND,
                409 => Response::HTTP_CONFLICT,
                422 => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_BAD_GATEWAY,
            };

            return new JsonResponse(['error' => $e->getMessage(), 'retry' => $status === Response::HTTP_BAD_GATEWAY], $status);
        } catch (\Throwable $e) {
            if ($e instanceof NoOpenCashSessionException) {
                return new JsonResponse(['error' => $e->getMessage(), 'retry' => false], Response::HTTP_CONFLICT);
            }

            return new JsonResponse(['error' => 'Internal server error', 'retry' => true], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $terminalId = (int) ($request->getSession()->get('terminal_id') ?? 1);
        $refund = \is_array($result['refund'] ?? null) ? $result['refund'] : [];
        if (!empty($refund['resumed'])) {
            // Completed, printed and paid out by an earlier call.
            $refund['print_status'] = 'none';
        } else {
            $refund['print_status'] = $this->handOver->printCreditNote($refund, $terminalId);
            $this->handOver->openDrawerForCash($refund, $terminalId);
        }
        $result['refund'] = $refund;
        if (\is_array($result['sale'] ?? null)) {
            // Printed asynchronously by the checkout, like any sale.
            $result['sale']['print_status'] = 'dispatched';
        }

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }
}
