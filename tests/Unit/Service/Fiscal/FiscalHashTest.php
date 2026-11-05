<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\FiscalHash;

/**
 * The three worked examples of the AEAT "Detalle de las especificaciones técnicas
 * para generación de la huella o hash de los registros de facturación" v0.1.2.
 */
final class FiscalHashTest extends TestCase
{
    public function testFirstRecordHasAnEmptyPreviousHash(): void
    {
        self::assertSame(
            '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
            FiscalHash::alta('89890001K', '12345678/G33', '01-01-2024', 'F1', '12.35', '123.45', '', '2024-01-01T19:20:30+01:00')
        );
    }

    public function testSecondRecordChainsThePreviousHash(): void
    {
        self::assertSame(
            'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
            FiscalHash::alta(
                '89890001K',
                '12345679/G34',
                '01-01-2024',
                'F1',
                '12.35',
                '123.45',
                '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
                '2024-01-01T19:20:35+01:00'
            )
        );
    }

    public function testCancellationRecord(): void
    {
        self::assertSame(
            '177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68',
            FiscalHash::anulacion(
                '89890001K',
                '12345679/G34',
                '01-01-2024',
                'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97',
                '2024-01-01T19:20:40+01:00'
            )
        );
    }

    public function testValuesAreTrimmed(): void
    {
        self::assertSame(
            FiscalHash::alta('89890001K', '12345678/G33', '01-01-2024', 'F1', '12.35', '123.45', '', '2024-01-01T19:20:30+01:00'),
            FiscalHash::alta(' 89890001K ', ' 12345678/G33 ', '01-01-2024', 'F1', '12.35', '123.45', '', '2024-01-01T19:20:30+01:00')
        );
    }

    public function testAmountsUseTwoDecimalsAndADot(): void
    {
        self::assertSame('123.10', FiscalHash::amount(123.1));
        self::assertSame('-5.00', FiscalHash::amount(-5));
        self::assertSame('0.00', FiscalHash::amount(-0.0));
    }
}
