<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Support;

/**
 * MySQL stores a JSON column in its own binary form and returns object keys in
 * its own order (shorter keys first), not in the order the application wrote
 * them. Key order carries no meaning in JSON, so tests compare decoded objects
 * after sorting their keys, keeping value types and list order strict.
 */
final class JsonKeys
{
    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    public static function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
