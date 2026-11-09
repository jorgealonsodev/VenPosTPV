<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\FreeItemSettings;
use VenPosTPV\Service\Checkout\FreeItemSettingsStore;
use VenPosTPV\Service\Checkout\FreeItemSetupService;
use VenPosTPV\Service\Checkout\PsOrderClient;

/**
 * tpv:free-item:setup (docs/prd/articulo-libre.md, A5.2): asks the module for the
 * generic products of the configured VAT rates and stores their ids.
 */
final class FreeItemSetupServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function answer(array $rates = ['21.00' => 22, '10.00' => 23], int $slots = 20): array
    {
        $products = [];
        foreach ($rates as $rate => $product) {
            $products[$rate] = ['id_product' => $product, 'slots' => array_combine(array_map('strval', range(1, $slots)), range($product * 100 + 1, $product * 100 + $slots))];
        }

        return ['products' => $products];
    }

    private function store(?array &$saved): FreeItemSettingsStore
    {
        $store = $this->createMock(FreeItemSettingsStore::class);
        $store->method('load')->willReturn(new FreeItemSettings(true, 'all', 1000.0, [21.0, 10.0]));
        $store->method('savePsProducts')->willReturnCallback(static function (array $map) use (&$saved): void {
            $saved = $map;
        });

        return $store;
    }

    public function testTheModuleIsAskedSignedForTheConfiguredRatesAndTheIdsAreStored(): void
    {
        $request = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$request): MockResponse {
            $request = ['method' => $method, 'url' => $url, 'body' => (string) $options['body'], 'auth' => implode("\n", $options['headers'])];

            return new MockResponse((string) json_encode(self::answer()), ['http_code' => 200]);
        });
        $saved = null;

        $map = (new FreeItemSetupService(new PsOrderClient('http://shop.test/', 'test_secret', $http), $this->store($saved)))->run();

        $this->assertSame('POST', $request['method']);
        $this->assertSame('http://shop.test/module/tpvconnect/freeitem', $request['url']);
        $this->assertSame('{"vat_rates":[21,10]}', $request['body']);
        $this->assertStringContainsString('Authorization: HMAC-SHA256 ' . hash_hmac('sha256', '{"vat_rates":[21,10]}', 'test_secret'), $request['auth']);
        $this->assertSame(['21.00', '10.00'], array_keys($map));
        $this->assertSame(22, $map['21.00']['id_product']);
        $this->assertSame(range(1, 20), array_keys($map['21.00']['slots']));
        $this->assertSame(2220, $map['21.00']['slots'][20]);
        $this->assertSame($map, $saved);
    }

    public function testAnAnswerWithoutEveryRateOrSlotIsRefusedAndNothingIsStored(): void
    {
        foreach ([self::answer(['21.00' => 22]), self::answer(slots: 19), ['products' => 'x'], []] as $answer) {
            $saved = null;
            $client = new PsOrderClient('http://shop.test', 'test_secret', new MockHttpClient(new MockResponse((string) json_encode($answer), ['http_code' => 200])));
            try {
                (new FreeItemSetupService($client, $this->store($saved)))->run();
                $this->fail('Expected a refusal');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('PrestaShop did not return', $e->getMessage());
            }
            $this->assertNull($saved);
        }
    }

    public function testAModuleRefusalIsReportedWithItsDetails(): void
    {
        $client = new PsOrderClient('http://shop.test', 'test_secret', new MockHttpClient(new MockResponse(
            '{"error":"Validation failed","details":{"vat_rates":"No active tax rules group with 10.00 % for the default country"}}',
            ['http_code' => 422]
        )));
        $saved = null;
        try {
            (new FreeItemSetupService($client, $this->store($saved)))->run();
            $this->fail('Expected a refusal');
        } catch (CheckoutException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('No active tax rules group with 10.00 % for the default country', $e->getMessage());
        }
        $this->assertNull($saved);
    }
}
