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
 * Rejected referenced-order request, carrying the HTTP status and per-field
 * details the front controller returns to the TPV.
 */
class ReferencedOrderException extends RuntimeException
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
 * Request-scoped holder of the reference the TPV asked for. Only set while this
 * module creates its own order, so actionGenerateDocumentReference never touches
 * web orders.
 */
final class TpvOrderReference
{
    private static ?string $pending = null;

    public static function hold(string $reference): void
    {
        self::$pending = $reference;
    }

    public static function release(): void
    {
        self::$pending = null;
    }

    /**
     * Answer for actionGenerateDocumentReference: the held reference, once, and
     * only for orders. Empty string means "let PrestaShop generate its own".
     * Giving it only once keeps PaymentModule::validateOrder, which asks again
     * while the reference already exists, from looping forever.
     */
    public static function claim(string $type): string
    {
        if ($type !== 'order' || self::$pending === null) {
            return '';
        }
        $reference = self::$pending;
        self::$pending = null;

        return $reference;
    }
}

/**
 * The PrestaShop operations idempotent creation needs, so the rules can be
 * tested without a shop. PsReferencedOrderGateway is the production one.
 */
interface ReferencedOrderGateway
{
    /** @return array{order_id: int, reference: string, total_paid: float}|null */
    public function findOrderByReference(string $reference): ?array;

    public function acquireLock(string $name): bool;

    public function releaseLock(string $name): void;
}

/**
 * Creates TPV orders with the TPV's own ticket code as PrestaShop reference.
 *
 * The reference (`A26000123`: terminal letter, 2-digit year, 6 digits) is also
 * the idempotency key: under a per-reference lock, an existing order with that
 * reference is returned (replayed) instead of creating another one. Requests
 * without a reference keep the previous behavior untouched.
 */
class ReferencedOrderCreator
{
    public const PATTERN = '/^[A-Z]\d{8}\z/';
    private const FORMAT_ERROR = 'Must match ^[A-Z]\d{8}$ (e.g. A26000123)';

    public function __construct(private readonly ReferencedOrderGateway $gateway)
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string> field errors, empty when valid
     */
    public static function validateRequest(array $data): array
    {
        if (!array_key_exists('reference', $data)) {
            return [];
        }
        $reference = $data['reference'];

        return is_string($reference) && preg_match(self::PATTERN, $reference) === 1
            ? []
            : ['reference' => self::FORMAT_ERROR];
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(): array<string, mixed> $createOrder creates the order (validateOrder) and returns its response
     *
     * @return array<string, mixed>
     *
     * @throws ReferencedOrderException
     */
    public function create(array $data, callable $createOrder): array
    {
        if (!array_key_exists('reference', $data)) {
            return $createOrder();
        }
        $errors = self::validateRequest($data);
        if ($errors !== []) {
            throw new ReferencedOrderException(422, 'Validation failed', $errors);
        }

        $reference = (string) $data['reference'];
        $lock = 'tpvconnect_order_ref_' . $reference;
        if (!$this->gateway->acquireLock($lock)) {
            throw new ReferencedOrderException(409, 'Another order with this reference is in progress');
        }

        try {
            $existing = $this->gateway->findOrderByReference($reference);
            if ($existing !== null) {
                return $existing + ['replayed' => true];
            }

            TpvOrderReference::hold($reference);
            try {
                return $createOrder() + ['replayed' => false];
            } finally {
                TpvOrderReference::release();
            }
        } finally {
            $this->gateway->releaseLock($lock);
        }
    }
}

/**
 * Production ReferencedOrderGateway on top of PrestaShop's database.
 */
class PsReferencedOrderGateway implements ReferencedOrderGateway
{
    // Longer than a refund: the first request may still be inside validateOrder.
    private const LOCK_WAIT_SECONDS = 10;

    public function findOrderByReference(string $reference): ?array
    {
        // Uncached: a concurrent request may have created it a moment ago.
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order`, `reference`, `total_paid` FROM `' . _DB_PREFIX_ . "orders` WHERE `reference` = '" . pSQL($reference) . "' ORDER BY `id_order` ASC LIMIT 1",
            true,
            false
        );
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        return [
            'order_id' => (int) $rows[0]['id_order'],
            'reference' => (string) $rows[0]['reference'],
            'total_paid' => round((float) $rows[0]['total_paid'], 2),
        ];
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
