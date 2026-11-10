<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

/**
 * The shop's bank account (IBAN) printed on the tickets paid by transfer: stored
 * without spaces in capitals, checked with its two control digits (ISO 13616,
 * mod 97) and shown in groups of four.
 */
final class Iban
{
    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]/', '', $iban));
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        if (str_starts_with($iban, 'ES') && \strlen($iban) !== 24) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $digits = '';
        foreach (str_split($rearranged) as $char) {
            $digits .= ctype_alpha($char) ? (string) (\ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (((string) $remainder) . $chunk) % 97;
        }

        return $remainder === 1;
    }

    /** «ES91 2100 0418 4502 0005 1332». */
    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }
}
