<?php

declare(strict_types=1);

namespace VenPosTPV\Message;

final class PrintTicketMessage
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $terminalId,
    ) {
    }
}
