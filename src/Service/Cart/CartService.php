<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Cart;

class CartService
{
    private const SESSION_KEY = 'cart';
    private const IVA_RATE = 0.21;

    /**
     * @return array<string, mixed>
     */
    public function getCart(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $cart = $_SESSION[self::SESSION_KEY] ?? [
            'lines' => [],
            'discount' => null,
            'customer' => null,
        ];

        return $this->enrichCart($cart);
    }

    /**
     * @param array<string, mixed> $cart
     *
     * @return array<string, mixed>
     */
    private function enrichCart(array $cart): array
    {
        $lines = $cart['lines'] ?? [];
        $discount = $cart['discount'] ?? null;

        $subtotal = 0.0;
        foreach ($lines as $line) {
            $linePrice = (float) ($line['price'] ?? 0);
            $qty = (int) ($line['qty'] ?? 1);
            $lineDiscount = (float) ($line['discount'] ?? 0);
            $subtotal += $linePrice * $qty * (1 - $lineDiscount / 100);
        }

        $discountAmt = 0.0;
        if ($discount !== null) {
            $discountValue = (float) ($discount['value'] ?? 0);
            if (($discount['type'] ?? '') === 'pct') {
                $discountAmt = $subtotal * $discountValue / 100;
            } else {
                $discountAmt = min($discountValue, $subtotal);
            }
        }

        $total = max(0.0, $subtotal - $discountAmt);
        $iva = $total * self::IVA_RATE / (1 + self::IVA_RATE);

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'discount_amount' => round($discountAmt, 2),
            'iva' => round($iva, 2),
            'total' => round($total, 2),
            'customer' => $cart['customer'] ?? null,
        ];
    }

    private function saveCart(array $cart): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::SESSION_KEY] = $cart;
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    public function addLine(array $product, int $qty = 1): array
    {
        $cart = $this->getCart();
        $lines = $cart['lines'] ?? [];

        $existingIndex = null;
        foreach ($lines as $i => $line) {
            if ((int) $line['product_id'] === (int) $product['id']) {
                $existingIndex = $i;
                break;
            }
        }

        if ($existingIndex !== null) {
            $lines[$existingIndex]['qty'] = (int) $lines[$existingIndex]['qty'] + $qty;
        } else {
            $lines[] = [
                'id' => uniqid('line_', true),
                'product_id' => (int) $product['id'],
                'name' => (string) ($product['name'] ?? ''),
                'reference' => (string) ($product['reference'] ?? ''),
                'price' => (float) ($product['price'] ?? 0),
                'qty' => max(1, $qty),
                'discount' => 0,
            ];
        }

        $cart['lines'] = $lines;
        $this->saveCart($cart);

        return $this->enrichCart($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLine(string $lineId): array
    {
        $cart = $this->getCart();
        $lines = $cart['lines'] ?? [];
        $lines = array_values(array_filter($lines, fn (array $line): bool => $line['id'] !== $lineId));
        $cart['lines'] = $lines;
        $this->saveCart($cart);

        return $this->enrichCart($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateQty(string $lineId, int $qty): array
    {
        $cart = $this->getCart();
        $lines = $cart['lines'] ?? [];

        foreach ($lines as $i => $line) {
            if ($line['id'] === $lineId) {
                if ($qty <= 0) {
                    unset($lines[$i]);
                } else {
                    $lines[$i]['qty'] = $qty;
                }
                break;
            }
        }

        $cart['lines'] = array_values($lines);
        $this->saveCart($cart);

        return $this->enrichCart($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(): array
    {
        $cart = [
            'lines' => [],
            'discount' => null,
            'customer' => null,
        ];
        $this->saveCart($cart);

        return $this->enrichCart($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function applyDiscount(string $type, float $value): array
    {
        $cart = $this->getCart();
        $cart['discount'] = ['type' => $type, 'value' => $value];
        $this->saveCart($cart);

        return $this->enrichCart($cart);
    }

    /**
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    public function setCustomer(array $customer): array
    {
        $cart = $this->getCart();
        $cart['customer'] = $customer;
        $this->saveCart($cart);

        return $this->enrichCart($cart);
    }
}
