<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

/**
 * Credit-note (abono) data printed instead of the tendered cash block.
 * rectifiesReference is "Sin ticket" for a refund without a ticket; the voucher
 * fields are set when the refund was paid with a PrestaShop voucher.
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
        public readonly ?string $voucherCode = null,
        public readonly ?float $voucherAmount = null,
    ) {
    }
}
