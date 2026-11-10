<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Coupon;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidFieldNameException;

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

    /**
     * The coupons newest first, optionally those whose code contains $query, with
     * the cashier (full name, else user name) and the till that created them.
     *
     * @return list<array{id: int, ps_cart_rule_id: int, code: string, type: string, value: float, expires_at: string, created_at: string, cashier: ?string, terminal: ?string}>
     */
    public function listRecent(?string $query, int $limit, int $offset): array
    {
        $where = '';
        $params = [];
        $query = trim((string) $query);
        if ($query !== '') {
            $where = "WHERE LOWER(c.code) LIKE ? ESCAPE '!'";
            $params[] = '%' . strtolower(strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_'])) . '%';
        }
        $sql = static fn (string $cashier): string => 'SELECT c.id, c.ps_cart_rule_id, c.code, c.discount_type, c.value, c.expires_at, c.created_at, '
            . $cashier . ' AS cashier, t.name AS terminal FROM issued_coupon c '
            . 'LEFT JOIN user u ON u.id = c.user_id LEFT JOIN terminal t ON t.id = c.terminal_id '
            . $where . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        try {
            $rows = $this->connection->fetchAllAssociative($sql("COALESCE(NULLIF(TRIM(u.full_name), ''), u.name)"), $params);
        } catch (InvalidFieldNameException) {
            // Code deployed before Version00000049: no full_name column yet.
            $rows = $this->connection->fetchAllAssociative($sql('u.name'), $params);
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'ps_cart_rule_id' => (int) $r['ps_cart_rule_id'],
            'code' => (string) $r['code'],
            'type' => (string) $r['discount_type'],
            'value' => (float) $r['value'],
            'expires_at' => substr((string) $r['expires_at'], 0, 10),
            'created_at' => (string) $r['created_at'],
            'cashier' => isset($r['cashier']) && $r['cashier'] !== '' ? (string) $r['cashier'] : null,
            'terminal' => isset($r['terminal']) && $r['terminal'] !== '' ? (string) $r['terminal'] : null,
        ], $rows);
    }
}
