<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;
use VenPosTPV\Service\Checkout\PsOrderClient;

/**
 * PRD V5: a PrestaShop coupon is only accepted while PrestaShop answers. When it
 * cannot be reached the TPV says so (coupon_offline) and the sale goes on without
 * the coupon; an order request that may have reached PrestaShop stays a
 * reconciliation case, because the order could exist.
 */
final class PsOrderClientCouponOfflineTest extends TestCase
{
    private function client(MockResponse $response): PsOrderClient
    {
        return new PsOrderClient('http://localhost/prestashop', 'test_secret', new MockHttpClient([$response]));
    }

    /** @return array<string, mixed> */
    private function previewPayload(): array
    {
        return ['operation' => 'coupon_preview', 'coupon_code' => 'SAVE10', 'id_customer' => 0, 'id_shop' => 1, 'id_currency' => 1, 'products' => []];
    }

    private function catch(callable $call): CheckoutException
    {
        try {
            $call();
        } catch (CheckoutException $e) {
            return $e;
        }
        $this->fail('Expected a CheckoutException');
    }

    public function testPreviewThatCannotReachPrestaShopIsCouponOffline(): void
    {
        foreach (['Failed to connect to localhost port 80 after 0 ms: Couldn\'t connect to server', 'Idle timeout reached for "http://localhost/prestashop".'] as $error) {
            $e = $this->catch(fn () => $this->client(new MockResponse('', ['error' => $error]))->previewCoupon($this->previewPayload()));

            $this->assertTrue($e->isCouponOffline(), $error);
            $this->assertSame(503, $e->getCode());
            $this->assertSame('Sin conexión con PrestaShop: no se puede aplicar el cupón. Puedes cobrar sin él.', $e->getMessage());
        }
    }

    public function testPreviewAnsweredByAGatewayErrorIsCouponOffline(): void
    {
        $e = $this->catch(fn () => $this->client(new MockResponse('Bad gateway', ['http_code' => 502]))->previewCoupon($this->previewPayload()));

        $this->assertTrue($e->isCouponOffline());
    }

    public function testPreviewRejectedOrAnsweredByAnOlderModuleKeepsItsPreviousErrors(): void
    {
        $rejected = $this->catch(fn () => $this->client(new MockResponse('{}', ['http_code' => 422]))->previewCoupon($this->previewPayload()));
        $older = $this->catch(fn () => $this->client(new MockResponse('{}', ['http_code' => 404]))->previewCoupon($this->previewPayload()));

        $this->assertFalse($rejected->isCouponOffline());
        $this->assertSame(422, $rejected->getCode());
        $this->assertFalse($older->isCouponOffline());
        $this->assertSame(503, $older->getCode());
        $this->assertStringContainsString('deploy the compatible TPV Connect module', $older->getMessage());
    }

    public function testCouponOrderThatNeverReachedPrestaShopIsCouponOffline(): void
    {
        foreach ([
            'Could not resolve host: shop.example',
            'Failed to connect to localhost port 80 after 0 ms: Connection refused',
            'Connection timed out after 30001 milliseconds',
        ] as $error) {
            $e = $this->catch(fn () => $this->client(new MockResponse('', ['error' => $error]))->createCouponOrder(['coupon' => []]));

            $this->assertTrue($e->isCouponOffline(), $error);
        }
    }

    public function testCouponOrderThatMayHaveReachedPrestaShopStillNeedsReconciliation(): void
    {
        $timedOut = $this->catch(fn () => $this->client(new MockResponse('', ['error' => 'Operation timed out after 30000 milliseconds with 0 bytes received']))->createCouponOrder(['coupon' => []]));
        $connected = $this->catch(fn () => $this->client(new MockResponse('', ['error' => 'Failed to connect to proxy', 'connect_time' => 0.012]))->createCouponOrder(['coupon' => []]));
        $serverError = $this->catch(fn () => $this->client(new MockResponse('{}', ['http_code' => 500]))->createCouponOrder(['coupon' => []]));

        foreach ([$timedOut, $connected, $serverError] as $e) {
            $this->assertFalse($e->isCouponOffline());
            $this->assertSame(409, $e->getCode());
        }
    }
}
