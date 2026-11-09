<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Checkout;

/**
 * Free item settings (docs/prd/articulo-libre.md, A7 and A8): whether the till may
 * sell a typed line, who may, the highest unit price and the VAT rates offered.
 */
final class FreeItemSettings
{
    public const ROLE_ALL = 'all';
    public const ROLE_ADMIN = 'admin';

    /** @param list<float> $vatRates */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $role,
        public readonly float $maxPrice,
        public readonly array $vatRates,
    ) {
    }

    public static function defaults(): self
    {
        return new self(true, self::ROLE_ALL, 1000.0, [21.0, 10.0]);
    }

    public function allows(?string $sessionRole): bool
    {
        return $this->role !== self::ROLE_ADMIN || $sessionRole === 'admin';
    }

    public function offersRate(float $rate): bool
    {
        foreach ($this->vatRates as $offered) {
            if (abs($offered - $rate) < 0.001) {
                return true;
            }
        }

        return false;
    }

    /** @return array{enabled: bool, role: string, max_price: float, vat_rates: list<float>} */
    public function toArray(): array
    {
        return ['enabled' => $this->enabled, 'role' => $this->role, 'max_price' => $this->maxPrice, 'vat_rates' => $this->vatRates];
    }
}
