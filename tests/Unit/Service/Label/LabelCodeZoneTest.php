<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\BarcodeModules;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\LabelBarcode;
use VenPosTPV\Service\Label\LabelCodeZone;

/**
 * docs/prd/agente-impresion.md L3, L4, L7: the code zone of a label is one 1-bit
 * bitmap, 576 dots wide, with the bars (module-aligned, integer dots per module),
 * their text under them and, on the same rows, a small QR code with the same data.
 * The printer gets it as a GS v 0 raster and the preview paints it pixel by pixel.
 */
final class LabelCodeZoneTest extends TestCase
{
    private static function ean13(): LabelBarcode
    {
        return (new BarcodeSelector())->select('8412345678905', null, null);
    }

    public function testTheZoneIsFullWidthAndBarsPlusTextHigh(): void
    {
        $zone = LabelCodeZone::for(self::ean13(), 104);

        self::assertSame(576, $zone->width);
        self::assertSame(128, $zone->height);
        $bitmap = $zone->bitmap();
        self::assertCount(128, $bitmap);
        foreach ($bitmap as $row) {
            self::assertSame(576, \strlen($row));
        }
    }

    public function testTheBarsAreTheModulesAtIntegerDotsOnEveryRowOfTheBars(): void
    {
        $barcode = self::ean13();
        $zone = LabelCodeZone::for($barcode, 104);
        $expected = self::expand(BarcodeModules::ean13('8412345678905'), 3);

        self::assertSame(3, $zone->moduleDots);
        self::assertSame(\strlen($expected), $zone->barsWidth);
        $bitmap = $zone->bitmap();
        foreach ([0, 50, 103] as $y) {
            self::assertSame($expected, substr($bitmap[$y], $zone->barsLeft, $zone->barsWidth), 'row ' . $y);
        }
    }

    public function testTheQrHasTheSameDataAsTheBarsAndSitsBesideThem(): void
    {
        foreach ([
            ['8412345678905', null],
            ['96385074', null],
            [null, 'CAM-M-AZ'],
            [null, '00123456'],
            [null, 'a{1'],
        ] as [$ean, $reference]) {
            $barcode = (new BarcodeSelector())->select($ean, null, $reference);
            $zone = LabelCodeZone::for($barcode, 104);

            self::assertTrue($zone->hasQr(), $barcode->data);
            self::assertQrIs($barcode->data, $zone);
            // Beside the bars, never over them, with its quiet zone towards them.
            self::assertGreaterThanOrEqual($zone->barsLeft + $zone->barsWidth, $zone->qrLeft - 2 * $zone->qrModuleDots, $barcode->data);
            self::assertLessThanOrEqual(576, $zone->qrLeft + ($zone->qrModules + 2) * $zone->qrModuleDots);
        }
    }

    public function testTheQrIsSmallAndNeverTallerThanTheZone(): void
    {
        $zone = LabelCodeZone::for(self::ean13(), 104);

        // A version 1 symbol (21 modules) at 5 dots: 105 dots, 13.1 mm.
        self::assertSame(21, $zone->qrModules);
        self::assertSame(5, $zone->qrModuleDots);
        self::assertLessThanOrEqual(LabelCodeZone::QR_MAX_DOTS, $zone->qrModules * $zone->qrModuleDots);

        foreach ([40, 64, 104, 255] as $bars) {
            $zone = LabelCodeZone::for(self::ean13(), $bars);
            self::assertTrue($zone->hasQr(), $bars . ' dots of bars');
            self::assertLessThanOrEqual($zone->height, ($zone->qrModules + 4) * $zone->qrModuleDots, $bars . ' dots of bars');
            self::assertGreaterThanOrEqual(2 * $zone->qrModuleDots, $zone->qrTop, 'quiet zone above');
            self::assertLessThanOrEqual($zone->height - 2 * $zone->qrModuleDots, $zone->qrTop + $zone->qrModules * $zone->qrModuleDots, 'quiet zone below');
            self::assertLessThanOrEqual(LabelCodeZone::QR_MAX_DOTS, $zone->qrModules * $zone->qrModuleDots);
            self::assertQrIs('8412345678905', $zone);
        }
    }

    public function testTheGroupOfBarsAndQrIsCentred(): void
    {
        $zone = LabelCodeZone::for(self::ean13(), 104);

        $right = 576 - ($zone->qrLeft + ($zone->qrModules + 2) * $zone->qrModuleDots);
        self::assertLessThanOrEqual(1, abs($zone->barsLeft - $right));
    }

