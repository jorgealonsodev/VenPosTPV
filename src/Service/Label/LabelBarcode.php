<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * The code a label carries (docs/prd/agente-impresion.md L4): its symbology, the
 * data as validated, the human-readable text under the bars and the module width
 * in printer dots (2 dots = 0.25 mm at 203 dpi) that makes it fit on 576 dots.
 */
final class LabelBarcode
{
    public const EAN13 = 'EAN13';
    public const EAN8 = 'EAN8';
    public const UPCA = 'UPCA';
    public const CODE128 = 'CODE128';

    /**
     * @param string      $codeSet 'B' or 'C' for Code128, '' otherwise
     */
    public function __construct(
        public readonly string $type,
        public readonly string $data,
        public readonly string $text,
        public readonly int $moduleDots,
        public readonly string $codeSet = '',
    ) {
    }

    /** Modules of the whole symbol with its quiet zones, for width checks and the preview. */
    public function modules(): string
    {
        return BarcodeModules::forBarcode($this);
    }
}
