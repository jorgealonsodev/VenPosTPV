<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * Chooses the code of a label (docs/prd/agente-impresion.md L4): the combination's
 * ean13 and, without one, the product's. Its check digit decides EAN-13 (13 digits),
 * EAN-8 (8) or UPC-A (12). Without a valid one, the reference in Code128 (set C
 * when it is numeric with an even length, which halves its width; set B otherwise).
 * A code that does not fit the 576 dots of the TM-T20 at the smallest module that
 * scanners read (0.25 mm = 2 dots at 203 dpi), or no code at all, is refused with
 * the reason.
 */
final class BarcodeSelector
{
    public const PAPER_DOTS = 576;
    public const MIN_MODULE_DOTS = 2;
    public const PREFERRED_MODULE_DOTS = 3;

    public function select(?string $combinationEan, ?string $productEan, ?string $reference): LabelBarcode
    {
        $ean = trim((string) $combinationEan);
        if ($ean === '') {
            $ean = trim((string) $productEan);
        }
        if ($ean !== '') {
            $barcode = self::ean($ean);
            if ($barcode !== null) {
                return $barcode;
            }
        }

        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new LabelException($ean !== ''
                ? sprintf('El EAN «%s» no es válido y no hay referencia', $ean)
                : 'Sin EAN ni referencia: no se puede imprimir la etiqueta');
        }

        return self::code128($reference);
    }

    public static function validCheckDigit(string $digits): bool
    {
        if (preg_match('/\A\d{8}\z|\A\d{12,13}\z/', $digits) !== 1) {
            return false;
        }
        // Weights 3 and 1 alternate from the digit next to the check digit.
        $body = strrev(substr($digits, 0, -1));
        $sum = 0;
        for ($i = 0, $n = \strlen($body); $i < $n; ++$i) {
            $sum += (int) $body[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    private static function ean(string $digits): ?LabelBarcode
    {
        if (!self::validCheckDigit($digits)) {
            return null;
        }
        $type = match (\strlen($digits)) {
            13 => LabelBarcode::EAN13,
            12 => LabelBarcode::UPCA,
            default => LabelBarcode::EAN8,
        };

        // 113 modules at most: always fits at 3 dots (0.375 mm).
        return new LabelBarcode($type, $digits, $digits, self::PREFERRED_MODULE_DOTS);
    }

    private static function code128(string $reference): LabelBarcode
    {
        if (preg_match('/\A[\x20-\x7E]+\z/', $reference) !== 1) {
            throw new LabelException(sprintf('La referencia «%s» tiene caracteres que no admite el código de barras', $reference));
        }

        $setC = preg_match('/\A\d+\z/', $reference) === 1 && \strlen($reference) % 2 === 0;
        $modules = BarcodeModules::code128Width($setC ? intdiv(\strlen($reference), 2) : \strlen($reference));
        foreach ([self::PREFERRED_MODULE_DOTS, self::MIN_MODULE_DOTS] as $dots) {
            if ($modules * $dots <= self::PAPER_DOTS) {
                return new LabelBarcode(LabelBarcode::CODE128, $reference, $reference, $dots, $setC ? 'C' : 'B');
            }
        }

        throw new LabelException($setC
            ? sprintf('La referencia «%s» es demasiado larga para el código de barras (máximo 42 cifras)', $reference)
            : sprintf('La referencia «%s» es demasiado larga para el código de barras (máximo 21 caracteres)', $reference));
    }
}