    public function testACode128TooWideAtItsModuleNarrowsItToTheMinimumToMakeRoomForTheQr(): void
    {
        // 12 characters of set B: 187 modules, 561 dots at 3, 374 at 2.
        $barcode = (new BarcodeSelector())->select(null, null, 'ABCDEFGHIJKL');
        self::assertSame(3, $barcode->moduleDots);

        $zone = LabelCodeZone::for($barcode, 104);

        self::assertSame(BarcodeSelector::MIN_MODULE_DOTS, $zone->moduleDots);
        self::assertTrue($zone->hasQr());
        self::assertSame(self::expand(BarcodeModules::code128('ABCDEFGHIJKL', 'B'), 2), substr($zone->bitmap()[0], $zone->barsLeft, $zone->barsWidth));
        self::assertQrIs('ABCDEFGHIJKL', $zone);
    }

    public function testACode128WithNoRoomForTheQrKeepsItsBarsAndDropsTheQr(): void
    {
        // 21 characters of set B: 286 modules, 572 dots at 2: no room left.
        $barcode = (new BarcodeSelector())->select(null, null, str_repeat('A', 21));
        $zone = LabelCodeZone::for($barcode, 104);

        self::assertFalse($zone->hasQr());
        self::assertSame(2, $zone->moduleDots);
        self::assertSame(2, $zone->barsLeft);
        $expected = self::expand(BarcodeModules::code128(str_repeat('A', 21), 'B'), 2);
        self::assertSame(str_repeat('0', 2) . $expected . str_repeat('0', 2), $zone->bitmap()[0]);
    }

    public function testTheTextOfTheCodeIsUnderTheBars(): void
    {
        $zone = LabelCodeZone::for(self::ean13(), 104);
        $bitmap = $zone->bitmap();

        $ink = 0;
        $outside = 0;
        for ($y = 104; $y < 128; ++$y) {
            $under = substr($bitmap[$y], $zone->barsLeft, $zone->barsWidth);
            $ink += substr_count($under, '1');
            $outside += substr_count(substr($bitmap[$y], 0, $zone->barsLeft), '1');
        }
        self::assertGreaterThan(100, $ink, 'the digits are drawn under the bars');
        self::assertSame(0, $outside, 'nothing on the left of the bars');
    }

    public function testTheRasterPacksTheBitmapEightDotsPerByteFromTheLeft(): void
    {
        $zone = LabelCodeZone::for(self::ean13(), 104);
        $raster = $zone->raster();

        self::assertSame(72 * 128, \strlen($raster));
        foreach ($zone->bitmap() as $y => $row) {
            $packed = '';
            foreach (str_split($row, 8) as $byte) {
                $packed .= \chr((int) bindec($byte));
            }
            self::assertSame(bin2hex($packed), bin2hex(substr($raster, $y * 72, 72)), 'row ' . $y);
        }
    }

    private static function expand(string $modules, int $dots): string
    {
        $out = '';
        foreach (str_split($modules) as $module) {
            $out .= str_repeat($module, $dots);
        }

        return $out;
    }

    /** Samples the bitmap at the centre of every module against an independent encoding (M). */
    public static function assertQrIs(string $data, LabelCodeZone $zone, ?array $bitmap = null): void
    {
        $matrix = Encoder::encode($data, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        $bitmap ??= $zone->bitmap();
        $d = $zone->qrModuleDots;
        self::assertSame($matrix->getWidth(), $zone->qrModules, $data);
        for ($y = -2; $y < $matrix->getWidth() + 2; ++$y) {
            for ($x = -2; $x < $matrix->getWidth() + 2; ++$x) {
                $inside = $x >= 0 && $y >= 0 && $x < $matrix->getWidth() && $y < $matrix->getWidth();
                $expected = $inside && $matrix->get($x, $y) === 1 ? '1' : '0';
                // Every dot of the module, not only its centre: integer dots per module.
                for ($dy = 0; $dy < $d; ++$dy) {
                    $row = $bitmap[$zone->qrTop + $y * $d + $dy];
                    self::assertSame(str_repeat($expected, $d), substr($row, $zone->qrLeft + $x * $d, $d), sprintf('%s module %d,%d', $data, $x, $y));
                }
            }
        }
    }
}
