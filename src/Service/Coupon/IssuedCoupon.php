<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Coupon;

/** A coupon created from the till, as stored in issued_coupon. */
final class IssuedCoupon
{
    public function __construct(
        public readonly int $id,
        public readonly int $psCartRuleId,
        public readonly string $code,
        /** 'amount' (euros) or 'percentage' */
        public readonly string $discountType,
        public readonly float $value,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly int $quantity,
        public readonly int $quantityPerUser,
        public readonly ?int $terminalId,
        public readonly ?int $userId,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
