<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout\Exception;

use RuntimeException;
use Throwable;

final class CheckoutException extends RuntimeException
{
    public static function psUnavailable(string $message, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('PrestaShop order service unavailable: %s', $message),
            0,
            $previous
        );
    }

    public static function psValidationFailed(string $message): self
    {
        return new self(
            sprintf('PrestaShop order validation failed: %s', $message),
            422
        );
    }

    public static function couponUnavailable(string $message): self
    {
        return new self($message, 503);
    }

    public static function couponReconciliationRequired(string $message, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Coupon checkout outcome is uncertain: %s', $message),
            409,
            $previous
        );
    }

    public static function stockUpdateFailed(int $psOrderId, Throwable $previous): self
    {
        return new self(
            sprintf('Stock update failed for PS order %d', $psOrderId),
            0,
            $previous
        );
    }

    public static function orderMetaFailed(int $psOrderId, string $reference, Throwable $previous): self
    {
        return new self(
            sprintf('Order meta persist failed for PS order %d (ref: %s)', $psOrderId, $reference),
            0,
            $previous
        );
    }
}
