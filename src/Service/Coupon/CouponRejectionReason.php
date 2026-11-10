<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Coupon;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VenPosTPV\Service\PrestaShop\CouponStatusLookup;

/**
 * Why the module rejected a coupon at «Validar y aplicar», for the cashier: the
 * module only says "rejected", so the coupon's state is read from PrestaShop.
 */
class CouponRejectionReason
{
    public const GENERIC = 'La tienda no acepta este cupón para esta venta: puede estar reservado a un cliente o necesitar un importe mínimo o unos productos concretos.';

    private readonly \DateTimeZone $timezone;

    public function __construct(
        private readonly CouponStatusLookup $lookup,
        #[Autowire('%env(PS_SHOP_TIMEZONE)%')] string $shopTimezone = 'Europe/Madrid',
    ) {
        try {
            $this->timezone = new \DateTimeZone($shopTimezone);
        } catch (\Throwable) {
            $this->timezone = new \DateTimeZone('Europe/Madrid');
        }
    }

    public function explain(string $code, ?\DateTimeImmutable $now = null): string
    {
        $rules = $this->lookup->findByCode(trim($code));
        if ($rules === null) {
            return self::GENERIC;
        }
        $rule = reset($rules);
        if ($rule === false) {
            return 'Este código no existe en la tienda. Revisa que esté bien escrito.';
        }
        // PrestaShop dates are in the shop's timezone.
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($this->timezone)->format('Y-m-d H:i:s');

        return match (true) {
            !$rule['active'] => 'Este cupón está desactivado en la tienda.',
            $rule['quantity'] <= 0 => 'Este cupón ya se ha usado.',
            $rule['date_to'] !== '' && $rule['date_to'] < $now => 'Este cupón caducó el ' . self::day($rule['date_to']) . '.',
            $rule['date_from'] !== '' && $rule['date_from'] > $now => 'Este cupón todavía no es válido: empieza el ' . self::day($rule['date_from']) . '.',
            default => self::GENERIC,
        };
    }

    private static function day(string $dateTime): string
    {
        $parts = explode('-', substr($dateTime, 0, 10));

        return \count($parts) === 3 ? $parts[2] . '/' . $parts[1] . '/' . $parts[0] : $dateTime;
    }
}
