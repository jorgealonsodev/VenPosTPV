<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Rejected refund request, carrying the HTTP status and per-field details the
 * front controller returns to the TPV.
 */
class RefundRequestException extends RuntimeException
{
    /** @param array<string, string> $details */
    public function __construct(private readonly int $httpCode, string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /** @return array<string, string> */
    public function getDetails(): array
    {
        return $this->details;
    }
}

/**
 * The PrestaShop operations a refund needs, so the rules can be tested without
 * a shop. PsRefundGateway is the production implementation.
 */
interface RefundGateway
{
    /**
     * @return array{id: int, id_shop: int, details: array<int, array{id_order_detail: int, product_id: int, product_attribute_id: int, product_quantity: int, slipped_quantity: int, unit_price_tax_excl: float, unit_price_tax_incl: float}>}|null
     */
    public function loadOrder(int $orderId): ?array;

    /**
     * Creates one credit slip refunding exactly $amount (tax incl.).
     *
     * @param array<int, array{id_order_detail: int, quantity: int, unit_price: float}> $productList
     *
     * @return int the new order slip id
     */
    public function createSlip(int $orderId, array $productList, float $amount): int;

    /** Adds $quantity to the live stock of one exact combination. */
    public function restock(int $productId, int $attributeId, int $quantity, int $shopId): void;

    public function setOrderState(int $orderId, int $stateId): void;

    /** @return array<string, mixed>|null */
    public function findProcessedRequest(string $requestId): ?array;

    /** @param array<string, mixed> $result */
    public function rememberProcessedRequest(string $requestId, array $result): void;

    public function acquireLock(string $name): bool;

    public function releaseLock(string $name): void;
}

/**
 * Refunds lines of a PrestaShop order for the TPV.
 *
 * The TPV sends the amount the customer actually paid per line (it knows
 * discounts the shop never saw); the shop caps it at the line price, caps the
 * quantity at what is still refundable, credits it through one OrderSlip and,
 * per line, adds the goods back to the live stock of the exact combination.
 * A request_id makes a retried request return the first result instead of
 * refunding twice.
 */
class RefundProcessor
{
    private const AMOUNT_TOLERANCE = 0.01;

