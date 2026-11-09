<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Provisioning;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\FreeItemSettings;
use VenPosTPV\Service\Checkout\FreeItemSettingsStore;
use VenPosTPV\Service\Checkout\FreeItemSetupService;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Provisioning\FreeItemProductsStep;
use VenPosTPV\Service\Provisioning\ProvisioningException;

/**
 * The free item generic products (docs/prd/articulo-libre.md, A5.2) as a
 * provisioning step: done when free_item.ps_products has every configured rate with
 * all its slots, otherwise tpv:free-item:setup runs.
 */
final class FreeItemProductsStepTest extends TestCase
{
    /** @return array{id_product: int, slots: array<int, int>} */
    private static function entry(int $product, int $slots = 20): array
    {
        return ['id_product' => $product, 'slots' => array_combine(range(1, $slots), range($product * 100 + 1, $product * 100 + $slots))];
    }

    /** @param array<string, array{id_product: int, slots: array<int, int>}> $stored */
    private function step(array $stored, ?PsOrderClient $client = null): FreeItemProductsStep
    {
        $store = $this->createMock(FreeItemSettingsStore::class);
        $store->method('load')->willReturn(new FreeItemSettings(true, 'all', 1000.0, [21.0, 10.0]));
        $store->method('loadPsProducts')->willReturn($stored);

        return new FreeItemProductsStep(new FreeItemSetupService($client ?? $this->createMock(PsOrderClient::class), $store), $store);
    }

    public function testItIsDoneWhenEveryConfiguredRateHasItsProductAndAllItsSlots(): void
    {
        $this->assertTrue($this->step(['21.00' => self::entry(22), '10.00' => self::entry(23)])->isDone());
        $this->assertSame('free-item-products', $this->step([])->id());
        $this->assertSame('Productos genéricos del artículo libre en PrestaShop', $this->step([])->description());
    }

    public function testItIsPendingBeforeTheSetupOrWhenARateOrASlotIsMissing(): void
    {
        $this->assertFalse($this->step([])->isDone(), 'never set up');
        $this->assertFalse($this->step(['21.00' => self::entry(22)])->isDone(), 'a VAT rate added later');
        $this->assertFalse($this->step(['21.00' => self::entry(22), '10.00' => self::entry(23, 19)])->isDone(), 'a slot missing');
    }

    public function testRunningItAsksTheModuleAndStoresTheProducts(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->once())->method('setupFreeItemProducts')->with([21, 10])->willReturn(['products' => [
            '21.00' => self::entry(22), '10.00' => self::entry(23),
        ]]);
        $store = $this->createMock(FreeItemSettingsStore::class);
        $store->method('load')->willReturn(new FreeItemSettings(true, 'all', 1000.0, [21.0, 10.0]));
        $store->expects($this->once())->method('savePsProducts');

        (new FreeItemProductsStep(new FreeItemSetupService($client, $store), $store))->run();
    }

    public function testAShopThatCannotSetItUpGivesTheReasonInSpanish(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->method('setupFreeItemProducts')->willThrowException(CheckoutException::psUnavailable('PS module returned HTTP 404: Not Found.'));

        try {
            $this->step([], $client)->run();
            self::fail('A failed setup must be reported.');
        } catch (ProvisioningException $e) {
            $this->assertStringStartsWith('PrestaShop no ha preparado los productos del artículo libre: ', $e->getMessage());
            $this->assertStringContainsString('PS module returned HTTP 404: Not Found. Comprueba', $e->getMessage());
            $this->assertStringContainsString('TPV Connect 0.7.0', $e->getMessage());
        }
    }

    public function testWithTheFreeItemDisabledThereIsNothingToProvisionAndPrestaShopIsNeverCalled(): void
    {
        $client = $this->createMock(PsOrderClient::class);
        $client->expects($this->never())->method($this->anything());
        $store = $this->createMock(FreeItemSettingsStore::class);
        $store->method('load')->willReturn(new FreeItemSettings(false, 'all', 1000.0, [21.0, 10.0]));
        $store->method('loadPsProducts')->willReturn([]);
        $store->expects($this->never())->method('savePsProducts');
        $step = new FreeItemProductsStep(new FreeItemSetupService($client, $store), $store);

        $this->assertTrue($step->isDone(), 'free_item.enabled = 0 counts as done');
        $step->run();
    }
}
