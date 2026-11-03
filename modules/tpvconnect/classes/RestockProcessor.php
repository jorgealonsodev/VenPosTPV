<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/RefundProcessor.php';

/**
 * The PrestaShop operations a restock without an order needs, so the rules can
 * be tested without a shop. PsRestockGateway is the production implementation.
 */
interface RestockGateway
{
    /** True when the product exists and, for $attributeId > 0, the combination belongs to it. */
    public function productExists(int $productId, int $attributeId): bool;

    public function shopId(): int;

    /** Adds $quantity to the live stock of one exact combination. */
    public function restock(int $productId, int $attributeId, int $quantity, int $shopId): void;

    /** @return array<string, mixed>|null */
    public function findProcessedRequest(string $requestId): ?array;

    /** @param array<string, mixed> $result */
    public function rememberProcessedRequest(string $requestId, array $result): void;

    public function acquireLock(string $name): bool;

    public function releaseLock(string $name): void;
}

/**
 * Puts goods returned at the TPV without a ticket back on sale.
 *
 * There is no order to credit, so the only effect is a delta on the live stock
 * of each exact combination. A request_id makes a retried request return the
 * first result instead of restocking twice; the result is recorded as
 * "unconfirmed" before the stock moves, so an interrupted request is reported
 * as a failure on replay rather than restocked again.
 */
class RestockProcessor
{
    public function __construct(private readonly RestockGateway $gateway)
    {
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
        $requestId = (string) $data['request_id'];

        $lock = 'tpvconnect_restock_' . md5($requestId);
        if (!$this->gateway->acquireLock($lock)) {
            throw new RefundRequestException(409, 'This restock request is already in progress');
        }

        try {
            $previous = $this->gateway->findProcessedRequest($requestId);
            if ($previous !== null) {
                return ['replayed' => true] + $previous;
            }

            $errors = [];
            foreach ($lines as $index => $line) {
                if (!$this->gateway->productExists($line['id_product'], $line['id_product_attribute'])) {
                    $errors['lines.' . $index . '.id_product'] = 'Product or combination not found';
                }
            }
            if ($errors !== []) {
                throw new RefundRequestException(404, 'Product not found', $errors);
            }

            $this->gateway->rememberProcessedRequest($requestId, [
                'restocked_lines' => array_map(static fn (array $line): array => $line + ['restocked' => false], $lines),
                'restock_failed' => array_map(static fn (array $line): array => $line + ['error' => 'Interrupted before restock was confirmed'], $lines),
            ]);

            $shopId = $this->gateway->shopId();
            $restockedLines = [];
            $restockFailed = [];
            foreach ($lines as $line) {
                $restocked = false;
                try {
                    $this->gateway->restock($line['id_product'], $line['id_product_attribute'], $line['quantity'], $shopId);
                    $restocked = true;
                } catch (Throwable $e) {
                    $restockFailed[] = $line + ['error' => $e->getMessage()];
                }
                $restockedLines[] = $line + ['restocked' => $restocked];
            }

            $result = ['restocked_lines' => $restockedLines, 'restock_failed' => $restockFailed];
            $this->gateway->rememberProcessedRequest($requestId, $result);

            return ['replayed' => false] + $result;
        } finally {
            $this->gateway->releaseLock($lock);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array{id_product: int, id_product_attribute: int, quantity: int}>
     */
    private function validate(array $data): array
    {
        $errors = [];

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

                $lines[] = [
                    'id_product' => (int) $productId,
                    'id_product_attribute' => (int) $attributeId,
                    'quantity' => (int) $quantity,
                ];
            }
        }

        if ($errors !== []) {
            throw new RefundRequestException(422, 'Validation failed', $errors);
        }

        return $lines;
    }
}

/**
 * Production RestockGateway: the refund gateway's stock delta, request store and
 * locks, with its own request key prefix.
 */
class PsRestockGateway extends PsRefundGateway implements RestockGateway
{
    public function __construct()
    {
        parent::__construct('TPVCONNECT_RSQ_');
    }

    public function productExists(int $productId, int $attributeId): bool
    {
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }
        if ($attributeId === 0) {
            return true;
        }

        $combination = new Combination($attributeId);

        return Validate::isLoadedObject($combination) && (int) $combination->id_product === $productId;
    }

    public function shopId(): int
    {
        return (int) Context::getContext()->shop->id;
    }
}
