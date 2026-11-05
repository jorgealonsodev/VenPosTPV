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
        // Sale of an exchange: the returned goods' credit applied to this total, and
        // the credit note it comes from when known.
        public readonly ?float $exchangeCredit = null,
        public readonly ?string $exchangeCreditNote = null,
        // Issuer data a simplified invoice must show: legal name and NIF.
        public readonly ?string $legalName = null,
        public readonly ?string $taxId = null,
        // PRD S5: the PrestaShop reference when PrestaShop answered one other than the
        // ticket code; printed as "Pedido web: XXXX" under it. Null for every other ticket.
        public readonly ?string $webOrderReference = null,
        // VERI*FACTU on: the AEAT cotejo URL printed as «QR tributario:» at the top.
        public readonly ?string $fiscalQrUrl = null,
        // Ajustes → Cajas: side margins in mm (0-10) and the logo width as a % of the
        // printable width (20-100); null keeps the default (2 mm, the whole width).
        public readonly ?float $ticketMarginLeftMm = null,
        public readonly ?float $ticketMarginRightMm = null,
        public readonly ?int $ticketLogoWidthPct = null,
        // Sale tickets: the VAT box, one row per rate (art. 7.1.f RD 1619/2012); empty
        // when the sale has no stored lines, then the single «IVA:» line is printed.
        /** @var list<array{rate: float, base: float, vat: float}> */
        public readonly array $vatBreakdown = [],
        // factura-completa F3.4: the full invoice that replaced this ticket, printed
        // at the foot of a reprint («Sustituido por la factura F26000004»).
        public readonly ?string $replacedByInvoice = null,
    ) {
    }

    /** Rate as printed: «21 %», «5,5 %». */
    public static function formatRate(float $rate): string
    {
        $text = number_format($rate, 2, ',', '');
        $text = rtrim(rtrim($text, '0'), ',');

        return $text . ' %';
    }

    /** Side margin used when the till sets none. */
    public const DEFAULT_MARGIN_MM = 2.0;

    /**
     * Spanish label of how the sale was paid; with an exchange credit it reads
     * "Abono" alone or "Abono + <method>" for the balance.
     */
    public function paymentMethodLabel(): string
    {
        $label = match ($this->paymentMethod) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'mixed' => 'Mixto',
            'voucher' => 'Vale',
            'exchange' => 'Abono',
            default => $this->paymentMethod,
        };
        if ($this->exchangeCredit === null || $this->paymentMethod === 'exchange') {
            return $label;
        }

        return 'Abono + ' . $label;
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
