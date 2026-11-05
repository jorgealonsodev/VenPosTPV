<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cash;

/**
 * What a set of cash totals covers: one cash session (the X/Z report) or a half-open
 * date range [from, to) of the shop, optionally of one till (the sales report «Por
 * forma de pago», docs/prd/informes-ventas.md §5).
 */
final class CashTotalsScope
{
    private function __construct(
        public readonly ?int $sessionId,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?int $terminalId,
    ) {
    }

    public static function session(int $sessionId): self
    {
        return new self($sessionId, null, null, null);
    }

    /** @param \DateTimeImmutable $to exclusive: the first moment after the range */
    public static function period(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $terminalId = null): self
    {
        return new self(null, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $terminalId !== null && $terminalId > 0 ? $terminalId : null);
    }

    public function isSession(): bool
    {
        return $this->sessionId !== null;
    }
}
