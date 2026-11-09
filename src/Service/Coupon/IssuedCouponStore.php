<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Coupon;

use Doctrine\DBAL\Connection;

/** The till's copy of the coupons it created (issued_coupon, Version00000048). */
class IssuedCouponStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Stores a coupon PrestaShop already created. Returns its local id, or null when
     * the row cannot be written: the coupon exists anyway, only its slip is lost.
     */
    public function record(int $psCartRuleId, string $code, string $discountType, float $value, string $expiresAt, int $quantity, int $quantityPerUser, ?int $terminalId, ?int $userId): ?int
    {
        try {
            $this->connection->insert('issued_coupon', [
                'ps_cart_rule_id' => $psCartRuleId,
                'code' => $code,
                'discount_type' => $discountType,
                'value' => number_format($value, 2, '.', ''),
                'expires_at' => $expiresAt,
                'quantity' => $quantity,
                'quantity_per_user' => $quantityPerUser,
                'terminal_id' => $terminalId,
                'user_id' => $userId,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return (int) $this->connection->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    public function find(int $id): ?IssuedCoupon
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, ps_cart_rule_id, code, discount_type, value, expires_at, quantity, quantity_per_user, terminal_id, user_id, created_at FROM issued_coupon WHERE id = ?',
            [$id]
        );
        if ($row === false) {
            return null;
        }

        return new IssuedCoupon(
            id: (int) $row['id'],
            psCartRuleId: (int) $row['ps_cart_rule_id'],
            code: (string) $row['code'],
            discountType: (string) $row['discount_type'],
            value: (float) $row['value'],
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            quantity: (int) $row['quantity'],
            quantityPerUser: (int) $row['quantity_per_user'],
            terminalId: $row['terminal_id'] !== null ? (int) $row['terminal_id'] : null,
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
        );
    }
}
