<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

/**
 * Label text for the printer: control characters removed (a catalog name can never
 * carry printer commands) and WPC1252 bytes for character table 16, like the ticket.
 */
final class LabelText
{
    public const COLUMNS = 48;

    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    }

    public static function toWpc1252(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', self::clean($text));

        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', self::clean($text)) : $converted;
    }

    /**
     * The name on at most two lines of 48 columns (font A on 576 dots), the second
     * ending with "…" when it does not fit.
     *
     * @return list<string> WPC1252 lines
     */
    public static function nameLines(string $name, int $maxLines = 2): array
    {
        $bytes = self::toWpc1252($name);
        if ($bytes === '') {
            return [];
        }
        $lines = explode("\n", wordwrap($bytes, self::COLUMNS, "\n", true));
        if (\count($lines) <= $maxLines) {
            return $lines;
        }
        $kept = \array_slice($lines, 0, $maxLines);
        $last = rtrim(substr($kept[$maxLines - 1], 0, self::COLUMNS - 1));
        $kept[$maxLines - 1] = $last . "\x85";

        return $kept;
    }
}
