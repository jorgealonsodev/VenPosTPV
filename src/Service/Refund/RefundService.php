<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Catalog\CatalogPriceResolver;
use VenPosTPV\Service\Catalog\Exception\ProductNotInCatalogException;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;
use VenPosTPV\Service\Refund\Exception\RefundException;

/**
 * Refunds lines of a TPV sale.
 *
 * Amounts always come from what the sale recorded as paid per line (order_meta
 * paid_total), never from the browser; money goes back by the original tender
 * split; quantities are capped by what earlier refunds already returned. The
 * refund row is written before PrestaShop is called, so a call with an unknown
 * outcome is resumed with the same request_id instead of refunding twice.
 *
 * Refunds without a ticket follow the same lifecycle (pending row first, explicit
 * retry, numbering on completion) with no order: the amount is the current
 * catalog price, the cashier picks cash, card or a PrestaShop voucher, and the
 * shop only restocks.
 */
class RefundService
{
    public const REASONS = [
        'size',
        'defective',
        'dislike',
        'billing_error',
        'not_as_described',
        'gift_duplicate',
        'late',
        'other',
    ];

    public const NO_TICKET_METHODS = ['cash', 'card', 'voucher'];

    private const VOUCHER_VALIDITY = '+1 year';

    public function __construct(
        private readonly OrderSearchService $orderSearchService,
        private readonly PsRefundClient $client,
        private readonly Connection $conn,
        private readonly CashSessionService $cashSessionService,
        private readonly ?CreditNoteNumberer $creditNoteNumberer = null,
        private readonly ?PsRestockClient $restockClient = null,
        private readonly ?CouponCreationService $couponCreationService = null,
        private readonly ?CatalogPriceResolver $priceResolver = null,
    ) {
    }

