<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Invoice;

/**
 * An invoice that cannot be issued (or sent), with the message to show and the HTTP
 * status the API answers. Nothing was stored and no number was taken.
 */
final class InvoiceException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details extra fields of the error response (missing, invoice_number...)
     */
    public function __construct(string $message, public readonly int $status, public readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /** @param array<string, mixed> $details */
    public static function invalid(string $message, array $details = []): self
    {
        return new self($message, 422, $details);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $message, array $details = []): self
    {
        return new self($message, 409, $details);
    }
}
