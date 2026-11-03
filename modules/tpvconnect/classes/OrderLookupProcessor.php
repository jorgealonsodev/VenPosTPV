<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/RefundProcessor.php';

/**
 * The PrestaShop reads an order lookup needs, so the rules can be tested without
 * a shop. PsOrderLookupGateway is the production implementation.
 */
interface OrderLookupGateway
{
    /** @return array<int, int> ids of the orders with this reference, ascending */
    public function findOrderIdsByReference(string $reference): array;

    /**
     * @return array{id: int, reference: string, date_add: string, customer_name: string, module: string, current_state: array{id: int, name: string}, total_discounts_tax_incl: float, shipping_discount_tax_incl: float, history: array<int, array{id_order_state: int, date_add: string, delivery: bool}>, details: array<int, array{id_order_detail: int, product_id: int, product_attribute_id: int, product_name: string, product_quantity: int, slipped_quantity: int, total_price_tax_incl: float, unit_price_tax_incl: float}>}|null
     */
    public function loadOrder(int $orderId): ?array;
}

/**
 * Describes a PrestaShop order to the TPV so it can refund or exchange an online
 * order: what each line actually cost the customer, how much of it is still
 * refundable, and when it was delivered (the refund window counts from there).
 *
 * The paid amount of a line is its tax-included total minus its share of the
 * order-level discounts, prorated by line total in cents; the heaviest line takes
 * the rounding remainder so the lines add up exactly to what was paid for products.
 * A free-shipping discount is not charged to the products.
 */
class OrderLookupProcessor
{
    public const TPV_MODULE = 'tpvconnect';

    public function __construct(private readonly OrderLookupGateway $gateway)
    {
    }

    /**
     * Constant-time check of "Authorization: HMAC-SHA256 <hex>" over the raw body,
     * the same scheme as the other TPV endpoints. An empty secret never matches.
     */
    public static function verifySignature(string $rawBody, string $secret, string $authHeader): bool
    {
        if ($secret === '' || strpos($authHeader, 'HMAC-SHA256 ') !== 0) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($authHeader, strlen('HMAC-SHA256 ')));
    }

    /**
     * @param array<string, mixed> $data {id_order: int} or {reference: string}
     *
     * @return array{orders: array<int, array<string, mixed>>}
     *
     * @throws RefundRequestException 422 invalid input, 404 no such order
     */
    public function process(array $data): array
    {
        $ids = $this->resolveIds($data);

        $orders = [];
        foreach ($ids as $id) {
            $order = $this->gateway->loadOrder($id);
            if ($order !== null) {
                $orders[] = $this->describe($order);
            }
        }
        if ($orders === []) {
            throw new RefundRequestException(404, 'Order not found');
        }

        return ['orders' => $orders];
    }

    /** @return array<int, int> */
    private function resolveIds(array $data): array
    {
        if (array_key_exists('id_order', $data)) {
            if (!is_int($data['id_order']) || $data['id_order'] <= 0) {
                throw new RefundRequestException(422, 'Validation failed', ['id_order' => 'Required positive integer']);
            }

            return [$data['id_order']];
        }

        $reference = $data['reference'] ?? null;
        if (!is_string($reference) || !preg_match('/^[A-Za-z0-9]{1,32}$/', $reference)) {
            throw new RefundRequestException(422, 'Validation failed', ['reference' => 'Send id_order or a reference of 1-32 letters or digits']);
        }

        return $this->gateway->findOrderIdsByReference(strtoupper($reference));
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function describe(array $order): array
    {
        $details = $order['details'];
        usort($details, static fn (array $a, array $b): int => (int) $a['id_order_detail'] <=> (int) $b['id_order_detail']);

        $paid = $this->paidTotals($details, (float) $order['total_discounts_tax_incl'], (float) $order['shipping_discount_tax_incl']);

        $lines = [];
        foreach ($details as $index => $detail) {
            $lines[] = [
                'id_order_detail' => (int) $detail['id_order_detail'],
                'product_id' => (int) $detail['product_id'],
                'product_attribute_id' => (int) $detail['product_attribute_id'],
                'name' => (string) $detail['product_name'],
                'quantity' => (int) $detail['product_quantity'],
                'refundable_quantity' => max(0, (int) $detail['product_quantity'] - (int) $detail['slipped_quantity']),
                'paid_total' => $paid[$index],
                'unit_price_tax_incl' => round((float) $detail['unit_price_tax_incl'], 2),
            ];
        }

        return [
            'id_order' => (int) $order['id'],
            'reference' => (string) $order['reference'],
            'date_add' => (string) $order['date_add'],
            'customer_name' => (string) $order['customer_name'],
            'created_by_tpv' => (string) $order['module'] === self::TPV_MODULE,
            'current_state' => ['id' => (int) $order['current_state']['id'], 'name' => (string) $order['current_state']['name']],
            'delivered_at' => $this->deliveredAt($order['history']),
            'lines' => $lines,
        ];
    }

    /**
     * @param array<int, array{id_order_state: int, date_add: string, delivery: bool}> $history
     */
    private function deliveredAt(array $history): ?string
    {
        $first = null;
        foreach ($history as $entry) {
            if (!empty($entry['delivery']) && ($first === null || strcmp((string) $entry['date_add'], $first) < 0)) {
                $first = (string) $entry['date_add'];
            }
        }

        return $first;
    }

    /**
     * @param array<int, array<string, mixed>> $details
     *
     * @return array<int, float> paid total per detail, same keys
     */
    private function paidTotals(array $details, float $discounts, float $shippingDiscount): array
    {
        $totals = array_map(static fn (array $d): int => (int) round((float) $d['total_price_tax_incl'] * 100), $details);
        $weight = array_sum($totals);
        if ($weight <= 0) {
            return array_map(static fn (int $cents): float => $cents / 100, $totals);
        }

        $discount = min($weight, max(0, (int) round(($discounts - $shippingDiscount) * 100)));

        $heaviest = array_keys($totals, max($totals), true)[0];
        $shares = [];
        foreach ($totals as $index => $cents) {
            if ($index !== $heaviest) {
                $shares[$index] = (int) round($discount * $cents / $weight);
            }
        }
        $shares[$heaviest] = $discount - array_sum($shares);

        $paid = [];
        foreach ($totals as $index => $cents) {
            $paid[$index] = round(($cents - $shares[$index]) / 100, 2);
        }

        return $paid;
    }
}

