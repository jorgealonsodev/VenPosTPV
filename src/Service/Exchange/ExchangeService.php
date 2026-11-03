<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Exchange;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Checkout\CheckoutService;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Refund\Exception\RefundException;
use VenPosTPV\Service\Refund\RefundService;

/**
 * Product exchange with a balance, in one operation (R13): the returned lines are a
 * normal refund of the TPV sale (credit note, PrestaShop slip, restock) whose money
 * becomes credit for the new items, which are a normal sale (new PrestaShop order and
 * ticket). Balance = new total - credit: a positive balance is collected like any
 * checkout, a negative one is refunded by the original sale's tender split, and the
 * credit itself never moves cash.
 *
 * Order and recovery. Everything that can be checked locally (reason, window,
 * quantities, catalog prices, stock, payment) is checked before PrestaShop is
 * called. The refund goes first, because the credit must exist before goods leave
 * on it. The exchange row is written in the same transaction as the pending refund,
 * so whatever happens next it is resumable with retry():
 *  - pending:     the refund's outcome is unknown (PrestaShop down); retry() resends
 *                 it with the same request_id, then creates the sale;
 *  - credit_open: the refund is done (and any difference paid out once) but the new
 *                 sale failed; retry() only creates the sale;
 *  - done / failed: terminal.
 * A retry holds a named lock on the exchange, so two terminals cannot create the
 * sale twice, and never sends or pays the returned part again.
 *
 * Online orders (R14 second half, task T4) are not TPV sales: their returned part
 * follows R10 (no money back at the till) and plugs in where startReturn() calls
 * RefundService.
 */
class ExchangeService
{
    private const PAYMENT_METHODS = ['cash', 'card', 'mixed'];

    /** Payment method stored and sent to PrestaShop when the credit pays everything. */
    public const NO_PAYMENT_METHOD = 'exchange';

    public function __construct(
        private readonly RefundService $refundService,
        private readonly CheckoutService $checkoutService,
        private readonly Connection $conn,
    ) {
    }

