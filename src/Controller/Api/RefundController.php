<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\Exception\NoOpenCashSessionException;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;
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
        private readonly PrinterService $printerService,
        private readonly TicketDataResolver $ticketDataResolver,
        private readonly TicketPdfGenerator $pdfGenerator,
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
            $result['print_status'] = $this->printCreditNote($result, $request);

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

    /**
     * Refund without a ticket: {lines: [{product_id, id_product_attribute, quantity,
     * restock}], method: cash|card|voucher, reason_code, reason_text, card_confirmed}.
     * Amounts come from the current catalog price on the server; any price the
     * browser sends is ignored.
     */
    #[Route('/refund/no-ticket', methods: ['POST'])]
    public function refundWithoutTicket(Request $request): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            $body = [];
        }

        $lines = isset($body['lines']) && \is_array($body['lines']) ? array_values($body['lines']) : [];
        $method = isset($body['method']) && \is_string($body['method']) ? $body['method'] : '';
        $reasonCode = isset($body['reason_code']) && \is_string($body['reason_code']) ? $body['reason_code'] : '';
        $reasonText = isset($body['reason_text']) && \is_string($body['reason_text']) ? $body['reason_text'] : '';
        $cardConfirmed = ($body['card_confirmed'] ?? false) === true;

        try {
            $result = $this->refundService->processNoTicketRefund(
                (int) $session->get('user_id'),
                (int) $session->get('shop_id', 0),
                $lines,
                $method,
                $reasonCode,
                $reasonText,
                $cardConfirmed,
            );
            $result['print_status'] = $this->printCreditNote($result, $request);

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (RefundException $e) {
            $status = match ($e->getCode()) {
                404 => Response::HTTP_NOT_FOUND,
                409 => Response::HTTP_CONFLICT,
                422 => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_BAD_GATEWAY,
            };

            return new JsonResponse(['error' => $e->getMessage(), 'retry' => $status === Response::HTTP_BAD_GATEWAY], $status);
        } catch (NoOpenCashSessionException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'retry' => false], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Internal server error', 'retry' => true], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Refunds without a ticket still waiting for PrestaShop, to retry them from the screen. */
    #[Route('/refund/pending-no-ticket', methods: ['GET'])]
    public function pendingWithoutTicket(Request $request): JsonResponse
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return new JsonResponse(['data' => $this->refundService->pendingNoTicketRefunds()], Response::HTTP_OK);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/refund/{id}/retry', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function retry(Request $request, int $id): JsonResponse
    {
        $session = $request->getSession();
        if ($session->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->refundService->retryPending((int) $session->get('user_id'), $id);
            $result['print_status'] = $this->printCreditNote($result, $request);

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (RefundException $e) {
            $status = match ($e->getCode()) {
                404 => Response::HTTP_NOT_FOUND,
                409 => Response::HTTP_CONFLICT,
                422 => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_BAD_GATEWAY,
            };

            return new JsonResponse(['error' => $e->getMessage(), 'retry' => $status === Response::HTTP_BAD_GATEWAY], $status);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Internal server error', 'retry' => true], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/refund/{id}/pdf', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function creditNotePdf(Request $request, int $id): Response
    {
        if ($request->getSession()->get('user_id') === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $ticketData = $this->ticketDataResolver->resolveRefund($id);
        if ($ticketData === null) {
            return new JsonResponse(['error' => 'Refund not found'], Response::HTTP_NOT_FOUND);
        }

        $pdf = $this->pdfGenerator->generate($ticketData);

        return new StreamedResponse(
            static function () use ($pdf): void {
                echo $pdf;
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('inline; filename="abono-%s.pdf"', $ticketData->reference),
            ]
        );
    }

    /**
     * The credit note is always handed over: 'printed' on the network printer, or
     * 'pdf' so the screen opens it; 'none' when no credit note exists yet. A print
     * failure never undoes the refund.
     *
     * @param array<string, mixed> $result
     */
    private function printCreditNote(array $result, Request $request): string
    {
        if (!isset($result['refund_id'])) {
            return 'none';
        }
        if (!empty($result['saga_error'])) {
            // The shop refunded it but the local completion failed: no credit note
            // exists yet, so there is nothing to print until the refund is retried.
            return 'none';
        }
        if (!empty($result['already_completed'])) {
            return 'pdf';
        }

        try {
            return $this->printerService->printRefundTicket((int) $result['refund_id'], (int) ($request->getSession()->get('terminal_id') ?? 1));
        } catch (\Throwable) {
            return 'pdf';
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
