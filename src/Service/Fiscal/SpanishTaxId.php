<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

/**
 * Spanish tax identification numbers (NIF): DNI, NIE and company CIF, with their
 * control character. Printed on every ticket as the issuer's NIF.
 */
final class SpanishTaxId
{
    private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';
    private const CIF_LETTERS = 'JABCDEFGHI';

    public static function normalize(string $taxId): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]/', '', $taxId));
    }

    public static function isValid(string $taxId): bool
    {
        $taxId = self::normalize($taxId);

        if (preg_match('/^(\d{8})([A-Z])$/', $taxId, $m)) {
            return self::DNI_LETTERS[(int) $m[1] % 23] === $m[2];
        }
        if (preg_match('/^([XYZ])(\d{7})([A-Z])$/', $taxId, $m)) {
            $number = (int) (strpos('XYZ', $m[1]) . $m[2]);

            return self::DNI_LETTERS[$number % 23] === $m[3];
        }
        if (preg_match('/^([ABCDEFGHJNPQRSUVW])(\d{7})([0-9A-J])$/', $taxId, $m)) {
            return self::cifControlMatches($m[1], $m[2], $m[3]);
        }

        return false;
    }

    private static function cifControlMatches(string $type, string $digits, string $control): bool
    {
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $digit = (int) $digits[$i];
            if ($i % 2 === 0) {
                $doubled = $digit * 2;
                $sum += intdiv($doubled, 10) + $doubled % 10;
            } else {
                $sum += $digit;
            }
        }
        $check = (10 - $sum % 10) % 10;

        if (str_contains('PQRSNW', $type)) {
            return $control === self::CIF_LETTERS[$check];
        }
        if (str_contains('ABEH', $type)) {
            return $control === (string) $check;
        }

        return $control === (string) $check || $control === self::CIF_LETTERS[$check];
    }
}
