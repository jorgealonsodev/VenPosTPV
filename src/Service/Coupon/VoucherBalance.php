<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Coupon;

use VenPosTPV\Service\PrestaShop\CouponStatusLookup;

/**
 * The balance of an amount voucher at «Validar y aplicar»: what it is worth, what
 * this sale uses and what PrestaShop keeps in a new voucher when the sale is
 * charged (partial use). Read from the cart rule; never part of the signed quote,
 * so it only informs the cashier.
 */
class VoucherBalance
{
    public function __construct(private readonly CouponStatusLookup $lookup)
    {
    }

    /** @return array{balance: float, used: float, left: float}|null null: not an amount voucher with a rest, or the shop did not answer */
    public function forSale(string $code, float $discount): ?array
    {
        $rules = $this->lookup->findByCode(trim($code));
        $rule = \is_array($rules) ? reset($rules) : false;
        // Amounts with VAT, as the till shows them; a rest is only kept with partial use.
        if ($rule === false || $rule['reduction_amount'] <= 0 || $rule['reduction_percent'] > 0 || !$rule['reduction_tax'] || !$rule['partial_use']) {
            return null;
        }
        $balance = round($rule['reduction_amount'], 2);
        $used = round(min($balance, max(0.0, $discount)), 2);

        return ['balance' => $balance, 'used' => $used, 'left' => round($balance - $used, 2)];
    }
}
