<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Refund;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VenPosTPV\Service\Refund\Exception\RefundException;

final class RefundExceptionTest extends TestCase
{
    public function testDoubleRefundAttemptProducesCorrectMessageAndCode(): void
    {
        $e = RefundException::doubleRefundAttempt(42);

        $this->assertSame('Order 42 already fully refunded', $e->getMessage());
        $this->assertSame(409, $e->getCode());
    }

    public function testOrderNotFoundProducesCorrectMessageAndCode(): void
    {
        $e = RefundException::orderNotFound(99);

        $this->assertSame('Order 99 not found', $e->getMessage());
        $this->assertSame(404, $e->getCode());
    }

    public function testPsUnavailableProducesCorrectMessageAndPrevious(): void
    {
        $previous = new RuntimeException('Connection timeout');
        $e = RefundException::psUnavailable('Service down', $previous);

        $this->assertSame('PrestaShop refund service unavailable: Service down', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testValidationFailedProducesCorrectMessageAndCode(): void
    {
        $e = RefundException::validationFailed('Invalid lines');

        $this->assertSame('Refund validation failed: Invalid lines', $e->getMessage());
        $this->assertSame(422, $e->getCode());
    }

    public function testExceptionExtendsRuntimeException(): void
    {
        $e = RefundException::validationFailed('test');

        $this->assertInstanceOf(RuntimeException::class, $e);
    }
}
