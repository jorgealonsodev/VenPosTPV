<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\PrestaShop;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Coupon\IssuedCouponStore;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;

final class CouponCreationServiceTest extends TestCase
{
    private const BLANK_SCHEMA = '<?xml version="1.0"?><prestashop><cart_rule><name><language id="2"></language></name></cart_rule></prestashop>';

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        return ['discount_type' => 'amount', 'value' => 12.5, 'expires_at' => '2030-06-30', 'quantity' => 10, 'quantity_per_user' => 2];
    }

    public function testCreatesAmountCouponWithSchemaLanguageAndScopedPost(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');
            if ($method === 'GET') {
                return new MockResponse(self::BLANK_SCHEMA);
            }
            return new MockResponse('<?xml version="1.0"?><prestashop><cart_rule><id>42</id><code>' . $this->codeFromBody($options['body']) . '</code></cart_rule></prestashop>', ['http_code' => 201]);
        });
        $service = new CouponCreationService('https://shop.test/prestashop/api', 'key', $client, 2, 'Europe/Madrid');

        $result = $service->create($this->validInput(), 3);

        self::assertSame(42, $result['id']);
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $result['code']);
        self::assertSame('amount', $result['type']);
        self::assertCount(2, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertStringContainsString('/api/cart_rules?schema=blank&id_shop=3', $requests[0]['url']);
        self::assertSame('POST', $requests[1]['method']);
        self::assertStringContainsString('/api/cart_rules?id_shop=3', $requests[1]['url']);
        self::assertStringContainsString('<reduction_currency>2</reduction_currency>', $requests[1]['options']['body']);
        self::assertStringNotContainsString('<id_shop>', $requests[1]['options']['body']);
        self::assertStringNotContainsString('<id_currency>', $requests[1]['options']['body']);
        self::assertStringContainsString('<reduction_amount>12.50</reduction_amount>', $requests[1]['options']['body']);
        self::assertStringContainsString('<reduction_percent>0</reduction_percent>', $requests[1]['options']['body']);
        self::assertStringContainsString('<date_to>2030-06-30 23:59:59</date_to>', $requests[1]['options']['body']);
        self::assertStringContainsString('<language id="2">', $requests[1]['options']['body']);
    }

    public function testCreatesPercentageCouponWithNativePercentField(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');
            if ($method === 'GET') {
                return new MockResponse(self::BLANK_SCHEMA);
            }
            return new MockResponse('<?xml version="1.0"?><prestashop><cart_rule><id>43</id><code>' . $this->codeFromBody($options['body']) . '</code></cart_rule></prestashop>', ['http_code' => 201]);
        });
        $service = new CouponCreationService('https://shop.test', 'key', $client, 2, 'Europe/Madrid');
        $input = $this->validInput();
        $input['discount_type'] = 'percentage';
        $input['value'] = 15.5;

        self::assertSame(43, $service->create($input, 3)['id']);
        self::assertStringContainsString('<reduction_percent>15.5</reduction_percent>', $requests[1]['options']['body']);
        self::assertStringContainsString('<reduction_amount>0</reduction_amount>', $requests[1]['options']['body']);
    }

    public function testParsesNativeCdataCreationResponseWithoutClaimingFailure(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            if ($method === 'GET') {
                return new MockResponse(self::BLANK_SCHEMA);
            }
            preg_match('/<code>([^<]+)<\/code>/', $options['body'], $matches);
            $code = $matches[1] ?? '';
            $native = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<prestashop xmlns:xlink=\"http://www.w3.org/1999/xlink\">\n<cart_rule>\n  <id><![CDATA[2]]></id>\n  <code><![CDATA[" . $code . "]]></code>\n</cart_rule>\n</prestashop>\n";
            return new MockResponse($native, ['http_code' => 201]);
        });
        $service = new CouponCreationService('https://shop.test', 'key', $client, 2, 'Europe/Madrid');

        $result = $service->create($this->validInput(), 3);

        self::assertSame(2, $result['id']);
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $result['code']);
        self::assertSame(2, $requests);
    }

    public function testRejectsInvalidInputBeforeAnyTransport(): void
    {
        $requests = 0;
        $service = new CouponCreationService('https://shop.test', 'key', new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse('');
        }), 2, 'Europe/Madrid');
        $input = $this->validInput();
        $input['quantity_per_user'] = 11;

        try {
            $service->create($input, 3);
            self::fail('Expected validation failure');
        } catch (CouponCreationException $e) {
            self::assertSame(422, $e->getCode());
        }
        self::assertSame(0, $requests);
    }

    public function testTreatsLostCreateResponseAsAmbiguousWithoutRetry(): void
    {
        $requests = 0;
        $service = new CouponCreationService('https://shop.test', 'key', new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;
            return $requests === 1 ? new MockResponse(self::BLANK_SCHEMA) : new MockResponse('', ['error' => 'connection reset']);
        }), 2, 'Europe/Madrid');

        try {
            $service->create($this->validInput(), 3);
            self::fail('Expected ambiguous creation');
        } catch (CouponCreationException $e) {
            self::assertSame(409, $e->getCode());
        }
        self::assertSame(2, $requests);
    }

    /** @return array{0: CouponCreationService, 1: \ArrayObject<int, string>} service and the POST bodies it sent */
    private function capturingService(): array
    {
        $bodies = new \ArrayObject();
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($bodies): MockResponse {
            if ($method === 'GET') {
                return new MockResponse(self::BLANK_SCHEMA);
            }
            $bodies[] = $options['body'];

            return new MockResponse('<?xml version="1.0"?><prestashop><cart_rule><id>44</id><code>' . $this->codeFromBody($options['body']) . '</code></cart_rule></prestashop>', ['http_code' => 201]);
        });

        return [new CouponCreationService('https://shop.test', 'key', $client, 2, 'Europe/Madrid'), $bodies];
    }

    public function testCouponsAreSingleUseUnlessPartialUseIsAsked(): void
    {
        [$service, $bodies] = $this->capturingService();

        $result = $service->create($this->validInput(), 3);

        self::assertStringContainsString('<partial_use>0</partial_use>', $bodies[0]);
        self::assertArrayNotHasKey('partial_use', $result, 'the result shape of existing callers is unchanged');
    }

    public function testPartialUseKeepsTheRemainingBalanceInPrestaShop(): void
    {
        [$service, $bodies] = $this->capturingService();

        $service->create($this->validInput() + ['partial_use' => true], 3);

        self::assertStringContainsString('<partial_use>1</partial_use>', $bodies[0]);
    }

    public function testPartialUseMustBeABoolean(): void
    {
        [$service, $bodies] = $this->capturingService();

        try {
            $service->create($this->validInput() + ['partial_use' => 'yes'], 3);
            self::fail('Expected a validation error');
        } catch (CouponCreationException $e) {
            self::assertSame(422, $e->getCode());
        }
        self::assertCount(0, $bodies);
    }

    private function creatingClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($method === 'GET') {
                return new MockResponse(self::BLANK_SCHEMA);
            }
            return new MockResponse('<?xml version="1.0"?><prestashop><cart_rule><id>45</id><code>' . $this->codeFromBody($options['body']) . '</code></cart_rule></prestashop>', ['http_code' => 201]);
        });
    }

    public function testAnIssuedCouponIsRecordedLocallyWithItsTillAndCashier(): void
    {
        $store = $this->createMock(IssuedCouponStore::class);
        $store->expects(self::once())->method('record')
            ->with(45, self::matchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/'), 'percentage', 12.5, '2030-06-30', 10, 2, 4, 9)
            ->willReturn(31);
        $service = new CouponCreationService('https://shop.test', 'key', $this->creatingClient(), 2, 'Europe/Madrid', $store);

        $result = $service->createIssued(['discount_type' => 'percentage'] + $this->validInput(), 3, 4, 9);

        self::assertSame(45, $result['id']);
        self::assertSame(31, $result['local_id']);
        self::assertTrue($result['printable']);
    }

    /**
     * «entonces no tiene sentido que un vale de importe pueda usarse mas de una vez no?»:
     * an amount coupon from «Crear cupón» is a balance voucher, used once and keeping
     * its rest, whatever uses were sent.
     */
    public function testAnIssuedAmountCouponIsABalanceVoucherUsedOnceThatKeepsItsRest(): void
    {
        [$service, $bodies] = $this->capturingService();

        $result = $service->createIssued($this->validInput() + ['partial_use' => false], 3, 4, 9);

        self::assertStringContainsString('<quantity>1</quantity><quantity_per_user>1</quantity_per_user>', $bodies[0]);
        self::assertStringContainsString('<partial_use>1</partial_use>', $bodies[0]);
        self::assertSame(1, $result['quantity']);
        self::assertSame(1, $result['quantity_per_user']);
    }

    public function testAnIssuedPercentageCouponKeepsItsUsesAndIsSingleUse(): void
    {
        [$service, $bodies] = $this->capturingService();

        $result = $service->createIssued(['discount_type' => 'percentage'] + $this->validInput(), 3, 4, 9);

        self::assertStringContainsString('<quantity>10</quantity><quantity_per_user>2</quantity_per_user>', $bodies[0]);
        self::assertStringContainsString('<partial_use>0</partial_use>', $bodies[0]);
        self::assertSame(10, $result['quantity']);
        self::assertSame(2, $result['quantity_per_user']);
    }

    public function testAnIssuedAmountCouponStoresItsSingleUseLocally(): void
    {
        $store = $this->createMock(IssuedCouponStore::class);
        $store->expects(self::once())->method('record')
            ->with(45, self::anything(), 'amount', 12.5, '2030-06-30', 1, 1, 4, 9)
            ->willReturn(32);
        $service = new CouponCreationService('https://shop.test', 'key', $this->creatingClient(), 2, 'Europe/Madrid', $store);

        self::assertSame(32, $service->createIssued($this->validInput(), 3, 4, 9)['local_id']);
    }

    public function testACouponCreatedInPrestaShopIsStillReturnedWhenTheLocalCopyFails(): void
    {
        $store = $this->createMock(IssuedCouponStore::class);
        $store->method('record')->willReturn(null);
        $service = new CouponCreationService('https://shop.test', 'key', $this->creatingClient(), 2, 'Europe/Madrid', $store);

        $result = $service->createIssued($this->validInput(), 3, 4, 9);

        self::assertSame(45, $result['id']);
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $result['code']);
        self::assertNull($result['local_id']);
        self::assertFalse($result['printable']);
    }

    public function testPlainCreateDoesNotRecordTheCoupon(): void
    {
        $store = $this->createMock(IssuedCouponStore::class);
        $store->expects(self::never())->method('record');
        $service = new CouponCreationService('https://shop.test', 'key', $this->creatingClient(), 2, 'Europe/Madrid', $store);

        $result = $service->create($this->validInput(), 3);

        self::assertSame(45, $result['id']);
        self::assertArrayNotHasKey('local_id', $result);
    }

    private function codeFromBody(string $body): string
    {
        preg_match('/<code>([^<]+)<\/code>/', $body, $matches);
        return $matches[1] ?? '';
    }
}
