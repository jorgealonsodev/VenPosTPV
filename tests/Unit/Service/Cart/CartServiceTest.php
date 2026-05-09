<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Cart;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Cart\CartService;

class CartServiceTest extends TestCase
{
    private CartService $service;

    protected function setUp(): void
    {
        // Ensure clean session state
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        $this->service = new CartService();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGetCartReturnsEmptyCartByDefault(): void
    {
        $cart = $this->service->getCart();

        $this->assertEmpty($cart['lines']);
        $this->assertSame(0.0, $cart['subtotal']);
        $this->assertSame(0.0, $cart['total']);
        $this->assertSame(0.0, $cart['iva']);
        $this->assertNull($cart['discount']);
    }

    public function testAddLineCreatesNewLine(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 10.00];
        $cart = $this->service->addLine($product);

        $this->assertCount(1, $cart['lines']);
        $this->assertSame('Test Product', $cart['lines'][0]['name']);
        $this->assertSame(1, $cart['lines'][0]['qty']);
        $this->assertSame(10.0, $cart['subtotal']);
    }

    public function testAddLineIncrementsQtyForExistingProduct(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 10.00];
        $this->service->addLine($product);
        $cart = $this->service->addLine($product);

        $this->assertCount(1, $cart['lines']);
        $this->assertSame(2, $cart['lines'][0]['qty']);
        $this->assertSame(20.0, $cart['subtotal']);
    }

    public function testAddLineWithCustomQuantity(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 10.00];
        $cart = $this->service->addLine($product, 3);

        $this->assertSame(3, $cart['lines'][0]['qty']);
        $this->assertSame(30.0, $cart['subtotal']);
    }

    public function testRemoveLineRemovesCorrectLine(): void
    {
        $product1 = ['id' => 1, 'name' => 'Product A', 'price' => 10.00];
        $product2 = ['id' => 2, 'name' => 'Product B', 'price' => 20.00];
        $this->service->addLine($product1);
        $this->service->addLine($product2);

        $lineId = $_SESSION['cart']['lines'][0]['id'];
        $cart = $this->service->removeLine($lineId);

        $this->assertCount(1, $cart['lines']);
        $this->assertSame('Product B', $cart['lines'][0]['name']);
    }

    public function testUpdateQtyIncreasesQuantity(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 10.00];
        $this->service->addLine($product);
        $lineId = $_SESSION['cart']['lines'][0]['id'];

        $cart = $this->service->updateQty($lineId, 5);

        $this->assertSame(5, $cart['lines'][0]['qty']);
        $this->assertSame(50.0, $cart['subtotal']);
    }

    public function testUpdateQtyToZeroRemovesLine(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 10.00];
        $this->service->addLine($product);
        $lineId = $_SESSION['cart']['lines'][0]['id'];

        $cart = $this->service->updateQty($lineId, 0);

        $this->assertEmpty($cart['lines']);
    }

    public function testClearEmptiesCart(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 10.00];
        $this->service->addLine($product);
        $this->service->applyDiscount('pct', 10);

        $cart = $this->service->clear();

        $this->assertEmpty($cart['lines']);
        $this->assertNull($cart['discount']);
        $this->assertSame(0.0, $cart['subtotal']);
    }

    public function testApplyPercentageDiscount(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 100.00];
        $this->service->addLine($product, 1);
        $cart = $this->service->applyDiscount('pct', 10);

        $this->assertSame(10.0, $cart['discount_amount']);
        $this->assertSame(90.0, $cart['total']);
    }

    public function testApplyAmountDiscount(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 100.00];
        $this->service->addLine($product, 1);
        $cart = $this->service->applyDiscount('amt', 25);

        $this->assertSame(25.0, $cart['discount_amount']);
        $this->assertSame(75.0, $cart['total']);
    }

    public function testDiscountAmountDoesNotExceedSubtotal(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 10.00];
        $this->service->addLine($product, 1);
        $cart = $this->service->applyDiscount('amt', 50);

        $this->assertSame(10.0, $cart['discount_amount']);
        $this->assertSame(0.0, $cart['total']);
    }

    public function testIvaCalculationIsCorrect(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 121.00];
        $cart = $this->service->addLine($product, 1);

        // 121 total with 21% IVA => 21 IVA, 100 base
        $this->assertSame(121.0, $cart['total']);
        $this->assertEqualsWithDelta(21.0, $cart['iva'], 0.01);
    }

    public function testSetCustomerPersistsToCart(): void
    {
        $customer = ['id' => 5, 'name' => 'Juan Pérez'];
        $cart = $this->service->setCustomer($customer);

        $this->assertSame('Juan Pérez', $cart['customer']['name']);
    }

    public function testLineDiscountIsAppliedInSubtotal(): void
    {
        $product = ['id' => 1, 'name' => 'Test Product', 'price' => 100.00];
        $this->service->addLine($product, 1);
        $_SESSION['cart']['lines'][0]['discount'] = 10;

        $cart = $this->service->getCart();

        $this->assertSame(90.0, $cart['subtotal']);
    }
}
