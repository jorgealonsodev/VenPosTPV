<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * One label (docs/prd/agente-impresion.md L3): the product name (printed on at most
 * two lines), its combination ("M · Azul"), the current price with VAT as of the
 * last catalog sync and the code with its text.
 */
final class Label
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $combination,
        public readonly float $price,
        public readonly LabelBarcode $barcode,
    ) {
    }

    /** "23,14 €", like the rest of the POS. */
    public function priceText(): string
    {
        return number_format($this->price, 2, ',', '.') . ' €';
    }
}
