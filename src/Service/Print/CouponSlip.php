<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

/**
 * The lines of a coupon slip (ticket printer, PDF and email): the store header of
 * the till, as on its tickets, and the coupon as stored in issued_coupon.
 */
final class CouponSlip
{
    public const TITLE = 'CUPÓN DE DESCUENTO';
    public const REDEEM_LINE = 'Canjeable en tienda y en la web';

    public function __construct(
        public readonly string $code,
        /** 'amount' (euros) or 'percentage' */
        public readonly string $discountType,
        public readonly float $value,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly int $quantity,
        public readonly int $quantityPerUser,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $storeName,
        public readonly ?string $legalName = null,
        public readonly ?string $taxId = null,
        public readonly string $storeAddress = '',
        /** data: URI uploaded in Ajustes → Cajas */
        public readonly ?string $logo = null,
        public readonly ?float $marginLeftMm = null,
        public readonly ?float $marginRightMm = null,
        public readonly ?int $logoWidthPct = null,
    ) {
    }

    /** «100,00 €» or «10 %» («12,5 %» when the percentage has decimals). */
    public function valueLabel(): string
    {
        if ($this->discountType === 'percentage') {
            $percent = rtrim(rtrim(number_format($this->value, 2, ',', '.'), '0'), ',');

            return $percent . ' %';
        }

        return number_format($this->value, 2, ',', '.') . ' €';
    }

    public function expiryLabel(): string
    {
        return 'Válido hasta ' . $this->expiresAt->format('d/m/Y');
    }

    public function usesLabel(): string
    {
        return $this->quantity === 1 ? '1 uso' : $this->quantity . ' usos';
    }

    public function perUserLabel(): string
    {
        return 'Usos por cliente: ' . $this->quantityPerUser;
    }

    public function createdLabel(): string
    {
        return 'Emitido el ' . $this->createdAt->format('d/m/Y H:i');
    }

    /** The legal name only when it differs from the store name, like the tickets. */
    public function legalNameLine(): ?string
    {
        return $this->legalName !== null && $this->legalName !== $this->storeName ? $this->legalName : null;
    }
}
