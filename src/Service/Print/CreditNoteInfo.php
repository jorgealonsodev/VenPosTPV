<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

/**
 * Credit-note (abono) data printed instead of the tendered cash block.
 * rectifiesReference is "Sin ticket" for a refund without a ticket; the voucher
 * fields are set when the refund was paid with a PrestaShop voucher. exchangeCredit
 * is the part applied to an exchange's new items (R13); cash and card then hold
 * only what went back to the customer. webRefundAmount is set for an online order
 * (R10): the TPV paid nothing and the office refunds that amount from the web.
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
        public readonly ?float $exchangeCredit = null,
        public readonly ?float $webRefundAmount = null,
    ) {
    }
}
