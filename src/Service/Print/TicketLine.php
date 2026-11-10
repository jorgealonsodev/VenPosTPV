<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

final class TicketLine
{
    public function __construct(
        public readonly string $name,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $lineTotal,
        // The line's own discount (% and amount off lineTotal); 0 without one.
        public readonly float $discountPct = 0.0,
        public readonly float $discountAmount = 0.0,
    ) {
    }
}
