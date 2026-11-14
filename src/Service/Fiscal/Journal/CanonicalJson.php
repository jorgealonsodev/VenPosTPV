<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

/**
 * The canonical JSON of a fiscal journal line (fiscal-journal D2), shared with the
 * shop module, which checks the same line_hash: object keys sorted by byte order at
 * every depth, list order kept, encoded with JSON_UNESCAPED_UNICODE |
 * JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION.
 */
final class CanonicalJson
{
    private const FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /** @param array<array-key, mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode(self::sorted($value), self::FLAGS);
    }

    /**
     * Lowercase hex SHA-256 of the canonical JSON of the line without its line_hash.
     *
     * @param array<string, mixed> $line
     */
    public static function lineHash(array $line): string
    {
        unset($line['line_hash']);

        return hash('sha256', self::encode($line));
    }

    private static function sorted(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
