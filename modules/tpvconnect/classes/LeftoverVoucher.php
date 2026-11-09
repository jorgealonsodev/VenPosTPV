<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop reads and the single write LeftoverVoucher needs, so the matching rules
 * can be tested without a shop. PsLeftoverVoucherGateway is the production one.
 */
interface LeftoverVoucherGateway
{
    /**
     * @return array{id_customer: int, date_add: string, id_currency: int, cart_rules: list<array{id_cart_rule: int, value: float, value_tax_excl: float}>}|null
     */
    public function order(int $orderId): ?array;

    /**
     * @return array{id_cart_rule: int, code: string, reduction_amount: float, reduction_tax: int, reduction_currency: int, partial_use: int, free_shipping: int, date_to: string, id_customer: int, date_add: string}|null
     */
    public function cartRule(int $cartRuleId): ?array;

    /**
     * Cart rules with exactly this code, newer than $afterId and added at or after $since, lowest id first.
     *
     * @return list<array<string, mixed>>
     */
    public function rulesByCode(string $code, int $afterId, string $since): array;

    /** id_customer = 0 (anyone may use it) and the given date_to. */
    public function makePublic(int $cartRuleId, string $dateTo): void;
}

/**
 * The rest of a voucher worth more than a TPV sale.
 *
 * PaymentModule::validateOrder clones a partial-use amount voucher that is not
 * spent completely: code «<code>-2» (PrestaShop's own numbering), the remaining
 * amount, quantity 1 and quantity per user 1, bound to the order's customer. This
 * class finds exactly that clone (its code, created for this order after it, for
 * the order's customer, with the remaining amount), makes it usable by anyone
 * (id_customer 0) until the original's expiry, and describes it for the TPV.
 * Running it again for the same order returns the same voucher and changes nothing.
 */
class LeftoverVoucher
{
    private const MONEY_EPSILON = 0.005;

    public function __construct(private readonly LeftoverVoucherGateway $gateway)
    {
    }

    /**
     * The order response plus `leftover_voucher` (null when there is none). A
     * failure here never fails the order: it already exists.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public function attachTo(array $response): array
    {
        try {
            $leftover = $this->publish((int) ($response['order_id'] ?? 0));
        } catch (Throwable) {
            $leftover = null;
        }

        return $response + ['leftover_voucher' => $leftover];
    }

    /** @return array{id: int, code: string, amount: float, expires_at: string}|null */
    public function publish(int $orderId): ?array
    {
        $order = $orderId > 0 ? $this->gateway->order($orderId) : null;
        if ($order === null) {
            return null;
        }

        foreach ($order['cart_rules'] as $used) {
            $original = $this->gateway->cartRule((int) $used['id_cart_rule']);
            if ($original === null || (int) $original['partial_use'] !== 1 || (float) $original['reduction_amount'] <= 0
                || trim((string) $original['code']) === '') {
                continue;
            }
            $spent = (int) $original['reduction_tax'] === 1 ? (float) $used['value'] : (float) $used['value_tax_excl'];
            // In another currency PrestaShop converts the amount first; the code,
            // order and customer still identify the clone.
            $remaining = (int) $original['reduction_currency'] === (int) $order['id_currency']
                ? round((float) $original['reduction_amount'] - $spent, 2)
                : null;
            if ($remaining !== null && $remaining <= 0) {
                continue;
            }

            $code = self::leftoverCode((string) $original['code']);
            foreach ($this->gateway->rulesByCode($code, (int) $original['id_cart_rule'], (string) $order['date_add']) as $candidate) {
                if (!$this->isTheClone($candidate, $order, $remaining)) {
                    continue;
                }
                $dateTo = (string) $candidate['date_to'];
                if ((int) $candidate['id_customer'] !== 0) {
                    $dateTo = (string) $original['date_to'];
                    $this->gateway->makePublic((int) $candidate['id_cart_rule'], $dateTo);
                }

                return [
                    'id' => (int) $candidate['id_cart_rule'],
                    'code' => (string) $candidate['code'],
                    'amount' => round((float) $candidate['reduction_amount'], 2),
                    'expires_at' => $dateTo,
                ];
            }
        }

        return null;
    }

