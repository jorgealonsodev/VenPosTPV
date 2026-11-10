<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Coupon;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VenPosTPV\Service\PrestaShop\CouponStatusLookup;

/**
 * «Cupones»: the coupons created from the till, newest first, in pages, with their
 * state in PrestaShop. Without the shop the list still shows; then only a past
 * expiry date (known locally) gives a state.
 */
class CouponListService
{
    public const PAGE_SIZE = 25;

    public const AVAILABLE = 'available';
    public const USED = 'used';
    public const EXPIRED = 'expired';
    public const DISABLED = 'disabled';
    /** PrestaShop no longer has the cart rule. */
    public const DELETED = 'deleted';
    /** The shop did not answer. */
    public const UNKNOWN = 'unknown';

    private readonly \DateTimeZone $timezone;

    public function __construct(
        private readonly IssuedCouponStore $store,
        private readonly CouponStatusLookup $lookup,
        #[Autowire('%env(PS_SHOP_TIMEZONE)%')] string $shopTimezone = 'Europe/Madrid',
    ) {
        try {
            $this->timezone = new \DateTimeZone($shopTimezone);
        } catch (\Throwable) {
            $this->timezone = new \DateTimeZone('Europe/Madrid');
        }
    }

    /**
     * @return array{data: list<array{id: int, ps_cart_rule_id: int, code: string, type: string, value: float, expires_at: string, created_at: string, cashier: ?string, terminal: ?string, status: string, uses_left: ?int}>, has_more: bool, status_checked: bool}
     */
    public function list(?string $query, int $page, ?\DateTimeImmutable $now = null): array
    {
        $page = max(1, $page);
        $rows = $this->store->listRecent($query, self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);
        $hasMore = \count($rows) > self::PAGE_SIZE;
        $rows = \array_slice($rows, 0, self::PAGE_SIZE);

        $live = $this->lookup->find(array_map(static fn (array $r): int => $r['ps_cart_rule_id'], $rows));
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($this->timezone);

        $data = [];
        foreach ($rows as $row) {
            $rule = $live[$row['ps_cart_rule_id']] ?? null;
            $data[] = $row + [
                'status' => self::status($row['expires_at'], $live === null, $rule, $now),
                'uses_left' => $rule['quantity'] ?? null,
            ];
        }

        return ['data' => $data, 'has_more' => $hasMore, 'status_checked' => $live !== null];
    }

    /** @param array{quantity: int, active: bool, date_to: string}|null $rule */
    private static function status(string $expiresAt, bool $shopDown, ?array $rule, \DateTimeImmutable $now): string
    {
        if ($shopDown) {
            return $expiresAt < $now->format('Y-m-d') ? self::EXPIRED : self::UNKNOWN;
        }

        return match (true) {
            $rule === null => self::DELETED,
            !$rule['active'] => self::DISABLED,
            $rule['quantity'] <= 0 => self::USED,
            $rule['date_to'] !== '' && $rule['date_to'] < $now->format('Y-m-d H:i:s') => self::EXPIRED,
            default => self::AVAILABLE,
        };
    }
}
