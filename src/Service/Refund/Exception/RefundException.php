<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund\Exception;

use RuntimeException;
use Throwable;

final class RefundException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function doubleRefundAttempt(int $psOrderId): self
    {
        return new self(
            sprintf('Order %d already fully refunded', $psOrderId),
            409
        );
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function orderNotFound(int $psOrderId): self
    {
        return new self(
            sprintf('Order %d not found', $psOrderId),
            404
        );
    }

    public static function psUnavailable(string $message, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('PrestaShop refund service unavailable: %s', $message),
            0,
            $previous
        );
    }

    /** Code of voucherUnavailable(): PrestaShop could not be reached, so no voucher exists. */
    public const VOUCHER_UNAVAILABLE = 503;

    /**
     * Vouchers are PrestaShop cart rules (PRD D3): without a connection none can be
     * created, and the cashier gives the money back by another method.
     */
    public static function voucherUnavailable(?Throwable $previous = null): self
    {
        return new self(
            'Sin conexión con PrestaShop: el vale no se puede crear ahora. Elige efectivo o tarjeta.',
            self::VOUCHER_UNAVAILABLE,
            $previous
        );
    }

    public static function validationFailed(string $message): self
    {
        return new self(
            sprintf('Refund validation failed: %s', $message),
            422
        );
    }
}
