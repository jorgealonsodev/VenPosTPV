<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

use VenPosTPV\Service\Print\Code128Encoder;

/**
 * The bars of a label code as a module string ('1' bar, '0' space) with its quiet
 * zones: EAN-13, EAN-8, UPC-A and Code128 sets B and C. Used to check that a code
 * fits the paper at a given module width and to draw the preview; the printer
 * draws the real bars itself (GS k).
 */
final class BarcodeModules
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
    private const START_B = 104;
    private const START_C = 105;
    private const STOP = 106;
    public const CODE128_QUIET = 10;

    public static function forBarcode(LabelBarcode $barcode): string
    {
        return match ($barcode->type) {
            LabelBarcode::EAN13 => self::ean13($barcode->data),
            LabelBarcode::UPCA => self::upcA($barcode->data),
            LabelBarcode::EAN8 => self::ean8($barcode->data),
            default => self::code128($barcode->data, $barcode->codeSet),
        };
    }

    public static function ean13(string $digits): string
    {
        $parity = self::PARITY[(int) $digits[0]];
        $bars = '101';
        for ($i = 1; $i <= 6; ++$i) {
            $bars .= ($parity[$i - 1] === 'L' ? self::L : self::G)[(int) $digits[$i]];
        }
        $bars .= '01010';
        for ($i = 7; $i <= 12; ++$i) {
            $bars .= self::R[(int) $digits[$i]];
        }

        return str_repeat('0', 11) . $bars . '101' . str_repeat('0', 7);
    }

    public static function upcA(string $digits): string
    {
        // UPC-A is EAN-13 with a leading zero; its quiet zones are 9 modules.
        $modules = substr(self::ean13('0' . $digits), 11, 95);

        return str_repeat('0', 9) . $modules . str_repeat('0', 9);
    }

    public static function ean8(string $digits): string
    {
        $bars = '101';
        for ($i = 0; $i < 4; ++$i) {
            $bars .= self::L[(int) $digits[$i]];
        }
        $bars .= '01010';
        for ($i = 4; $i < 8; ++$i) {
            $bars .= self::R[(int) $digits[$i]];
        }

        return str_repeat('0', 7) . $bars . '101' . str_repeat('0', 7);
    }

    /**
     * @return list<int> start, data symbols, checksum and stop
     */
    public static function code128Symbols(string $data, string $codeSet): array
    {
        $values = [];
        if ($codeSet === 'C') {
            foreach (str_split($data, 2) as $pair) {
                $values[] = (int) $pair;
            }
        } else {
            foreach (str_split($data) as $char) {
                $values[] = \ord($char) - 32;
            }
        }
        $start = $codeSet === 'C' ? self::START_C : self::START_B;
        $checksum = $start;
        foreach ($values as $position => $value) {
            $checksum += $value * ($position + 1);
        }

        return [$start, ...$values, $checksum % 103, self::STOP];
    }

    /** Modules of a Code128 symbol of this many data symbols, with its quiet zones. */
    public static function code128Width(int $dataSymbols): int
    {
        // start + data + checksum are 11 modules each; the stop with its bar is 13.
        return 11 * ($dataSymbols + 2) + 13 + 2 * self::CODE128_QUIET;
    }

    public static function code128(string $data, string $codeSet): string
    {
        $modules = '';
        foreach (self::code128Symbols($data, $codeSet) as $value) {
            foreach (str_split(Code128Encoder::PATTERNS[$value]) as $i => $width) {
                $modules .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $width);
            }
        }

        return str_repeat('0', self::CODE128_QUIET) . $modules . str_repeat('0', self::CODE128_QUIET);
    }
}