    public function __construct(
        private readonly RefundGateway $gateway,
        private readonly int $refundStateId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws RefundRequestException
     */
    public function process(array $data): array
    {
        $lines = $this->validate($data);
        $orderId = (int) $data['ps_order_id'];
        $requestId = (string) $data['request_id'];

        $lock = 'tpvconnect_refund_' . $orderId;
        if (!$this->gateway->acquireLock($lock)) {
            throw new RefundRequestException(409, 'Another refund for this order is in progress');
        }

        try {
            $previous = $this->gateway->findProcessedRequest($requestId);
            if ($previous !== null) {
                return ['replayed' => true] + $previous;
            }

            $order = $this->gateway->loadOrder($orderId);
            if ($order === null) {
                throw new RefundRequestException(404, 'Order not found');
            }

            $matched = $this->matchLines($order, $lines);

            $productList = [];
            $amount = 0.0;
            foreach ($matched as $line) {
                $productList[] = [
                    'id_order_detail' => $line['detail']['id_order_detail'],
                    'quantity' => $line['quantity'],
                    'unit_price' => (float) $line['detail']['unit_price_tax_excl'],
                ];
                $amount += $line['amount'];
            }
            $amount = round($amount, 2);

            $slipId = $this->gateway->createSlip($orderId, $productList, $amount);

            // Record the slip before anything else can fail: a retry must find it
            // and never create a second one. Restock is unknown until confirmed.
            $this->gateway->rememberProcessedRequest($requestId, [
                'order_slip_id' => $slipId,
                'refunded_amount' => $amount,
                'refunded_lines' => array_map(static fn (array $line): array => [
                    'id_order_detail' => $line['detail']['id_order_detail'],
                    'id_product' => $line['id_product'],
                    'id_product_attribute' => $line['id_product_attribute'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'restocked' => false,
                ], $matched),
                'restock_failed' => array_values(array_map(static fn (array $line): array => [
                    'id_product' => $line['id_product'],
                    'id_product_attribute' => $line['id_product_attribute'],
                    'quantity' => $line['quantity'],
                    'error' => 'Interrupted before restock was confirmed',
                ], array_filter($matched, static fn (array $line): bool => $line['restock']))),
            ]);

            $refundedLines = [];
            $restockFailed = [];
            foreach ($matched as $line) {
                $restocked = false;
                if ($line['restock']) {
                    try {
                        $this->gateway->restock($line['id_product'], $line['id_product_attribute'], $line['quantity'], (int) $order['id_shop']);
                        $restocked = true;
                    } catch (Throwable $e) {
                        // The money side is already committed; report so the TPV can flag it.
                        $restockFailed[] = [
                            'id_product' => $line['id_product'],
                            'id_product_attribute' => $line['id_product_attribute'],
                            'quantity' => $line['quantity'],
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                $refundedLines[] = [
                    'id_order_detail' => $line['detail']['id_order_detail'],
                    'id_product' => $line['id_product'],
                    'id_product_attribute' => $line['id_product_attribute'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'restocked' => $restocked,
                ];
            }

            if ($this->refundStateId > 0) {
                $this->gateway->setOrderState($orderId, $this->refundStateId);
            }

            $result = [
                'order_slip_id' => $slipId,
                'refunded_amount' => $amount,
                'refunded_lines' => $refundedLines,
                'restock_failed' => $restockFailed,
            ];
            $this->gateway->rememberProcessedRequest($requestId, $result);

            return ['replayed' => false] + $result;
        } finally {
            $this->gateway->releaseLock($lock);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array{id_product: int, id_product_attribute: int, quantity: int, amount: float, restock: bool}>
     */
    private function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['ps_order_id']) || !is_int($data['ps_order_id']) || $data['ps_order_id'] <= 0) {
            $errors['ps_order_id'] = 'Required positive integer';
        }

        $requestId = $data['request_id'] ?? null;
        if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $requestId)) {
            $errors['request_id'] = 'Required, 8-64 characters [A-Za-z0-9_-]';
        }

        $lines = [];
        if (empty($data['lines']) || !is_array($data['lines'])) {
            $errors['lines'] = 'Required non-empty array';
        } else {
            foreach (array_values($data['lines']) as $index => $line) {
                if (!is_array($line)) {
                    $errors['lines.' . $index] = 'Must be an object';
                    continue;
                }
                $productId = $line['id_product'] ?? null;
                if (!is_int($productId) || $productId <= 0) {
                    $errors['lines.' . $index . '.id_product'] = 'Required positive integer';
                }
                $attributeId = $line['id_product_attribute'] ?? 0;
                if (!is_int($attributeId) || $attributeId < 0) {
                    $errors['lines.' . $index . '.id_product_attribute'] = 'Must be a non-negative integer';
                }
                $quantity = $line['quantity'] ?? null;
                if (!is_int($quantity) || $quantity < 1) {
                    $errors['lines.' . $index . '.quantity'] = 'Must be an integer >= 1';
                }
                $amount = $line['amount'] ?? null;
                if (!is_int($amount) && !is_float($amount) || !is_finite((float) $amount) || (float) $amount <= 0) {
                    $errors['lines.' . $index . '.amount'] = 'Must be a number > 0';
                }

                $lines[] = [
                    'id_product' => (int) $productId,
                    'id_product_attribute' => (int) $attributeId,
                    'quantity' => (int) $quantity,
                    'amount' => round((float) $amount, 2),
                    'restock' => ($line['restock'] ?? true) !== false,
                ];
            }
        }

        if ($errors !== []) {
            throw new RefundRequestException(422, 'Validation failed', $errors);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array{id_product: int, id_product_attribute: int, quantity: int, amount: float, restock: bool}> $lines
     *
     * @return array<int, array{id_product: int, id_product_attribute: int, quantity: int, amount: float, restock: bool, detail: array<string, mixed>}>
     */
    private function matchLines(array $order, array $lines): array
    {
        $errors = [];
        $requested = [];
        $matched = [];

        foreach ($lines as $index => $line) {
            $detail = null;
            foreach ($order['details'] as $candidate) {
                if ((int) $candidate['product_id'] === $line['id_product']
                    && (int) $candidate['product_attribute_id'] === $line['id_product_attribute']) {
                    $detail = $candidate;
                    break;
                }
            }

            if ($detail === null) {
                $errors['lines.' . $index . '.id_product'] = 'Product is not in this order';
                continue;
            }

            $detailId = (int) $detail['id_order_detail'];
            $requested[$detailId] = ($requested[$detailId] ?? 0) + $line['quantity'];
            $refundable = (int) $detail['product_quantity'] - (int) $detail['slipped_quantity'];
            if ($requested[$detailId] > $refundable) {
                $errors['lines.' . $index . '.quantity'] = sprintf('Only %d unit(s) left to refund', max(0, $refundable));
            }

            $maxAmount = round((float) $detail['unit_price_tax_incl'] * $line['quantity'], 2);
            if ($line['amount'] > $maxAmount + self::AMOUNT_TOLERANCE) {
                $errors['lines.' . $index . '.amount'] = sprintf('Exceeds the line price (%.2f)', $maxAmount);
            }

            $matched[] = $line + ['detail' => $detail];
        }

        if ($errors !== []) {
            throw new RefundRequestException(422, 'Validation failed', $errors);
        }

        return $matched;
    }
}

/**
 * Production RefundGateway on top of PrestaShop core classes.
 */
class PsRefundGateway implements RefundGateway
{
    private const LOCK_WAIT_SECONDS = 5;
    private const REQUEST_KEY_PREFIX = 'TPVCONNECT_RFQ_';

    public function loadOrder(int $orderId): ?array
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }

        $details = [];
        foreach (OrderDetail::getList($orderId) as $row) {
            $resume = OrderSlip::getProductSlipResume((int) $row['id_order_detail']);
            $details[] = [
                'id_order_detail' => (int) $row['id_order_detail'],
                'product_id' => (int) $row['product_id'],
                'product_attribute_id' => (int) $row['product_attribute_id'],
                'product_quantity' => (int) $row['product_quantity'],
                'slipped_quantity' => (int) ($resume['product_quantity'] ?? 0),
                'unit_price_tax_excl' => (float) $row['unit_price_tax_excl'],
                'unit_price_tax_incl' => (float) $row['unit_price_tax_incl'],
            ];
        }

        return ['id' => $orderId, 'id_shop' => (int) $order->id_shop, 'details' => $details];
    }

    public function createSlip(int $orderId, array $productList, float $amount): int
    {
        $order = new Order($orderId);
        // amount_choosen=true: the slip refunds exactly $amount; OrderSlip::create
        // returns a bool, so the new id is read back under the per-order lock.
        if (!OrderSlip::create($order, $productList, false, $amount, true, true)) {
            throw new RuntimeException('OrderSlip::create failed');
        }

        $slipId = (int) Db::getInstance()->getValue(
            'SELECT MAX(id_order_slip) FROM `' . _DB_PREFIX_ . 'order_slip` WHERE id_order = ' . (int) $orderId,
            false
        );
        if ($slipId <= 0) {
            throw new RuntimeException('Order slip was not found after creation');
        }

        return $slipId;
    }

    public function restock(int $productId, int $attributeId, int $quantity, int $shopId): void
    {
        // A delta, never an absolute quantity: sales made meanwhile are kept.
        if (!StockAvailable::updateQuantity($productId, $attributeId, $quantity, $shopId)) {
            throw new RuntimeException(sprintf('Stock update failed for product %d/%d', $productId, $attributeId));
        }
    }

    public function setOrderState(int $orderId, int $stateId): void
    {
        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState($stateId, $orderId);
        $history->addWithemail();
    }

    public function findProcessedRequest(string $requestId): ?array
    {
        // Read the table directly, uncached: Configuration::get() serves a per-request
        // cache that cannot see a result written by a concurrent request.
        $stored = Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL(self::REQUEST_KEY_PREFIX . md5($requestId)) . "'",
            false
        );
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function rememberProcessedRequest(string $requestId, array $result): void
    {
        if (!Configuration::updateGlobalValue(self::REQUEST_KEY_PREFIX . md5($requestId), json_encode($result))) {
            throw new RuntimeException('Could not record the refund request result');
        }
    }

    public function acquireLock(string $name): bool
    {
        $rows = Db::getInstance()->executeS("SELECT GET_LOCK('" . pSQL($name) . "', " . self::LOCK_WAIT_SECONDS . ') AS locked', true, false);

        return is_array($rows) && (string) ($rows[0]['locked'] ?? '0') === '1';
    }

    public function releaseLock(string $name): void
    {
        Db::getInstance()->executeS("SELECT RELEASE_LOCK('" . pSQL($name) . "') AS released", true, false);
    }
}
