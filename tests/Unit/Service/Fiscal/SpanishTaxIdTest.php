<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\SpanishTaxId;

final class SpanishTaxIdTest extends TestCase
{
    public function testValidDniNieAndCifAreAccepted(): void
    {
        foreach (['12345678Z', 'X1234567L', 'B12345674', 'Q1234567D'] as $taxId) {
            $this->assertTrue(SpanishTaxId::isValid($taxId), $taxId);
        }
    }

    public function testWrongControlCharacterOrShapeIsRejected(): void
    {
        foreach (['12345678A', 'X1234567A', 'B12345675', 'Q1234567E', 'ABC', '', '123456789Z'] as $taxId) {
            $this->assertFalse(SpanishTaxId::isValid($taxId), $taxId);
        }
    }

    public function testNormalizeUppercasesAndDropsSpacesDotsAndDashes(): void
    {
        $this->assertSame('B12345674', SpanishTaxId::normalize(' b-1234.567 4 '));
    }
}