    /**
     * @param array<int, array{line_index?: mixed, quantity?: mixed, restock?: mixed}>|null $lines null refunds everything still refundable
     *
     * @return array{refund_id: int, credit_note_number: ?string, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, voucher_code: ?string, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool, already_completed: bool}
     *
     * @throws RefundException
     */
    public function processRefund(
        int $userId,
        int $psOrderId,
        ?array $lines,
        string $reasonCode,
        string $reasonText = '',
        bool $cardConfirmed = false,
    ): array {
        $reasonText = $this->assertReason($reasonCode, $reasonText);

        $cashContext = $this->cashSessionService->requireOpenSession($userId);

        $requestId = bin2hex(random_bytes(16));

        // Planning and reserving happen under a lock on the sale row, so two
        // terminals refunding the same sale cannot both take the same units.
        [$refundId, $payload] = $this->conn->transactional(function () use ($psOrderId, $lines, $cardConfirmed, $requestId, $cashContext, $userId, $reasonCode, $reasonText): array {
            $locked = $this->conn->fetchOne('SELECT ps_order_id FROM order_meta WHERE ps_order_id = ? FOR UPDATE', [$psOrderId]);
            if ($locked === false) {
                throw RefundException::orderNotFound($psOrderId);
            }

            // An earlier call with an unknown outcome must be settled explicitly first.
            $pending = $this->conn->fetchOne(
                "SELECT id FROM refund WHERE ps_order_id = ? AND status = 'pending' ORDER BY id LIMIT 1",
                [$psOrderId]
            );
            if ($pending !== false) {
                throw RefundException::conflict(sprintf(
                    'Refund #%d of order %d is waiting for PrestaShop confirmation; retry it before starting a new one',
                    (int) $pending,
                    $psOrderId
                ));
            }

            $order = $this->orderSearchService->getOrder($psOrderId);
            if ($order === null) {
                throw RefundException::orderNotFound($psOrderId);
            }

            $this->assertWithinRefundWindow($order, (int) ($cashContext['terminal_id'] ?? 0));

            $planned = $this->planLines($order, $lines);
            $total = round(array_sum(array_column($planned, 'amount')), 2);
            if ($total <= 0) {
                throw RefundException::validationFailed('Refund total must be greater than 0');
            }

            [$cashAmount, $cardAmount] = $this->splitByTender($order, $total);
            $this->assertCardConfirmed($cardAmount, $cardConfirmed);

            $payload = [
                'ps_order_id' => $psOrderId,
                'request_id' => $requestId,
                'refund_type' => $lines === null ? 'full' : 'partial',
                'reason' => $reasonCode === 'other' ? $reasonText : $reasonCode,
                'lines' => array_map(static fn (array $line): array => [
                    'id_product' => $line['product_id'],
                    'id_product_attribute' => $line['id_product_attribute'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'restock' => $line['restock'],
                ], $planned),
            ];

            $this->conn->insert('refund', [
                'request_id' => $requestId,
                'status' => 'pending',
                'ps_order_id' => $psOrderId,
                'terminal_id' => (int) ($cashContext['terminal_id'] ?? 1),
                'session_id' => (int) $cashContext['session_id'],
                'user_id' => $userId,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText !== '' ? mb_substr($reasonText, 0, 255) : null,
                'total' => $total,
                'cash_amount' => $cashAmount,
                'card_amount' => $cardAmount,
                'card_confirmed' => $cardConfirmed ? 1 : 0,
                'ps_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            $refundId = (int) $this->conn->lastInsertId();

            foreach ($planned as $line) {
                $this->conn->insert('refund_line', [
                    'refund_id' => $refundId,
                    'line_index' => $line['line_index'],
                    'product_id' => $line['product_id'],
                    'id_product_attribute' => $line['id_product_attribute'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'restock' => $line['restock'] ? 1 : 0,
                ]);
            }

            return [$refundId, $payload];
        });

        return $this->send($refundId, $payload, (int) $cashContext['session_id']);
    }

    /**
     * Re-sends a refund whose PrestaShop outcome was unknown, with the same
     * request_id so the shop replays it instead of refunding twice. Cash is paid
     * out now, so the movement goes to the current open session.
     *
     * @return array{refund_id: int, credit_note_number: ?string, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, voucher_code: ?string, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool, already_completed: bool}
     *
     * @throws RefundException
     */
    public function retryPending(int $userId, int $refundId): array
    {
        $cashContext = $this->cashSessionService->requireOpenSession($userId);

        $row = $this->conn->fetchAssociative("SELECT ps_payload FROM refund WHERE id = ? AND status = 'pending'", [$refundId]);
        if ($row === false) {
            throw RefundException::conflict(sprintf('Refund #%d is not waiting for confirmation', $refundId));
        }

        $payload = json_decode((string) $row['ps_payload'], true);
        if (!\is_array($payload)) {
            throw RefundException::validationFailed(sprintf('Pending refund %d cannot be resumed', $refundId));
        }

        return $this->send($refundId, $payload, (int) $cashContext['session_id']);
    }

    /**
     * Refund without a ticket: the goods come back at their current catalog price
     * (never the browser's), paid by the method the cashier chooses. Only lines
     * marked for restock are sent to PrestaShop; nothing else is recorded there.
     *
     * @param array<int, mixed> $lines [{product_id, id_product_attribute, quantity, restock}]
     *
     * @return array{refund_id: int, credit_note_number: ?string, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, voucher_code: ?string, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool, already_completed: bool}
     *
     * @throws RefundException
     */
    public function processNoTicketRefund(
        int $userId,
        int $shopId,
        array $lines,
        string $method,
        string $reasonCode,
        string $reasonText = '',
        bool $cardConfirmed = false,
    ): array {
        if (!\in_array($method, self::NO_TICKET_METHODS, true)) {
            throw RefundException::validationFailed('Choose cash, card or voucher');
        }
        $reasonText = $this->assertReason($reasonCode, $reasonText);
        if ($method === 'voucher' && $shopId <= 0) {
            throw RefundException::validationFailed('The shop is unknown, so no voucher can be issued');
        }

        $planned = $this->planNoTicketLines($lines);
        $total = round(array_sum(array_column($planned, 'amount')), 2);
        if ($total <= 0) {
            throw RefundException::validationFailed('Refund total must be greater than 0');
        }
        $this->assertCardConfirmed($method === 'card' ? $total : 0.0, $cardConfirmed);

        $cashContext = $this->cashSessionService->requireOpenSession($userId);
        $requestId = bin2hex(random_bytes(16));

        $payload = [
            'kind' => 'no_ticket',
            'request_id' => $requestId,
            'method' => $method,
            'shop_id' => $shopId,
            'lines' => array_values(array_map(static fn (array $line): array => [
                'id_product' => $line['product_id'],
                'id_product_attribute' => $line['id_product_attribute'],
                'quantity' => $line['quantity'],
            ], array_filter($planned, static fn (array $line): bool => $line['restock']))),
        ];

        $refundId = $this->conn->transactional(function () use ($requestId, $cashContext, $userId, $reasonCode, $reasonText, $total, $method, $cardConfirmed, $payload, $planned): int {
            $this->conn->insert('refund', [
                'request_id' => $requestId,
                'status' => 'pending',
                'ps_order_id' => null,
                'terminal_id' => (int) ($cashContext['terminal_id'] ?? 1),
                'session_id' => (int) $cashContext['session_id'],
                'user_id' => $userId,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText !== '' ? mb_substr($reasonText, 0, 255) : null,
                'total' => $total,
                'cash_amount' => $method === 'cash' ? $total : 0,
                'card_amount' => $method === 'card' ? $total : 0,
                'card_confirmed' => $method === 'card' && $cardConfirmed ? 1 : 0,
                'voucher_amount' => $method === 'voucher' ? $total : null,
                'ps_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            $refundId = (int) $this->conn->lastInsertId();

            foreach ($planned as $line) {
                $this->conn->insert('refund_line', [
                    'refund_id' => $refundId,
                    'line_index' => null,
                    'product_id' => $line['product_id'],
                    'id_product_attribute' => $line['id_product_attribute'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'restock' => $line['restock'] ? 1 : 0,
                ]);
            }

            return $refundId;
        });

        return $this->send($refundId, $payload, (int) $cashContext['session_id']);
    }

    /**
     * Refunds without a ticket still waiting for PrestaShop, so the screen can
     * offer to retry them (they belong to no order the cashier could search).
     *
     * @return array<int, array{id: int, total: float, method: string, created_at: string, error: ?string, voucher_unknown: bool}>
     */
    public function pendingNoTicketRefunds(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT id, total, cash_amount, card_amount, voucher_amount, voucher_code, voucher_requested_at, error_message, created_at
             FROM refund WHERE ps_order_id IS NULL AND status = 'pending' ORDER BY id"
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'total' => round((float) $row['total'], 2),
            'method' => $row['voucher_amount'] !== null ? 'voucher' : ((float) $row['card_amount'] > 0 ? 'card' : 'cash'),
            'created_at' => (string) $row['created_at'],
            'error' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'voucher_unknown' => $row['voucher_requested_at'] !== null && $row['voucher_code'] === null,
        ], $rows);
    }

    /**
     * Sends a stored refund to PrestaShop and applies its local effects.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{refund_id: int, credit_note_number: ?string, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, voucher_code: ?string, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool, already_completed: bool}
     */
    private function send(int $refundId, array $payload, int $sessionId): array
    {
        if (($payload['kind'] ?? null) === 'no_ticket') {
            return $this->sendNoTicket($refundId, $payload, $sessionId);
        }

        try {
            $psResponse = $this->client->createRefund($payload);
        } catch (RefundException $e) {
            if ($e->getCode() === 404 || $e->getCode() === 422) {
                // Definitely rejected: nothing happened in the shop. A 409 only means
                // the order was busy, so the outcome is still unknown.
                $this->conn->update('refund', [
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                ], ['id' => $refundId]);
            }
            // Otherwise the outcome is unknown and the row stays pending for resumePending().

            throw $e;
        }

        return $this->complete($refundId, $psResponse, $sessionId);
    }

    /**
     * Restocks in PrestaShop (replayed by request_id on a retry), issues the voucher
     * when that is the method, then completes the refund locally.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{refund_id: int, credit_note_number: ?string, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, voucher_code: ?string, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool, already_completed: bool}
     */
    private function sendNoTicket(int $refundId, array $payload, int $sessionId): array
    {
        $psResponse = ['order_slip_id' => 0, 'refunded_amount' => 0.0, 'refunded_lines' => [], 'restock_failed' => []];

        $restockLines = \is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        if ($restockLines !== []) {
            if ($this->restockClient === null) {
                throw RefundException::psUnavailable('The PrestaShop restock client is not configured');
            }
            try {
                $restock = $this->restockClient->restock(['request_id' => (string) $payload['request_id'], 'lines' => $restockLines]);
            } catch (RefundException $e) {
                if ($e->getCode() === 404 || $e->getCode() === 422) {
                    // Definitely rejected before any stock moved.
                    $this->conn->update('refund', [
                        'status' => 'failed',
                        'error_message' => mb_substr($e->getMessage(), 0, 1000),
                    ], ['id' => $refundId, 'status' => 'pending']);
                }

                throw $e;
            }
            $psResponse['refunded_lines'] = $restock['restocked_lines'];
            $psResponse['restock_failed'] = $restock['restock_failed'];
        }

        if (($payload['method'] ?? null) === 'voucher') {
            $this->issueVoucher($refundId, (int) ($payload['shop_id'] ?? 0));
        }

        return $this->complete($refundId, $psResponse, $sessionId);
    }

    /**
     * Issues the refund's voucher at most once. The request is marked before the
     * cart rule is posted; when its outcome is unknown the mark stays, and the
     * refund stays pending until someone checks PrestaShop: a second voucher is
     * never created automatically.
     *
     * @throws RefundException
     */
    private function issueVoucher(int $refundId, int $shopId): void
    {
        $row = $this->conn->fetchAssociative('SELECT voucher_code, voucher_amount, voucher_requested_at, error_message FROM refund WHERE id = ?', [$refundId]);
        if ($row === false) {
            throw RefundException::conflict(sprintf('Refund #%d does not exist', $refundId));
        }
        if ($row['voucher_code'] !== null) {
            return;
        }
        if ($this->couponCreationService === null) {
            throw RefundException::psUnavailable('The voucher service is not configured');
        }

        $claimed = $this->conn->executeStatement(
            "UPDATE refund SET voucher_requested_at = ? WHERE id = ? AND status = 'pending' AND voucher_requested_at IS NULL AND voucher_code IS NULL",
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $refundId]
        );
        if ($claimed === 0) {
            throw RefundException::conflict(sprintf(
                'The voucher of refund #%d may already exist in PrestaShop%s. Check it there; a voucher is never created twice automatically',
                $refundId,
                $row['error_message'] !== null ? ' (' . $row['error_message'] . ')' : ''
            ));
        }

        try {
            $coupon = $this->couponCreationService->create([
                'discount_type' => 'amount',
                'value' => round((float) $row['voucher_amount'], 2),
                'quantity' => 1,
                'quantity_per_user' => 1,
                'expires_at' => (new \DateTimeImmutable(self::VOUCHER_VALIDITY))->format('Y-m-d'),
                'partial_use' => true,
            ], $shopId);
        } catch (CouponCreationException $e) {
            if ($e->getCode() !== 409) {
                // Validation and an unavailable schema fail before the cart rule is
                // posted, so the voucher may be requested again on a retry.
                $this->conn->update('refund', ['voucher_requested_at' => null, 'error_message' => mb_substr($e->getMessage(), 0, 1000)], ['id' => $refundId]);

                throw RefundException::psUnavailable($e->getMessage(), $e);
            }
            $this->conn->update('refund', ['error_message' => mb_substr($e->getMessage(), 0, 1000)], ['id' => $refundId]);

            throw RefundException::conflict($e->getMessage());
        } catch (\Throwable $e) {
            $this->conn->update('refund', ['error_message' => mb_substr('Voucher outcome unknown: ' . $e->getMessage(), 0, 1000)], ['id' => $refundId]);

            throw RefundException::conflict('Voucher outcome unknown: ' . $e->getMessage());
        }

        // Stored before completing: a failed completion retries without a new voucher.
        $this->conn->update('refund', [
            'voucher_code' => $coupon['code'],
            'voucher_cart_rule_id' => $coupon['id'],
            'error_message' => null,
        ], ['id' => $refundId]);
    }

    /**
     * @param array<int, mixed> $requested
     *
     * @return array<int, array{product_id: int, id_product_attribute: int, quantity: int, amount: float, restock: bool}>
     */
    private function planNoTicketLines(array $requested): array
    {
        if ($requested === []) {
            throw RefundException::validationFailed('Select at least one product');
        }

        $resolver = $this->priceResolver ?? new CatalogPriceResolver($this->conn);
        $planned = [];
        $seen = [];
        foreach (array_values($requested) as $position => $line) {
            $line = \is_array($line) ? $line : [];
            $productId = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT);
            $attributeId = filter_var($line['id_product_attribute'] ?? 0, FILTER_VALIDATE_INT);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId <= 0 || $attributeId === false || $attributeId < 0) {
                throw RefundException::validationFailed(sprintf('Line %d has no valid product', $position));
            }
            if ($quantity === false || $quantity < 1) {
                throw RefundException::validationFailed(sprintf('Quantity for line %d must be at least 1', $position));
            }
            $key = $productId . ':' . $attributeId;
            if (isset($seen[$key])) {
                throw RefundException::validationFailed(sprintf('Product %d is listed twice', $productId));
            }
            $seen[$key] = true;

            if ($attributeId > 0 && $this->conn->fetchOne('SELECT 1 FROM catalog_combination WHERE id = ? AND id_product = ?', [$attributeId, $productId]) === false) {
                throw RefundException::validationFailed(sprintf('Combination %d does not belong to product %d', $attributeId, $productId));
            }
            try {
                $unitPrice = $resolver->unitPrice($productId, $attributeId);
            } catch (ProductNotInCatalogException $e) {
                throw RefundException::validationFailed($e->getMessage());
            }
            if ($unitPrice === null || $unitPrice <= 0) {
                throw RefundException::validationFailed(sprintf('Product %d has no price in the catalog', $productId));
            }

            $planned[] = [
                'product_id' => $productId,
                'id_product_attribute' => $attributeId,
                'quantity' => $quantity,
                'amount' => round($unitPrice * $quantity, 2),
                'restock' => ($line['restock'] ?? true) !== false,
            ];
        }

        return $planned;
    }

    /** @return string the trimmed reason text */
    private function assertReason(string $reasonCode, string $reasonText): string
    {
        $reasonText = trim($reasonText);
        if (!\in_array($reasonCode, self::REASONS, true)) {
            throw RefundException::validationFailed('Choose a reason from the list');
        }
        if ($reasonCode === 'other' && $reasonText === '') {
            throw RefundException::validationFailed('Describe the reason when choosing "other"');
        }

        return $reasonText;
    }

    private function assertCardConfirmed(float $cardAmount, bool $cardConfirmed): void
    {
        if ($cardAmount > 0 && !$cardConfirmed) {
            throw RefundException::validationFailed(sprintf('Confirm the %.2f card refund on the payment terminal first', $cardAmount));
        }
    }

    /**
     * @param array{order_slip_id: int, refunded_amount: float, refunded_lines: array<int, array<string, mixed>>, restock_failed?: array<int, mixed>} $psResponse
     *
     * @return array{refund_id: int, credit_note_number: ?string, order_slip_id: int, refunded_amount: float, cash_amount: float, card_amount: float, voucher_code: ?string, lines: array<int, array<string, mixed>>, restock_failed: array<int, mixed>, saga_error: bool, already_completed: bool}
     */
    private function complete(int $refundId, array $psResponse, int $sessionId): array
    {
        $refund = $this->conn->fetchAssociative('SELECT * FROM refund WHERE id = ?', [$refundId]);
        $slipId = (int) $psResponse['order_slip_id'];
        $cashAmount = round((float) $refund['cash_amount'], 2);
        $total = round((float) $refund['total'], 2);
        // Null for a refund without a ticket: no order to update or reference.
        $psOrderId = $refund['ps_order_id'] !== null ? (int) $refund['ps_order_id'] : null;

        $restocked = [];
        foreach ($psResponse['refunded_lines'] as $line) {
            $restocked[(int) ($line['id_product'] ?? 0) . ':' . (int) ($line['id_product_attribute'] ?? 0)] = !empty($line['restocked']);
        }

        $lines = $this->conn->fetchAllAssociative('SELECT * FROM refund_line WHERE refund_id = ? ORDER BY id', [$refundId]);
        $sagaError = false;
        $alreadyCompleted = false;

        $numberer = $this->creditNoteNumberer ?? new CreditNoteNumberer($this->conn);
        $numberer->prepareYear(new \DateTimeImmutable());

        try {
            $this->conn->transactional(function () use ($numberer, $sessionId, $refundId, $slipId, $cashAmount, $total, $psOrderId, $lines, $restocked, &$alreadyCompleted): void {
                // Only the request that moves the row out of pending applies the local
                // effects; a concurrent retry that got the same slip replayed does nothing.
                $claimed = $this->conn->update('refund', [
                    'status' => 'done',
                    'session_id' => $sessionId,
                    'ps_order_slip_id' => $slipId > 0 ? $slipId : null,
                    'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ], ['id' => $refundId, 'status' => 'pending']);
                if ($claimed === 0) {
                    $alreadyCompleted = true;

                    return;
                }

                // Numbered only once the refund really happened, in the same transaction.
                $this->conn->update('refund', [
                    'credit_note_number' => $numberer->next(new \DateTimeImmutable()),
                ], ['id' => $refundId]);

                if ($cashAmount > 0) {
                    // Positive: the cash report subtracts the refund total from the drawer.
                    $this->conn->insert('movement', [
                        'session_id' => $sessionId,
                        'type' => 'refund',
                        'amount' => $cashAmount,
                        'concept' => $psOrderId !== null ? sprintf('Devolución pedido #%d', $psOrderId) : 'Devolución sin ticket',
                        'ps_order_id' => $psOrderId,
                        'ps_order_slip_id' => $slipId > 0 ? $slipId : null,
                        'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                }

                foreach ($lines as $line) {
                    $key = (int) $line['product_id'] . ':' . (int) $line['id_product_attribute'];
                    $wasRestocked = $restocked[$key] ?? false;
                    $this->conn->update('refund_line', ['restocked' => $wasRestocked ? 1 : 0], ['id' => (int) $line['id']]);
                    if ($wasRestocked) {
                        // Mirror the shop's restock locally until the next catalog sync.
                        $this->conn->executeStatement(
                            'UPDATE catalog_stock SET quantity = quantity + ? WHERE id_product = ? AND id_product_attribute = ?',
                            [(int) $line['quantity'], (int) $line['product_id'], (int) $line['id_product_attribute']]
                        );
                    }
                }

                if ($psOrderId !== null) {
                    $this->conn->executeStatement(
                        'UPDATE order_meta SET refunded_amount = refunded_amount + ? WHERE ps_order_id = ?',
                        [$total, $psOrderId]
                    );
                }
            });
        } catch (\Throwable $e) {
            // The shop already holds the credit slip: record it for reconciliation.
            $this->logRefundOrphan($psOrderId ?? 0, $slipId, $refundId, $e);
            $sagaError = true;
        }

        return [
            'refund_id' => $refundId,
            'credit_note_number' => $this->conn->fetchOne('SELECT credit_note_number FROM refund WHERE id = ?', [$refundId]) ?: null,
            'order_slip_id' => $slipId,
            'refunded_amount' => $total,
            'cash_amount' => $cashAmount,
            'card_amount' => round((float) $refund['card_amount'], 2),
            'voucher_code' => isset($refund['voucher_code']) && $refund['voucher_code'] !== '' ? (string) $refund['voucher_code'] : null,
            'lines' => array_map(static fn (array $l): array => [
                'line_index' => $l['line_index'] !== null ? (int) $l['line_index'] : null,
                'product_id' => (int) $l['product_id'],
                'id_product_attribute' => (int) $l['id_product_attribute'],
                'quantity' => (int) $l['quantity'],
                'amount' => round((float) $l['amount'], 2),
            ], $lines),
            'restock_failed' => $alreadyCompleted ? [] : ($psResponse['restock_failed'] ?? []),
            'saga_error' => $sagaError,
            'already_completed' => $alreadyCompleted,
        ];
    }

    private const DEFAULT_REFUND_WINDOW_DAYS = 30;

    /**
     * Refunds are blocked once the terminal's window (30 days by default) has passed
     * since the sale.
     *
     * @param array<string, mixed> $order
     */
    private function assertWithinRefundWindow(array $order, int $terminalId): void
    {
        $days = $terminalId > 0
            ? $this->conn->fetchOne('SELECT refund_window_days FROM terminal WHERE id = ?', [$terminalId])
            : false;
        $days = $days !== false && $days !== null && (int) $days > 0 ? (int) $days : self::DEFAULT_REFUND_WINDOW_DAYS;

        $soldAt = new \DateTimeImmutable((string) $order['created_at']);
        if ($soldAt->modify(sprintf('+%d days', $days)) < new \DateTimeImmutable()) {
            throw RefundException::validationFailed(sprintf(
                'This sale is outside the %d-day refund window (sold on %s)',
                $days,
                $soldAt->format('d/m/Y')
            ));
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>>|null $requested
     *
     * @return array<int, array{line_index: int, product_id: int, id_product_attribute: int, quantity: int, amount: float, restock: bool}>
     */
    private function planLines(array $order, ?array $requested): array
    {
        $orderLines = $order['lines'];

        if ($requested === null) {
            $requested = [];
            foreach ($orderLines as $index => $line) {
                if ((int) ($line['refundable_qty'] ?? 0) > 0) {
                    $requested[] = ['line_index' => $index, 'quantity' => (int) $line['refundable_qty'], 'restock' => true];
                }
            }
            if ($requested === []) {
                throw RefundException::doubleRefundAttempt((int) $order['ps_order_id']);
            }
        }

        if ($requested === []) {
            throw RefundException::validationFailed('Select at least one line');
        }

        $planned = [];
        $seen = [];
        foreach ($requested as $position => $line) {
            $index = filter_var($line['line_index'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($index === false || !isset($orderLines[$index])) {
                throw RefundException::validationFailed(sprintf('Line %d does not belong to this sale', $position));
            }
            if (isset($seen[$index])) {
                throw RefundException::validationFailed(sprintf('Line %d is listed twice', $index));
            }
            $seen[$index] = true;
            if ($quantity === false || $quantity < 1) {
                throw RefundException::validationFailed(sprintf('Quantity for line %d must be at least 1', $index));
            }

            $orderLine = $orderLines[$index];
            $productId = (int) ($orderLine['product_id'] ?? 0);
            if ($productId <= 0 || !isset($orderLine['paid_total'])) {
                throw RefundException::validationFailed('This sale was recorded before refunds were supported and cannot be refunded from the TPV');
            }

            $soldQty = (int) $orderLine['qty'];
            $refundedQty = (int) $orderLine['refunded_qty'];
            if ($quantity > $soldQty - $refundedQty) {
                throw RefundException::validationFailed(sprintf('Only %d unit(s) of line %d are left to refund', max(0, $soldQty - $refundedQty), $index));
            }

            $paidTotal = (float) $orderLine['paid_total'];
            // The last units take whatever is left, so rounding never leaves cents behind.
            $amount = $refundedQty + $quantity === $soldQty
                ? round($paidTotal - (float) $orderLine['refunded_line_amount'], 2)
                : round($paidTotal * $quantity / $soldQty, 2);

            $planned[] = [
                'line_index' => $index,
                'product_id' => $productId,
                'id_product_attribute' => (int) ($orderLine['id_product_attribute'] ?? 0),
                'quantity' => $quantity,
                'amount' => $amount,
                'restock' => ($line['restock'] ?? true) !== false,
            ];
        }

        return $planned;
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array{0: float, 1: float} cash and card amounts
     */
    private function splitByTender(array $order, float $total): array
    {
        $tender = $order['tender'] ?? null;
        $cash = \is_array($tender) ? (float) ($tender['cash'] ?? 0) : 0.0;
        $card = \is_array($tender) ? (float) ($tender['card'] ?? 0) : 0.0;

        if ($cash + $card <= 0) {
            $cash = ($order['payment_method'] ?? '') === 'card' ? 0.0 : 1.0;
            $card = 1.0 - $cash;
        }

        $cashAmount = round($total * $cash / ($cash + $card), 2);

        return [$cashAmount, round($total - $cashAmount, 2)];
    }

    private function logRefundOrphan(int $psOrderId, int $orderSlipId, int $refundId, \Throwable $error): void
    {
        try {
            $this->conn->insert('audit_log', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'terminal_id' => 1,
                'action' => 'refund_orphan',
                'target' => (string) $psOrderId,
                'payload' => json_encode([
                    'refund_id' => $refundId,
                    'order_slip_id' => $orderSlipId,
                    'error' => $error->getMessage(),
                ], JSON_THROW_ON_ERROR),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best-effort logging; do not throw
        }
    }
}
