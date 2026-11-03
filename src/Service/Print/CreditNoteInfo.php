<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

/**
 * Credit-note (abono) data printed instead of the tendered cash block.
 */
final class CreditNoteInfo
{
    public function __construct(
        public readonly string $number,
        public readonly string $rectifiesReference,
        public readonly ?\DateTimeImmutable $rectifiesDate,
        public readonly string $reason,
        public readonly float $cashAmount,
        public readonly float $cardAmount,
    ) {
    }
}
