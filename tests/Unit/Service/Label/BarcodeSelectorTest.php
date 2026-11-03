<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\LabelBarcode;
use VenPosTPV\Service\Label\LabelException;

/**
 * docs/prd/agente-impresion.md L4: the combination's ean13, else the product's;
 * the check digit decides EAN-13 (13), EAN-8 (8) or UPC-A (12); otherwise the
 * reference as Code128 (set C when numeric with an even length). A code that does
 * not fit at a 0.25 mm module (2 dots at 203 dpi on 576 dots), or no code at all,
 * is refused with the reason.
 */
final class BarcodeSelectorTest extends TestCase
{
    private BarcodeSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new BarcodeSelector();
    }

    public function testTheCombinationEanWinsOverTheProductEan(): void
    {
        $code = $this->selector->select('8412345678905', '4006381333931', 'CAM-M-AZ');

        self::assertSame(LabelBarcode::EAN13, $code->type);
        self::assertSame('8412345678905', $code->data);
        self::assertSame('8412345678905', $code->text);
    }

    public function testWithoutCombinationEanTheProductEanIsUsed(): void
    {
        $code = $this->selector->select('', '4006381333931', 'CAM');

        self::assertSame(LabelBarcode::EAN13, $code->type);
        self::assertSame('4006381333931', $code->data);
    }

    public function testEightDigitsWithAValidCheckDigitAreEan8(): void
    {
        $code = $this->selector->select('96385074', null, null);

        self::assertSame(LabelBarcode::EAN8, $code->type);
        self::assertSame('96385074', $code->data);
    }

    public function testTwelveDigitsWithAValidCheckDigitAreUpcA(): void
    {
        $code = $this->selector->select(null, '036000291452', null);

        self::assertSame(LabelBarcode::UPCA, $code->type);
        self::assertSame('036000291452', $code->data);
    }

    public function testAWrongCheckDigitFallsBackToTheReferenceInCode128B(): void
    {
        $code = $this->selector->select('8412345678901', null, 'CAM-M-AZ');

        self::assertSame(LabelBarcode::CODE128, $code->type);
        self::assertSame('B', $code->codeSet);
        self::assertSame('CAM-M-AZ', $code->data);
        self::assertSame('CAM-M-AZ', $code->text);
    }

    public function testANumericReferenceWithAnEvenLengthUsesCode128SetC(): void
    {
        $code = $this->selector->select(null, null, '00123456');

        self::assertSame(LabelBarcode::CODE128, $code->type);
        self::assertSame('C', $code->codeSet);
    }

    public function testANumericReferenceWithAnOddLengthUsesSetB(): void
    {
        $code = $this->selector->select(null, null, '1234567');

        self::assertSame('B', $code->codeSet);
    }

    public function testNoEanAndNoReferenceIsRefusedWithTheReason(): void
    {
        $this->expectException(LabelException::class);
        $this->expectExceptionMessage('Sin EAN ni referencia: no se puede imprimir la etiqueta');

        $this->selector->select(null, '  ', '');
    }

    public function testAnInvalidEanWithoutReferenceIsRefused(): void
    {
        $this->expectException(LabelException::class);
        $this->expectExceptionMessage('El EAN «8412345678901» no es válido y no hay referencia');

        $this->selector->select('8412345678901', null, null);
    }

    public function testAReferenceThatDoesNotFitAtAQuarterMillimetreModuleIsRefused(): void
    {
        // Set B: 21 characters fit at 2 dots per module on 576 dots, 22 do not.
        self::assertSame(2, $this->selector->select(null, null, str_repeat('A', 21))->moduleDots);

        $this->expectException(LabelException::class);
        $this->expectExceptionMessage('La referencia «' . str_repeat('A', 22) . '» es demasiado larga para el código de barras (máximo 21 caracteres)');

        $this->selector->select(null, null, str_repeat('A', 22));
    }

    public function testSetCFitsTwiceAsManyDigits(): void
    {
        self::assertSame('C', $this->selector->select(null, null, str_repeat('1', 42))->codeSet);

        $this->expectException(LabelException::class);
        $this->selector->select(null, null, str_repeat('1', 44));
    }

    public function testAReferenceWithCharactersCode128CannotCarryIsRefused(): void
    {
        $this->expectException(LabelException::class);
        $this->expectExceptionMessage('La referencia «CAMISETA-Ñ» tiene caracteres que no admite el código de barras');

        $this->selector->select(null, null, 'CAMISETA-Ñ');
    }

    public function testShortCodesUseTheWidestModuleThatFits(): void
    {
        self::assertSame(3, $this->selector->select('8412345678905', null, null)->moduleDots);
        self::assertSame(3, $this->selector->select(null, null, 'CAM-M-AZ')->moduleDots);
    }
}
