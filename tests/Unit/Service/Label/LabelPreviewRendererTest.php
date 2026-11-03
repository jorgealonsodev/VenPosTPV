<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\BarcodeModules;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\Label;
use VenPosTPV\Service\Label\LabelPreviewRenderer;
use VenPosTPV\Service\Label\LabelSettings;

/**
 * docs/prd/agente-impresion.md L1, L2: the preview is a PNG of the same layout at
 * 203 dpi: 576 dots wide, the label's height, the bars drawn module by module.
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
        $image = imagecreatefromstring($renderer->png($label, new LabelSettings(30, false)));
        self::assertNotFalse($image);

        $modules = BarcodeModules::ean13('8412345678905');
        $left = intdiv(576 - \strlen($modules) * 3, 2);
        $y = $renderer->barsTop($label, new LabelSettings(30, false)) + 10;
        foreach ([11, 12, 13, 14] as $module) { // start guard 101 after the 11-module quiet zone
            $dark = (imagecolorat($image, $left + $module * 3 + 1, $y) & 0xFF) < 128;
            self::assertSame($modules[$module] === '1', $dark, 'module ' . $module);
        }
    }
}
