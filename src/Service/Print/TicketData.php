<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

final class TicketData
{
    /**
     * @param TicketLine[] $lines
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $reference,
        public readonly string $storeName,
        public readonly string $storeAddress,
        public readonly string $ticketHeader,
        public readonly string $ticketFooter,
        public readonly ?string $ticketLogo,
        public readonly ?int $ticketWidthPx,
        public readonly \DateTimeImmutable $date,
        public readonly string $cashierName,
        public readonly array $lines,
        public readonly float $subtotal,
        public readonly float $tax,
        public readonly float $total,
        public readonly string $paymentMethod,
        public readonly float $amountTendered,
        public readonly float $changeDue,
        public readonly string $verificationUrl,
        public readonly ?CreditNoteInfo $creditNote = null,
        // Which scan code the ticket carries: 'barcode' (Code128), 'qr' or 'both'.
        public readonly string $ticketCode = 'both',
    ) {
    }

    public function showsBarcode(): bool
    {
        return $this->ticketCode === 'barcode' || $this->ticketCode === 'both';
    }

    public function showsQr(): bool
    {
        return $this->ticketCode === 'qr' || $this->ticketCode === 'both';
    }
}
