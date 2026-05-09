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
    ) {
    }
}
