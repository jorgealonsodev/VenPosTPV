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
use VenPosTPV\Service\PrintAgent\TerminalNotResolvedException;
use VenPosTPV\Service\Refund\CreditNoteHandOver;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\OrderSearchService;
use VenPosTPV\Service\Refund\RefundService;

#[AsController]
class RefundController
{
    private readonly CreditNoteHandOver $handOver;

    public function __construct(
        private readonly RefundService $refundService,
        private readonly OrderSearchService $orderSearchService,
        private readonly CashSessionService $cashSessionService,
        private readonly PrinterService $printerService,
        private readonly TicketDataResolver $ticketDataResolver,
        private readonly TicketPdfGenerator $pdfGenerator,
        ?CreditNoteHandOver $handOver = null,
    ) {
        $this->handOver = $handOver ?? new CreditNoteHandOver($printerService);
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

        // A missing "lines" refunds everything left; a malformed one must never do that.
        if (isset($body['lines']) && !\is_array($body['lines'])) {
            return new JsonResponse(['error' => 'lines must be a list of lines to refund', 'retry' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $lines = isset($body['lines']) ? $body['lines'] : null;
        $reasonCode = isset($body['reason_code']) && \is_string($body['reason_code']) ? $body['reason_code'] : '';
        $reasonText = isset($body['reason_text']) && \is_string($body['reason_text']) ? $body['reason_text'] : '';
        $cardConfirmed = ($body['card_confirmed'] ?? false) === true;
        $voucherFallback = isset($body['voucher_fallback']) && \is_string($body['voucher_fallback']) && $body['voucher_fallback'] !== '' ? $body['voucher_fallback'] : null;

        // The sale: by its local order_id or ticket reference (also before it is
        // uploaded, PRD D1), or by ps_order_id for compatibility and online orders.
        $target = self::saleTarget($body, $this->orderSearchService);
        if ($target === null) {
            return new JsonResponse(['error' => 'Missing or invalid ps_order_id', 'retry' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($target === []) {
            return new JsonResponse(['error' => 'Sale not found', 'retry' => false], Response::HTTP_NOT_FOUND);
        }

        $userId = (int) $session->get('user_id');
        $shopId = (int) $session->get('shop_id', 0);

        try {
            $this->cashSessionService->requireOpenSession($userId);
            $result = isset($target['order_id'])
                ? $this->refundService->processSaleRefund($userId, $target['order_id'], $lines, $reasonCode, $reasonText, $cardConfirmed, $shopId, $voucherFallback)
                : $this->refundService->processRefund($userId, $target['ps_order_id'], $lines, $reasonCode, $reasonText, $cardConfirmed, $shopId, $voucherFallback);
            $result = $this->handOverRefund($result, $request);

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (RefundException $e) {
            return self::refundError($e);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => 'Internal server error', 'retry' => true],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * The sale a request names: ['order_id' => id] for a TPV sale given by order_id or
     * reference, ['ps_order_id' => id] when only ps_order_id is given (a TPV sale or
     * an online order, resolved by the service), [] when the order_id or reference
     * matches no TPV sale, null when nothing usable was sent. Shared with exchanges.
     *
     * @param array<string, mixed> $body
     *
     * @return array{order_id?: int, ps_order_id?: int}|null
     */
    public static function saleTarget(array $body, OrderSearchService $orderSearchService): ?array
    {
        $orderId = isset($body['order_id']) && is_numeric($body['order_id']) ? (int) $body['order_id'] : 0;
        $reference = isset($body['reference']) && \is_string($body['reference']) ? trim($body['reference']) : '';
        if ($orderId > 0 || $reference !== '') {
            $found = $orderSearchService->findSaleId($orderId, $reference);

            return $found !== null ? ['order_id' => $found] : [];
        }
        $psOrderId = isset($body['ps_order_id']) && is_numeric($body['ps_order_id']) ? (int) $body['ps_order_id'] : 0;

        return $psOrderId > 0 ? ['ps_order_id' => $psOrderId] : null;
    }

    /**
     * 404/409/422 as such; 503 voucher_unavailable when PrestaShop cannot create a
     * voucher (the cashier picks another tender, PRD D3); anything else is a
     * retryable 502.
     */
    public static function refundError(RefundException $e): JsonResponse
    {
        if ($e->getCode() === RefundException::VOUCHER_UNAVAILABLE) {
            return new JsonResponse(
                ['error' => $e->getMessage(), 'code' => 'voucher_unavailable', 'retry' => false],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }
        $status = match ($e->getCode()) {
            404 => Response::HTTP_NOT_FOUND,
            409 => Response::HTTP_CONFLICT,
            422 => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_GATEWAY,
        };

        return new JsonResponse(['error' => $e->getMessage(), 'retry' => $status === Response::HTTP_BAD_GATEWAY], $status);
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
            $result = $this->handOverRefund($result, $request);

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (RefundException $e) {
            return self::refundError($e);
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
            $result = $this->handOverRefund($result, $request);

            return new JsonResponse(['data' => $result], Response::HTTP_OK);
        } catch (RefundException $e) {
            return self::refundError($e);
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
     * Credit note and drawer of a completed refund (shared with exchanges). A till
     * with a print agent also gets the ids of the queued jobs (print_job_id,
     * drawer_job_id); without a till in the session its jobs are not created and
     * print_error is terminal_not_resolved (T9).
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function handOverForTill(array $result, ?int $terminalId, CreditNoteHandOver $handOver, ?PrinterService $printerService): array
    {
        if ($terminalId === null && $printerService !== null && $printerService->usesAgent(null)) {
            $result['print_status'] = isset($result['refund_id']) ? 'pdf' : 'none';
            $result['print_error'] = TerminalNotResolvedException::CODE;

            return $result;
        }

        $terminalId ??= 1;
        $result['print_status'] = $handOver->printCreditNote($result, $terminalId);
        if ($result['print_status'] === 'queued' && $printerService?->queuedJobId('ticket') !== null) {
            $result['print_job_id'] = $printerService->queuedJobId('ticket');
        }
        $drawerBefore = $printerService?->queuedJobId('drawer');
        $handOver->openDrawerForCash($result, $terminalId);
        $drawerJob = $printerService?->queuedJobId('drawer');
        if ($drawerJob !== null && $drawerJob !== $drawerBefore) {
            $result['drawer_job_id'] = $drawerJob;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function handOverRefund(array $result, Request $request): array
    {
        $terminal = $request->getSession()->get('terminal_id');

        return self::handOverForTill($result, $terminal !== null ? (int) $terminal : null, $this->handOver, $this->printerService);
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
        } catch (RefundException $e) {
            // No TPV sale matched and the shop could not be asked for an online order.
            return new JsonResponse(
                ['error' => 'Online orders cannot be searched right now: ' . $e->getMessage(), 'retry' => true],
                Response::HTTP_BAD_GATEWAY
            );
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => 'Search failed', 'retry' => false],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
