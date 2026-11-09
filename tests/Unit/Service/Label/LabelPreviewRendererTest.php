<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\BarcodeModules;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\Label;
use VenPosTPV\Service\Label\LabelCodeZone;
use VenPosTPV\Service\Label\LabelEscPosRenderer;
use VenPosTPV\Service\Label\LabelPreviewRenderer;
use VenPosTPV\Service\Label\LabelSettings;

/**
 * docs/prd/agente-impresion.md L1, L2: the preview is a PNG of the same layout at
 * 203 dpi: 576 dots wide, the label's height, and the code zone the printer gets
 * (bars module by module, their text and the QR beside them) dot for dot.
 */
final class LabelPreviewRendererTest extends TestCase
{
    public function testThePreviewIsAPngOfTheLabelSizeAsADataUri(): void
    {
        $label = new Label('Camiseta básica', 'M · Azul', 23.14, (new BarcodeSelector())->select('8412345678905', null, null));

        $uri = (new LabelPreviewRenderer())->dataUri($label, new LabelSettings(30, false));

        self::assertStringStartsWith('data:image/png;base64,', $uri);
        $image = imagecreatefromstring((string) base64_decode(substr($uri, 22), true));
        self::assertNotFalse($image);
        self::assertSame(576, imagesx($image));
        self::assertSame(240, imagesy($image));
    }

    public function testTheBarsOfTheCodeAreDrawnModuleByModule(): void
    {
        $label = new Label('Gorra', null, 9.5, (new BarcodeSelector())->select('8412345678905', null, null));
        $renderer = new LabelPreviewRenderer();
        $settings = new LabelSettings(30, false);
        $image = imagecreatefromstring($renderer->png($label, $settings));
        self::assertNotFalse($image);

        $modules = BarcodeModules::ean13('8412345678905');
        $zone = LabelCodeZone::for($label->barcode, (new LabelEscPosRenderer())->layout($label, $settings)['bars']);
        $y = $renderer->barsTop($label, $settings) + 10;
        foreach (str_split($modules) as $module => $bar) {
            foreach ([0, 1, 2] as $dot) {
                $dark = (imagecolorat($image, $zone->barsLeft + $module * 3 + $dot, $y) & 0xFF) < 128;
                self::assertSame($bar === '1', $dark, 'module ' . $module);
            }
        }
    }

    public function testThePreviewShowsTheQrTheLabelPrints(): void
    {
        $label = new Label('Camiseta básica', 'M · Azul', 23.14, (new BarcodeSelector())->select('8412345678905', null, null));
        $renderer = new LabelPreviewRenderer();
        $settings = new LabelSettings(30, false);
        $image = imagecreatefromstring($renderer->png($label, $settings));
        self::assertNotFalse($image);

        $top = $renderer->barsTop($label, $settings);
        $zone = LabelCodeZone::for($label->barcode, 104);
        self::assertTrue($zone->hasQr());
        // The zone rows as the preview shows them.
        $rows = [];
        for ($y = 0; $y < $zone->height; ++$y) {
            $row = '';
            for ($x = 0; $x < 576; ++$x) {
                $row .= (imagecolorat($image, $x, $top + $y) & 0xFF) < 128 ? '1' : '0';
            }
            $rows[] = $row;
        }
        self::assertSame($zone->bitmap(), $rows, 'the preview shows exactly the printed code zone');
        LabelCodeZoneTest::assertQrIs('8412345678905', $zone, $rows);
    }
}
