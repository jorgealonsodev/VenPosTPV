<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use CouponCheckout;
use PHPUnit\Framework\TestCase;
use TpvOrderBuildException;

/**
 * T8 bug 3: a customer created at the till has no address. The temporary address
 * the cart needs had no dni, and with ES (need_identification_number = 1) PrestaShop
 * threw "La propiedad Address->dni está vacía." -> HTTP 500, retried forever.
 */
final class OrderCartAddressTest extends TestCase
{
    private const SECRET = 'coupon-test-secret-which-is-at-least-32-bytes';
    private const SPAIN = 6;
    private const FRANCE = 8;

    protected function setUp(): void
    {
        \CouponTestState::reset();
        \Configuration::updateValue('PS_COUNTRY_DEFAULT', self::SPAIN);
    }

    private function order(int $product = 1): array
    {
        return ['id_customer' => 42, 'id_currency' => 1, 'id_shop' => 1, 'payment_method' => 'Efectivo',
            'products' => [['id_product' => $product, 'id_product_attribute' => 0, 'quantity' => 1]]];
    }

    private function checkout(): CouponCheckout
    {
        return new CouponCheckout(self::SECRET, \Context::getContext());
    }

    public function test_customer_without_address_in_a_country_that_needs_an_id_gets_a_placeholder_dni(): void
    {
        \CouponTestState::$customerAddresses = [];
        \CouponTestState::$dniRequiredCountries = [self::SPAIN];

        $cart = $this->checkout()->buildCart($this->order());

        self::assertCount(1, \CouponTestState::$addedAddresses);
        $address = \CouponTestState::$addedAddresses[0];
        self::assertSame('TPV', $address->dni);
        self::assertTrue(\Validate::isDniLite($address->dni));
        self::assertSame(self::SPAIN, $address->id_country);
        self::assertSame(42, $address->id_customer);
        self::assertSame($address->id, $cart->id_address_delivery);
        self::assertSame($address->id, $cart->id_address_invoice);
    }

    public function test_customer_without_address_in_a_country_without_id_gets_no_dni(): void
    {
        \Configuration::updateValue('PS_COUNTRY_DEFAULT', self::FRANCE);
        \CouponTestState::$customerAddresses = [];
        \CouponTestState::$dniRequiredCountries = [self::SPAIN];

        $this->checkout()->buildCart($this->order());

        self::assertCount(1, \CouponTestState::$addedAddresses);
        self::assertNull(\CouponTestState::$addedAddresses[0]->dni, 'the temporary address is as before');
    }

    public function test_existing_address_is_reused_and_nothing_is_created(): void
    {
        \CouponTestState::$customerAddresses = [['id_address' => 77], ['id_address' => 78]];
        \CouponTestState::$dniRequiredCountries = [self::SPAIN];

        $cart = $this->checkout()->buildCart($this->order());

        self::assertSame([], \CouponTestState::$addedAddresses);
        self::assertSame(77, $cart->id_address_delivery);
        self::assertSame(77, $cart->id_address_invoice);
    }

    public function test_an_address_prestashop_would_reject_is_a_definitive_422_not_a_500(): void
    {
        \CouponTestState::$customerAddresses = [];
        \CouponTestState::$dniRequiredCountries = [self::SPAIN];
        $checkout = new class (self::SECRET, \Context::getContext()) extends CouponCheckout {
            protected function temporaryAddressDni(int $idCountry): ?string
            {
                return null;
            }
        };

        try {
            $checkout->buildCart($this->order());
            self::fail('An invalid temporary address must not be stored');
        } catch (TpvOrderBuildException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertStringContainsString('Address->dni', $e->getMessage());
        }
        self::assertSame([], \CouponTestState::$addedAddresses);
    }

    public function test_a_product_the_cart_refuses_is_a_definitive_422(): void
    {
        \CouponTestState::$unavailableProducts = [5];

        try {
            $this->checkout()->buildCart($this->order(5));
            self::fail('The cart must not be built without the product');
        } catch (TpvOrderBuildException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertStringContainsString('Product could not be added to cart', $e->getMessage());
        }
    }

    public function test_the_orders_endpoint_answers_422_for_order_build_failures(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../controllers/front/orders.php');

        self::assertMatchesRegularExpression(
            '/catch \(TpvOrderBuildException \$e\) \{\s*\$this->jsonResponse\(\[\'error\' => \'Order validation failed\', \'details\' => \$e->getMessage\(\)\], \$e->getHttpStatus\(\)\);/',
            $source
        );
        self::assertLessThan(strpos($source, 'catch (Exception $e)'), strpos($source, 'catch (TpvOrderBuildException $e)'), 'caught before the generic 500');
        self::assertStringContainsString("'Order creation failed', 'details' => \$e->getMessage()], 500", $source, 'other failures stay 500');
    }
}
