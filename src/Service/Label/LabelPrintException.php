<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * A label print request the TPV refuses as a whole, with its HTTP status and a
 * machine code for the POS.
 */
final class LabelPrintException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $errorCode = 'invalid_labels')
    {
        parent::__construct($message);
    }
}
