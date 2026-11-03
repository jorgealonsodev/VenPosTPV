<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Customer\Exception;

final class CustomerException extends \RuntimeException
{
    /** The cashier must correct the form: nothing was stored. */
    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }

    /** Another till is storing a customer with the same email right now: nothing was stored. */
    public static function busy(string $message): self
    {
        return new self($message, 409);
    }
}