    /**
     * @param array<int, mixed> $returnLines [{line_index, quantity, restock}] of the original sale
     * @param array<string, mixed> $newCartData same shape as /api/checkout cart_data
     *
     * @return array<string, mixed> see result()
     *
     * @throws RefundException when nothing was done (or the returned part's outcome is unknown)
     */
    public function processExchange(
        int $userId,
        int $psOrderId,
        array $returnLines,
        string $reasonCode,
        string $reasonText,
        array $newCartData,
        string $paymentMethod,
        float $amountTendered,
        bool $cardConfirmed,
    ): array {
        try {
            $newTotal = $this->checkoutService->quoteCart($newCartData);
        } catch (CheckoutException $e) {
            throw RefundException::validationFailed('New items: ' . $e->getMessage());
        }

        // Set inside the refund's planning transaction, once the exchange row exists.
        $reservation = new \ArrayObject(['exchange_id' => 0]);
        try {
            $refund = $this->refundService->processExchangeReturn(
                $userId,
                $psOrderId,
                array_values($returnLines),
                $reasonCode,
                $reasonText,
                $cardConfirmed,
                $newTotal,
                function (array $reserved) use ($reservation, $psOrderId, $userId, $newTotal, $newCartData, $paymentMethod, $amountTendered): void {
                    $balance = round($newTotal - $reserved['credit'], 2);
                    [$method, $tendered] = $this->assertBalanceCovered($balance, $paymentMethod, $amountTendered, $newCartData);
                    $this->conn->insert('exchange', [
                        'status' => 'pending',
                        'ps_order_id' => $psOrderId,
                        'refund_id' => $reserved['refund_id'],
                        'user_id' => $userId,
                        'credit' => $reserved['credit'],
                        'credit_applied' => $reserved['credit_applied'],
                        'new_total' => $newTotal,
                        'balance' => $balance,
                        'payment_method' => $method,
                        'amount_tendered' => $tendered,
                        'cart_data' => json_encode($newCartData, JSON_THROW_ON_ERROR),
                        'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                    $reservation['exchange_id'] = (int) $this->conn->lastInsertId();
                },
            );
        } catch (RefundException $e) {
            $exchangeId = (int) $reservation['exchange_id'];
            if ($exchangeId > 0 && $this->refundService->refundStatus($this->exchange($exchangeId)['refund_id']) === 'failed') {
                // PrestaShop definitely rejected the return: nothing happened anywhere.
                $this->conn->update('exchange', ['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 1000)], ['id' => $exchangeId]);
            }

            throw $e;
        }

        return $this->afterRefund($userId, $this->exchange((int) $reservation['exchange_id']), $refund);
    }

    /**
     * Resumes an exchange left pending or with its credit open.
     *
     * @return array<string, mixed> see result()
     *
     * @throws RefundException
     */
    public function retry(int $userId, int $exchangeId): array
    {
        $lockName = 'venpostpv_exchange_' . $exchangeId;
        if ((int) $this->conn->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
            throw RefundException::conflict(sprintf('Exchange #%d is being completed on another terminal', $exchangeId));
        }

        try {
            $exchange = $this->conn->fetchAssociative('SELECT * FROM exchange WHERE id = ?', [$exchangeId]);
            if ($exchange === false || !\in_array($exchange['status'], ['pending', 'credit_open'], true)) {
                throw RefundException::conflict(sprintf('Exchange #%d is not waiting to be completed', $exchangeId));
            }
            $exchange = $this->exchange($exchangeId);

            $refund = null;
            $status = $this->refundService->refundStatus($exchange['refund_id']);
            if ($status === 'pending') {
                // Same request_id: PrestaShop replays the slip instead of refunding twice.
                $refund = $this->refundService->retryPending($userId, $exchange['refund_id']);
            } elseif ($status !== 'done') {
                $this->conn->update('exchange', ['status' => 'failed'], ['id' => $exchangeId]);

                throw RefundException::conflict(sprintf('The return of exchange #%d was rejected; start a new exchange', $exchangeId));
            }

            return $this->afterRefund($userId, $exchange, $refund);
        } finally {
            $this->conn->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * @param array{id: int, refund_id: int, credit: float, credit_applied: float, new_total: float, balance: float, payment_method: string, amount_tendered: float, cart_data: array<string, mixed>} $exchange
     * @param array<string, mixed>|null $refund the refund result, null when it completed in an earlier call
     *
     * @return array<string, mixed>
     */
    private function afterRefund(int $userId, array $exchange, ?array $refund): array
    {
        if ($refund !== null && !empty($refund['saga_error'])) {
            // The shop holds the slip but the local completion failed: the refund stays
            // pending and a retry replays it, so the credit is never spent before it exists.
            return $this->result($exchange, 'pending', $refund, null, 'The return was refunded in PrestaShop but not recorded locally; retry the exchange', false);
        }

        $this->conn->update('exchange', ['status' => 'credit_open', 'error_message' => null], ['id' => $exchange['id']]);

        try {
            $sale = $this->checkoutService->createOrder(
                $userId,
                $exchange['cart_data'],
                $exchange['payment_method'],
                $exchange['amount_tendered'],
                $exchange['credit_applied'],
            );
        } catch (\Throwable $e) {
            // The credit stays open on the exchange; the refund is done and paid once.
            $this->conn->update('exchange', ['error_message' => mb_substr($e->getMessage(), 0, 1000)], ['id' => $exchange['id']]);
            $unknown = !($e instanceof CheckoutException && $e->getCode() === 422);

            return $this->result($exchange, 'credit_open', $refund, null, $e->getMessage(), $unknown);
        }

        $this->conn->update('exchange', [
            'status' => 'done',
            'new_ps_order_id' => $sale['ps_order_id'],
            'new_reference' => mb_substr($sale['reference'], 0, 32),
            'error_message' => null,
            'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $exchange['id']]);

        return $this->result($exchange, 'done', $refund, $sale, null, false);
    }

    /**
     * Validates how a positive balance is paid, before anything is sent.
     *
     * @param array<string, mixed> $cartData
     *
     * @return array{0: string, 1: float} the stored payment method and tendered amount
     */
    private function assertBalanceCovered(float $balance, string $paymentMethod, float $amountTendered, array $cartData): array
    {
        if ($balance <= 0) {
            return [self::NO_PAYMENT_METHOD, 0.0];
        }
        if (!\in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            throw RefundException::validationFailed(sprintf('Choose cash, card or mixed to collect the %.2f balance', $balance));
        }
        if ($paymentMethod === 'cash' && round($amountTendered, 2) < $balance) {
            throw RefundException::validationFailed(sprintf('Cash tendered does not cover the %.2f balance', $balance));
        }
        if ($paymentMethod === 'mixed') {
            $cash = round((float) ($cartData['cash_amount'] ?? 0), 2);
            if ($cash <= 0 || $cash >= $balance) {
                throw RefundException::validationFailed(sprintf('The cash part of a mixed payment must be between 0 and %.2f', $balance));
            }
        }

        return [$paymentMethod, round(max(0.0, $amountTendered), 2)];
    }

    /**
     * @return array{id: int, refund_id: int, credit: float, credit_applied: float, new_total: float, balance: float, payment_method: string, amount_tendered: float, cart_data: array<string, mixed>}
     */
    private function exchange(int $exchangeId): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM exchange WHERE id = ?', [$exchangeId]);
        if ($row === false) {
            throw RefundException::conflict(sprintf('Exchange #%d does not exist', $exchangeId));
        }
        $cart = json_decode((string) $row['cart_data'], true);

        return [
            'id' => (int) $row['id'],
            'refund_id' => (int) $row['refund_id'],
            'credit' => round((float) $row['credit'], 2),
            'credit_applied' => round((float) $row['credit_applied'], 2),
            'new_total' => round((float) $row['new_total'], 2),
            'balance' => round((float) $row['balance'], 2),
            'payment_method' => (string) $row['payment_method'],
            'amount_tendered' => round((float) $row['amount_tendered'], 2),
            'cart_data' => \is_array($cart) ? $cart : [],
        ];
    }

    /**
     * @param array{id: int, refund_id: int, credit: float, new_total: float, balance: float} $exchange
     * @param array<string, mixed>|null $refund
     * @param array<string, mixed>|null $sale
     *
     * @return array{exchange_id: int, status: string, credit: float, new_total: float, balance: float, refund: array<string, mixed>, sale: ?array<string, mixed>, sale_error: ?string, sale_outcome_unknown: bool}
     */
    private function result(array $exchange, string $status, ?array $refund, ?array $sale, ?string $saleError, bool $saleOutcomeUnknown): array
    {
        return [
            'exchange_id' => $exchange['id'],
            'status' => $status,
            'credit' => $exchange['credit'],
            'new_total' => $exchange['new_total'],
            'balance' => $exchange['balance'],
            // Null-safe for a resumed exchange whose refund completed earlier.
            'refund' => $refund ?? ['refund_id' => $exchange['refund_id'], 'already_completed' => true, 'resumed' => true],
            'sale' => $sale,
            'sale_error' => $saleError,
            'sale_outcome_unknown' => $saleOutcomeUnknown,
        ];
    }
}