/**
 * Production OrderLookupGateway on top of PrestaShop core classes.
 */
class PsOrderLookupGateway implements OrderLookupGateway
{
    public function findOrderIdsByReference(string $reference): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . "orders` WHERE `reference` = '" . pSQL($reference) . "' ORDER BY `id_order`",
            true,
            false
        );

        return is_array($rows) ? array_map(static fn (array $row): int => (int) $row['id_order'], $rows) : [];
    }

    public function loadOrder(int $orderId): ?array
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }

        $idLang = (int) (Context::getContext()->language->id ?? Configuration::get('PS_LANG_DEFAULT'));
        $customer = new Customer((int) $order->id_customer);
        $state = new OrderState((int) $order->current_state, $idLang);

        $history = [];
        $rows = Db::getInstance()->executeS(
            'SELECT oh.`id_order_state`, oh.`date_add`, os.`delivery`
             FROM `' . _DB_PREFIX_ . 'order_history` oh
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.`id_order_state` = oh.`id_order_state`
             WHERE oh.`id_order` = ' . (int) $orderId,
            true,
            false
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $history[] = ['id_order_state' => (int) $row['id_order_state'], 'date_add' => (string) $row['date_add'], 'delivery' => (int) $row['delivery'] === 1];
        }

        // A free-shipping rule's value covers the shipping, which is not a product.
        $freeShipping = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_cart_rule` WHERE `id_order` = ' . (int) $orderId . ' AND `free_shipping` = 1',
            false
        ) > 0;
        $shippingDiscount = $freeShipping ? min((float) $order->total_shipping_tax_incl, (float) $order->total_discounts_tax_incl) : 0.0;

        $details = [];
        foreach (OrderDetail::getList($orderId) as $row) {
            $resume = OrderSlip::getProductSlipResume((int) $row['id_order_detail']);
            $details[] = [
                'id_order_detail' => (int) $row['id_order_detail'],
                'product_id' => (int) $row['product_id'],
                'product_attribute_id' => (int) $row['product_attribute_id'],
                'product_name' => (string) $row['product_name'],
                'product_quantity' => (int) $row['product_quantity'],
                'slipped_quantity' => (int) ($resume['product_quantity'] ?? 0),
                'total_price_tax_incl' => (float) $row['total_price_tax_incl'],
                'unit_price_tax_incl' => (float) $row['unit_price_tax_incl'],
            ];
        }

        return [
            'id' => $orderId,
            'reference' => (string) $order->reference,
            'date_add' => (string) $order->date_add,
            'customer_name' => Validate::isLoadedObject($customer) ? trim($customer->firstname . ' ' . $customer->lastname) : '',
            'module' => (string) $order->module,
            'current_state' => [
                'id' => (int) $order->current_state,
                'name' => Validate::isLoadedObject($state) ? (string) (is_array($state->name) ? reset($state->name) : $state->name) : '',
            ],
            'total_discounts_tax_incl' => (float) $order->total_discounts_tax_incl,
            'shipping_discount_tax_incl' => $shippingDiscount,
            'history' => $history,
            'details' => $details,
        ];
    }
}
