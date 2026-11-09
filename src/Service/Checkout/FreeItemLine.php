<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

use VenPosTPV\Service\Checkout\Exception\CheckoutException;

/**
 * Validates a free item line on the server (docs/prd/articulo-libre.md, A1.2 and A2):
 * the browser's screen checks are a convenience, these are the rules.
 */
final class FreeItemLine
{
    /** PrestaShop refuses these in a product name. */
    private const FORBIDDEN = '/[<>;=#{}]/u';

    public const MAX_SLOTS_PER_RATE = 20;

    public static function isFree(mixed $line): bool
    {
        return \is_array($line) && ($line['free_item'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed> the line with its normalized concept, price, rate,
     *                              quantity and net price; never a PrestaShop product
     *
     * @throws CheckoutException 422 with the reason shown to the cashier, 403 by role
     */
    public static function normalize(array $line, FreeItemSettings $settings, ?string $sessionRole): array
    {
        if (!$settings->enabled) {
            throw CheckoutException::freeItemInvalid('El artículo libre no está activado');
        }
        if (!$settings->allows($sessionRole)) {
            throw CheckoutException::freeItemForbidden();
        }
        if ((int) ($line['product_id'] ?? $line['id'] ?? 0) !== 0 || (int) ($line['id_product_attribute'] ?? 0) !== 0) {
            throw CheckoutException::freeItemInvalid('Un artículo libre no puede llevar producto');
        }

        $concept = self::concept($line['concept'] ?? $line['name'] ?? '');
        $price = self::price($line['price'] ?? null, $settings->maxPrice);

        $rate = $line['vat_rate'] ?? null;
        if (!is_numeric($rate) || !$settings->offersRate((float) $rate)) {
            throw CheckoutException::freeItemInvalid(sprintf('El tipo de IVA %s %% no está configurado', is_scalar($rate) ? (string) $rate : ''));
        }
        $rate = (float) $rate;

        $qty = $line['qty'] ?? $line['quantity'] ?? 1;
        if (!\is_int($qty) && !(\is_string($qty) && ctype_digit($qty)) && !(\is_float($qty) && floor($qty) === $qty)) {
            $qty = 0;
        }
        $qty = (int) $qty;
        if ($qty < 1 || $qty > 999) {
            throw CheckoutException::freeItemInvalid('La cantidad debe ser un número entero entre 1 y 999');
        }

        return [
            'free_item' => true,
            'concept' => $concept,
            'name' => $concept,
            'product_id' => 0,
            'id_product_attribute' => 0,
            'qty' => $qty,
            'price' => $price,
            'vat_rate' => $rate,
            'price_net' => round($price / (1 + $rate / 100), 6),
            'discount' => max(0.0, min(100.0, (float) ($line['discount'] ?? 0))),
        ];
    }

    /**
     * Gives each free line its upload slot: 1, 2, … per VAT rate, in ticket order.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     *
     * @throws CheckoutException 422 above MAX_SLOTS_PER_RATE lines of one rate
     */
    public static function assignSlots(array $lines): array
    {
        $used = [];
        foreach ($lines as $index => $line) {
            if (!self::isFree($line)) {
                continue;
            }
            $key = number_format((float) $line['vat_rate'], 2, '.', '');
            $used[$key] = ($used[$key] ?? 0) + 1;
            if ($used[$key] > self::MAX_SLOTS_PER_RATE) {
                throw CheckoutException::freeItemInvalid(sprintf('Máximo %d artículos libres del mismo IVA por ticket', self::MAX_SLOTS_PER_RATE));
            }
            $lines[$index]['slot'] = $used[$key];
        }

        return $lines;
    }

    private static function concept(mixed $value): string
    {
        $concept = \is_string($value) ? trim((string) preg_replace('/[ \t\r\n]+/u', ' ', $value)) : '';
        if ($concept === '') {
            throw CheckoutException::freeItemInvalid('El concepto es obligatorio');
        }
        if (mb_strlen($concept) > 100) {
            throw CheckoutException::freeItemInvalid('El concepto no puede tener más de 100 caracteres');
        }
        if (preg_match(self::FORBIDDEN, $concept) === 1) {
            throw CheckoutException::freeItemInvalid('El concepto no puede llevar < > ; = # { }');
        }
        // The ticket printer prints Windows-1252: anything it cannot print is refused.
        $printable = mb_check_encoding($concept, 'UTF-8')
            && preg_match('/\p{Cc}/u', $concept) !== 1
            && mb_convert_encoding(mb_convert_encoding($concept, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') === $concept;
        if (!$printable) {
            throw CheckoutException::freeItemInvalid('El concepto tiene caracteres que no se pueden imprimir');
        }

        return $concept;
    }

    private static function price(mixed $value, float $max): float
    {
        if (\is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        $range = sprintf('El precio debe estar entre 0,01 € y %s €', number_format($max, 2, ',', ''));
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw CheckoutException::freeItemInvalid($range);
        }
        $price = (float) $value;
        if (abs(round($price, 2) - $price) > 1e-9) {
            throw CheckoutException::freeItemInvalid('El precio no puede tener más de 2 decimales');
        }
        $price = round($price, 2);
        if ($price < 0.01 || $price > $max) {
            throw CheckoutException::freeItemInvalid($range);
        }

        return $price;
    }
}
