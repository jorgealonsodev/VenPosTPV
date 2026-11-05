<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Invoice;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Invoice\InvoiceNumberer;

/** docs/prd/factura-completa.md F4: one shop series F + year + six digits, nine characters. */
final class InvoiceNumbererTest extends TestCase
{
    public function testNumbersAreTheLetterFTheYearAndSixDigits(): void
    {
        self::assertSame('F26000001', InvoiceNumberer::format(2026, 1));
        self::assertSame('F27999999', InvoiceNumberer::format(2027, 999999));
        self::assertSame('F26', InvoiceNumberer::series(2026));
        self::assertMatchesRegularExpression('/^[A-Z]\d{8}$/', InvoiceNumberer::format(2026, 42), 'the shape of a PrestaShop order reference');
    }

    public function testNumbersOutOfRangeAreRefused(): void
    {
        $this->expectException(\OverflowException::class);
        InvoiceNumberer::format(2026, 1000000);
    }

    public function testZeroIsNotANumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvoiceNumberer::format(2026, 0);
    }

    public function testOnlyTheLetterFIsReserved(): void
    {
        self::assertTrue(InvoiceNumberer::isReservedLetter('F'));
        self::assertTrue(InvoiceNumberer::isReservedLetter(' f '));
        self::assertFalse(InvoiceNumberer::isReservedLetter('A'));
        self::assertSame('La letra F está reservada para las facturas', InvoiceNumberer::RESERVED_LETTER_MESSAGE);
    }
}
