<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Mail;

/** A ticket email that could not be sent; the message is for the cashier (Spanish). */
final class TicketMailException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