    /** The code PaymentModule::validateOrder gives the clone of $code. */
    public static function leftoverCode(string $code): string
    {
        $next = $code . '-2';
        if (preg_match('/\-([0-9]{1,2})\-([0-9]{1,2})$/', $next, $matches) === 1 && $matches[1] === $matches[2]) {
            $next = substr($next, 0, -strlen($matches[0])) . '-' . ((int) $matches[1] + 1);
        }

        return $next;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $order
     */
    private function isTheClone(array $candidate, array $order, ?float $remaining): bool
    {
        $customer = (int) $candidate['id_customer'];
        // 0: a guest order, or this module already made it public on a first request.
        if ($customer !== 0 && $customer !== (int) $order['id_customer']) {
            return false;
        }
        if ($remaining === null) {
            return true;
        }
        $amount = (float) $candidate['reduction_amount'];

        // Free shipping: PrestaShop takes the order's shipping off the rest.
        return (int) $candidate['free_shipping'] === 1
            ? $amount > 0 && $amount <= $remaining + self::MONEY_EPSILON
            : abs($amount - $remaining) < self::MONEY_EPSILON;
    }
}

/**
 * Production LeftoverVoucherGateway on PrestaShop's database. Reads are uncached:
 * validateOrder wrote these rows in this same request.
 */
class PsLeftoverVoucherGateway implements LeftoverVoucherGateway
{
    public function order(int $orderId): ?array
    {
        $db = Db::getInstance();
        $rows = $db->executeS('SELECT `id_customer`, `date_add`, `id_currency` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . (int) $orderId, true, false);
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $rules = $db->executeS('SELECT `id_cart_rule`, `value`, `value_tax_excl` FROM `' . _DB_PREFIX_ . 'order_cart_rule` WHERE `id_order` = ' . (int) $orderId . ' ORDER BY `id_order_cart_rule` ASC', true, false);

        return [
            'id_customer' => (int) $rows[0]['id_customer'],
            'date_add' => (string) $rows[0]['date_add'],
            'id_currency' => (int) $rows[0]['id_currency'],
            'cart_rules' => array_map(static fn (array $row): array => [
                'id_cart_rule' => (int) $row['id_cart_rule'],
                'value' => (float) $row['value'],
                'value_tax_excl' => (float) $row['value_tax_excl'],
            ], is_array($rules) ? $rules : []),
        ];
    }

    public function cartRule(int $cartRuleId): ?array
    {
        $rows = Db::getInstance()->executeS($this->ruleSelect() . ' WHERE `id_cart_rule` = ' . (int) $cartRuleId, true, false);

        return is_array($rows) && $rows !== [] ? $this->ruleRow($rows[0]) : null;
    }

    public function rulesByCode(string $code, int $afterId, string $since): array
    {
        $rows = Db::getInstance()->executeS(
            $this->ruleSelect() . " WHERE `code` = '" . pSQL($code) . "' AND `id_cart_rule` > " . (int) $afterId
            . " AND `date_add` >= '" . pSQL($since) . "' ORDER BY `id_cart_rule` ASC",
            true,
            false
        );

        return is_array($rows) ? array_map(fn (array $row): array => $this->ruleRow($row), $rows) : [];
    }

    public function makePublic(int $cartRuleId, string $dateTo): void
    {
        $updated = Db::getInstance()->update('cart_rule', [
            'id_customer' => 0,
            'date_to' => pSQL($dateTo),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_cart_rule` = ' . (int) $cartRuleId);
        if (!$updated) {
            throw new RuntimeException('Leftover voucher could not be made public');
        }
    }

    private function ruleSelect(): string
    {
        return 'SELECT `id_cart_rule`, `code`, `reduction_amount`, `reduction_tax`, `reduction_currency`, `partial_use`, `free_shipping`, `date_to`, `id_customer`, `date_add` FROM `' . _DB_PREFIX_ . 'cart_rule`';
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function ruleRow(array $row): array
    {
        return [
            'id_cart_rule' => (int) $row['id_cart_rule'],
            'code' => (string) $row['code'],
            'reduction_amount' => (float) $row['reduction_amount'],
            'reduction_tax' => (int) $row['reduction_tax'],
            'reduction_currency' => (int) $row['reduction_currency'],
            'partial_use' => (int) $row['partial_use'],
            'free_shipping' => (int) $row['free_shipping'],
            'date_to' => (string) $row['date_to'],
            'id_customer' => (int) $row['id_customer'],
            'date_add' => (string) $row['date_add'],
        ];
    }
}
