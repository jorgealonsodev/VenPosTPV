<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Checkout;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VenPosTPV\Service\Checkout\Exception\CheckoutException;

final class CheckoutExceptionTest extends TestCase
{
    public function testPsUnavailableProducesCorrectMessageAndCode(): void
    {
        $previous = new RuntimeException('Connection timeout');
        $e = CheckoutException::psUnavailable('Service down', $previous);

        $this->assertSame('PrestaShop order service unavailable: Service down', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testPsValidationFailedProducesCorrectMessageAndCode(): void
    {
        $e = CheckoutException::psValidationFailed('Missing products');

        $this->assertSame('PrestaShop order validation failed: Missing products', $e->getMessage());
        $this->assertSame(422, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testStockUpdateFailedProducesCorrectMessageAndPrevious(): void
    {
        $previous = new RuntimeException('Deadlock');
        $e = CheckoutException::stockUpdateFailed(42, $previous);

        $this->assertSame('Stock update failed for PS order 42', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testOrderMetaFailedProducesCorrectMessageAndPrevious(): void
    {
        $previous = new RuntimeException('Insert failed');
        $e = CheckoutException::orderMetaFailed(42, 'ORD-042', $previous);

        $this->assertSame('Order meta persist failed for PS order 42 (ref: ORD-042)', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testExceptionExtendsRuntimeException(): void
    {
        $e = CheckoutException::psValidationFailed('test');

        $this->assertInstanceOf(RuntimeException::class, $e);
    }
}
