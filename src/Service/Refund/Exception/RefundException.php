<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund\Exception;

use RuntimeException;
use Throwable;

final class RefundException extends RuntimeException
{
    public static function doubleRefundAttempt(int $psOrderId): self
    {
        return new self(
            sprintf('Order %d already fully refunded', $psOrderId),
            409
        );
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

    public static function validationFailed(string $message): self
    {
        return new self(
            sprintf('Refund validation failed: %s', $message),
            422
        );
    }
}
