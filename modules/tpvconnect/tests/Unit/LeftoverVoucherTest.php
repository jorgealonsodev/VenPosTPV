<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/** In-memory shop: orders with their cart rules, and the cart_rule table. */
final class FakeLeftoverVoucherGateway implements \LeftoverVoucherGateway
{
    /** @var array<int, array{id_customer: int, date_add: string, id_currency: int, cart_rules: list<array{id_cart_rule: int, value: float, value_tax_excl: float}>}> */
    public array $orders = [];
    /** @var array<int, array<string, mixed>> */
    public array $rules = [];
    /** @var list<array{int, string}> */
    public array $published = [];
    public bool $failOnPublish = false;

    public function order(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    public function cartRule(int $cartRuleId): ?array
    {
        return $this->rules[$cartRuleId] ?? null;
    }

    public function rulesByCode(string $code, int $afterId, string $since): array
    {
        $found = array_values(array_filter(
            $this->rules,
            static fn (array $rule): bool => $rule['code'] === $code && $rule['id_cart_rule'] > $afterId && $rule['date_add'] >= $since,
        ));
        usort($found, static fn (array $a, array $b): int => $a['id_cart_rule'] <=> $b['id_cart_rule']);

        return $found;
    }

    public function makePublic(int $cartRuleId, string $dateTo): void
    {
        if ($this->failOnPublish) {
            throw new \RuntimeException('database down');
        }
        $this->published[] = [$cartRuleId, $dateTo];
        $this->rules[$cartRuleId]['id_customer'] = 0;
        $this->rules[$cartRuleId]['date_to'] = $dateTo;
    }
}

/**
 * A voucher worth more than the TPV sale: PrestaShop's validateOrder clones it with
 * the rest («<code>-2», quantity 1, for the order's customer). The module finds that
 * exact clone, makes it usable by anyone until the original's expiry and returns it.
 */
final class LeftoverVoucherTest extends TestCase
{
    private const ORDER = 501;
    private const CUSTOMER = 7;

    private FakeLeftoverVoucherGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakeLeftoverVoucherGateway();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function rule(int $id, string $code, float $amount, array $overrides = []): array
    {
        return $overrides + [
            'id_cart_rule' => $id, 'code' => $code, 'reduction_amount' => $amount, 'reduction_tax' => 1,
            'reduction_currency' => 1, 'partial_use' => 1, 'free_shipping' => 0, 'date_to' => '2027-06-30 23:59:59',
            'id_customer' => 0, 'date_add' => '2026-11-01 10:00:00',
        ];
    }

    /** A 50 € partial-use voucher spent on a 36 € sale, and the 14 € clone PrestaShop made. */
    private function fiftyOnThirtySix(array $original = [], ?array $clone = []): void
    {
        $this->gateway->rules[40] = $this->rule(40, 'ABCD1234', 50.0, $original);
        $this->gateway->orders[self::ORDER] = [
            'id_customer' => self::CUSTOMER, 'date_add' => '2026-11-09 12:00:00', 'id_currency' => 1,
            'cart_rules' => [['id_cart_rule' => 40, 'value' => 36.0, 'value_tax_excl' => 29.75]],
        ];
        if ($clone !== null) {
            $this->gateway->rules[41] = $this->rule(41, 'ABCD1234-2', 14.0, $clone + [
                'id_customer' => self::CUSTOMER, 'date_add' => '2026-11-09 12:00:00',
            ]);
        }
    }

    private function publisher(): \LeftoverVoucher
    {
        return new \LeftoverVoucher($this->gateway);
    }

    public function test_the_leftover_clone_of_the_order_is_made_public_with_the_original_expiry(): void
    {
        $this->fiftyOnThirtySix();

        $leftover = $this->publisher()->publish(self::ORDER);

        self::assertSame(['id' => 41, 'code' => 'ABCD1234-2', 'amount' => 14.0, 'expires_at' => '2027-06-30 23:59:59'], $leftover);
        self::assertSame([[41, '2027-06-30 23:59:59']], $this->gateway->published);
        self::assertSame(0, $this->gateway->rules[41]['id_customer'], 'anyone can use it at the till or on the web');
    }

    public function test_the_order_response_carries_the_leftover_voucher(): void
    {
        $this->fiftyOnThirtySix();

        $response = $this->publisher()->attachTo(['order_id' => self::ORDER, 'reference' => 'XKBKNABJK', 'total_paid' => 0.0]);

        self::assertSame(['order_id' => self::ORDER, 'reference' => 'XKBKNABJK', 'total_paid' => 0.0, 'leftover_voucher' => ['id' => 41, 'code' => 'ABCD1234-2', 'amount' => 14.0, 'expires_at' => '2027-06-30 23:59:59']], $response);
    }

