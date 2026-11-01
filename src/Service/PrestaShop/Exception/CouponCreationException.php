<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop\Exception;

final class CouponCreationException extends \RuntimeException
{
    public static function validation(string $message): self
    {
        return new self($message, 422);
    }

    public static function unavailable(): self
    {
        return new self('La creación de cupones no está disponible en este momento.', 503);
    }

    public static function reconcile(string $code): self
    {
        return new self(sprintf('No se pudo confirmar la creación del cupón %s. Compruébalo en PrestaShop antes de repetirlo.', $code), 409);
    }
}
