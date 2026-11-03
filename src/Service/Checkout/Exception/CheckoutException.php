<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout\Exception;

use RuntimeException;
use Throwable;

final class CheckoutException extends RuntimeException
{
    /** PRD V5: what the cashier reads when a coupon needs a PrestaShop that does not answer. */
    public const COUPON_OFFLINE_MESSAGE = 'Sin conexión con PrestaShop: no se puede aplicar el cupón. Puedes cobrar sin él.';

    private bool $couponOffline = false;

    /**
     * PrestaShop could not be reached for a coupon preview or a coupon order that
     * never arrived: nothing exists in PrestaShop nor in the TPV, so the cashier
     * can charge the same cart without the coupon (PRD V5).
     */
    public static function couponOffline(?Throwable $previous = null): self
    {
        $e = new self(self::COUPON_OFFLINE_MESSAGE, 503, $previous);
        $e->couponOffline = true;

        return $e;
    }

    public function isCouponOffline(): bool
    {
        return $this->couponOffline;
    }

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

    /** PrestaShop refused the order for good (HTTP 404 or 422): retrying cannot help. */
    public static function psRejected(int $status, string $details): self
    {
        return new self(sprintf('PrestaShop rejected the order (HTTP %d): %s', $status, $details), 422);
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

    /** The cashier must fix the terminal (no ticket letter) or the series ran out: nothing was stored. */
    public static function ticketNumberUnavailable(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 422, $previous);
    }

    /** The local sale transaction rolled back: nothing was stored, charging again is safe. */
    public static function localSaveFailed(Throwable $previous): self
    {
        return new self(sprintf('The sale could not be stored locally: %s', $previous->getMessage()), 0, $previous);
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