    public function test_a_replay_returns_the_same_leftover_without_changing_it_again(): void
    {
        $this->fiftyOnThirtySix();
        $first = $this->publisher()->publish(self::ORDER);
        $rulesAfterFirst = $this->gateway->rules;

        $again = $this->publisher()->attachTo(['order_id' => self::ORDER, 'reference' => 'XKBKNABJK', 'total_paid' => 0.0, 'replayed' => true]);

        self::assertSame($first, $again['leftover_voucher']);
        self::assertCount(1, $this->gateway->published, 'the replay alters nothing');
        self::assertSame($rulesAfterFirst, $this->gateway->rules);
    }

    public function test_a_fully_spent_voucher_has_no_leftover(): void
    {
        $this->fiftyOnThirtySix([], null);
        $this->gateway->orders[self::ORDER]['cart_rules'][0]['value'] = 50.0;
        // An unrelated coupon an employee happened to name like a clone.
        $this->gateway->rules[42] = $this->rule(42, 'ABCD1234-2', 25.0, ['id_customer' => 3, 'date_add' => '2026-11-09 12:00:00']);

        self::assertNull($this->publisher()->publish(self::ORDER));
        self::assertSame(['order_id' => self::ORDER, 'leftover_voucher' => null], $this->publisher()->attachTo(['order_id' => self::ORDER]));
        self::assertSame([], $this->gateway->published);
    }

    public function test_percentage_and_single_use_vouchers_have_no_leftover(): void
    {
        $this->fiftyOnThirtySix(['reduction_amount' => 0.0, 'reduction_percent' => 20.0], null);
        self::assertNull($this->publisher()->publish(self::ORDER));

        $this->fiftyOnThirtySix(['partial_use' => 0], null);
        self::assertNull($this->publisher()->publish(self::ORDER));
        self::assertSame([], $this->gateway->published);
    }

    public function test_only_the_clone_made_for_this_order_is_taken(): void
    {
        $this->fiftyOnThirtySix([], null);
        // Same code before the order, another amount, another customer: never the leftover.
        $this->gateway->rules[39] = $this->rule(39, 'ABCD1234-2', 14.0, ['id_customer' => self::CUSTOMER, 'date_add' => '2026-11-01 09:00:00']);
        $this->gateway->rules[43] = $this->rule(43, 'ABCD1234-2', 9.0, ['id_customer' => self::CUSTOMER, 'date_add' => '2026-11-09 12:00:00']);
        $this->gateway->rules[44] = $this->rule(44, 'ABCD1234-2', 14.0, ['id_customer' => 3, 'date_add' => '2026-11-09 12:00:00']);
        $this->gateway->rules[45] = $this->rule(45, 'ABCD1234-2', 14.0, ['id_customer' => self::CUSTOMER, 'date_add' => '2026-11-09 12:00:01']);

        self::assertSame(['id' => 45, 'code' => 'ABCD1234-2', 'amount' => 14.0, 'expires_at' => '2027-06-30 23:59:59'], $this->publisher()->publish(self::ORDER));
        self::assertSame([[45, '2027-06-30 23:59:59']], $this->gateway->published);
    }

    public function test_the_leftover_code_follows_prestashop_numbering(): void
    {
        self::assertSame('ABCD1234-2', \LeftoverVoucher::leftoverCode('ABCD1234'));
        self::assertSame('ABCD1234-3', \LeftoverVoucher::leftoverCode('ABCD1234-2'));
        self::assertSame('ABCD1234-3-2', \LeftoverVoucher::leftoverCode('ABCD1234-3'));
    }

    public function test_the_leftover_of_a_leftover_is_found_by_its_next_code(): void
    {
        $this->fiftyOnThirtySix(['code' => 'ABCD1234-2'], ['code' => 'ABCD1234-3']);

        self::assertSame('ABCD1234-3', $this->publisher()->publish(self::ORDER)['code'] ?? null);
    }

    public function test_free_shipping_vouchers_accept_a_clone_reduced_by_the_shipping(): void
    {
        $this->fiftyOnThirtySix(['free_shipping' => 1], ['reduction_amount' => 9.0, 'free_shipping' => 1]);

        self::assertSame(9.0, $this->publisher()->publish(self::ORDER)['amount'] ?? null);
    }

    public function test_a_failure_while_publishing_never_breaks_the_order_response(): void
    {
        $this->fiftyOnThirtySix();
        $this->gateway->failOnPublish = true;

        self::assertSame(['order_id' => self::ORDER, 'leftover_voucher' => null], $this->publisher()->attachTo(['order_id' => self::ORDER]));
    }

    public function test_an_unknown_order_has_no_leftover(): void
    {
        self::assertNull($this->publisher()->publish(999));
    }
}
