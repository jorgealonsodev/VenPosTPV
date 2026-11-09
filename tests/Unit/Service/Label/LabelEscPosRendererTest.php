<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\BarcodeModules;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\Label;
use VenPosTPV\Service\Label\LabelCodeZone;
use VenPosTPV\Service\Label\LabelEscPosRenderer;
use VenPosTPV\Service\Label\LabelSettings;

/**
 * docs/prd/agente-impresion.md L3, L5-L7, AC5, AC6: ESC/POS labels for the TM-T20,
 * checked byte for byte. Full width (576 dots), height 25-60 mm (8 dots per mm),
 * one strip per job with a dotted line between labels and a single cut at the end
 * (or one cut per label when the till asks for it), the code zone (bars, their
 * text and a QR with the same data beside them) as one GS v 0 raster image and
 * character table 16 (WPC1252) for "€".
 *
 * The golden files in tests/Fixtures/labels/ are regenerated with
 * UPDATE_LABEL_FIXTURES=1 and must then be reviewed byte by byte (xxd).
 */
final class LabelEscPosRendererTest extends TestCase
{
    private const INIT = "\x1B@" . "\x1Bt\x10" . "\x1Ba\x01" . "\x1B3\x18";
    private const SEPARATOR = "\x1BJ\x03" . "\x1Dv0\x00\x48\x00\x02\x00";
    private const CUT = "\x1DV\x41\x00";

    private function shirt(): Label
    {
        return new Label('Camiseta básica', 'M · Azul', 23.14, (new BarcodeSelector())->select('8412345678905', null, 'CAM-M-AZ'));
    }

    private function cap(): Label
    {
        return new Label('Gorra', null, 9.5, (new BarcodeSelector())->select(null, null, '00123456'));
    }

    /** One 30 mm label: every byte, in the order the printer receives them. */
    public function testOneLabelIsExactlyTheseBytes(): void
    {
        $bytes = (new LabelEscPosRenderer())->render([$this->shirt()], new LabelSettings(30, false));

        $label = "\x1BJ\x08"
            . "\x1BE\x01" . "Camiseta b\xE1sica\n" . "\x1BE\x00"
            . "M \xB7 Azul\n"
            . "\x1D!\x11" . "\x1BE\x01" . "\x1B3\x30" . "23,14 \x80\n" . "\x1D!\x00" . "\x1BE\x00" . "\x1B3\x18"
            // 240 dots - 8 (separator) - 8 top - 24 name - 24 combination - 48 price - 24 HRI = 104
            // bars; the raster is the bars and their text: 128 rows of 72 bytes (576 dots).
            . "\x1Dv0\x00" . "\x48\x00" . "\x80\x00" . LabelCodeZone::for($this->shirt()->barcode, 104)->raster();

        self::assertSame(self::INIT . $label . "\x1BJ\x08" . self::CUT, $bytes);
    }

    public function testTheEuroSignIsSentAsWpc1252WithTable16(): void
    {
        $bytes = (new LabelEscPosRenderer())->render([$this->shirt()], new LabelSettings(30, false));

        self::assertStringStartsWith("\x1B@\x1Bt\x10", $bytes);
        self::assertStringContainsString("23,14 \x80", $bytes);
        self::assertStringNotContainsString("\xE2\x82\xAC", $bytes);
    }

    public function testAStripHasDottedLinesBetweenLabelsAndASingleCut(): void
    {
        $bytes = (new LabelEscPosRenderer())->render([$this->shirt(), $this->shirt(), $this->cap()], new LabelSettings(30, false));

        self::assertSame(1, substr_count($bytes, self::CUT));
        self::assertStringEndsWith(self::CUT, $bytes);
        self::assertSame(2, substr_count($bytes, self::SEPARATOR));
        self::assertGolden('strip-30mm-3-labels.bin', $bytes);
    }

    public function testCutEachLabelCutsAfterEveryLabelWithoutDottedLines(): void
    {
        $bytes = (new LabelEscPosRenderer())->render([$this->shirt(), $this->shirt(), $this->cap()], new LabelSettings(30, true));

        self::assertSame(3, substr_count($bytes, self::CUT));
        self::assertSame(0, substr_count($bytes, self::SEPARATOR));
        self::assertGolden('strip-30mm-cut-each.bin', $bytes);
    }

    public function testTheCodeIsARasterImageInsteadOfThePrinterBarcodeCommands(): void
    {
        $bytes = (new LabelEscPosRenderer())->render([$this->shirt(), $this->cap()], new LabelSettings(30, false));

        self::assertStringNotContainsString("\x1Dk", $bytes, 'no GS k');
        self::assertStringNotContainsString("\x1Dh", $bytes, 'no GS h');
        self::assertStringNotContainsString("\x1DH", $bytes, 'no GS H');
        $zones = array_values(array_filter(self::tape($bytes)['rasters'], static fn (array $r): bool => $r['height'] > 2));
        self::assertCount(2, $zones);
        foreach ($zones as $raster) {
            self::assertSame(576, $raster['width']);
            // Bars 40-255 dots plus 24 of text.
            self::assertGreaterThanOrEqual(64, $raster['height']);
            self::assertLessThanOrEqual(279, $raster['height']);
        }
    }

    public function testTheBarsAndTheQrOfTheRasterCarryTheSameEan(): void
    {
        $label = $this->shirt();
        $bytes = (new LabelEscPosRenderer())->render([$label], new LabelSettings(30, false));
        $zone = LabelCodeZone::for($label->barcode, 104);
        $bitmap = self::zoneBitmap($bytes);

        self::assertSame(self::expand(BarcodeModules::ean13('8412345678905'), 3), substr($bitmap[0], $zone->barsLeft, $zone->barsWidth));
        self::assertTrue($zone->hasQr());
        LabelCodeZoneTest::assertQrIs('8412345678905', $zone, $bitmap);
    }

    public function testANumericReferenceIsCode128SetCWithTheReferenceInTheQr(): void
    {
        $settings = new LabelSettings(30, false);
        $bytes = (new LabelEscPosRenderer())->render([$this->cap()], $settings);
        $zone = LabelCodeZone::for($this->cap()->barcode, (new LabelEscPosRenderer())->layout($this->cap(), $settings)['bars']);
        $bitmap = self::zoneBitmap($bytes);

        self::assertSame(self::expand(BarcodeModules::code128('00123456', 'C'), 3), substr($bitmap[0], $zone->barsLeft, $zone->barsWidth));
        LabelCodeZoneTest::assertQrIs('00123456', $zone, $bitmap);
        self::assertStringContainsString("9,50 \x80\n", $bytes);
    }

    public function testATextReferenceIsCode128SetBWithTheReferenceInTheQr(): void
    {
        $label = new Label('Bolsa', null, 1.0, (new BarcodeSelector())->select(null, null, 'A{1'));
        $settings = new LabelSettings(30, false);
        $bytes = (new LabelEscPosRenderer())->render([$label], $settings);
        $zone = LabelCodeZone::for($label->barcode, (new LabelEscPosRenderer())->layout($label, $settings)['bars']);
        $bitmap = self::zoneBitmap($bytes);

        self::assertSame(self::expand(BarcodeModules::code128('A{1', 'B'), 3), substr($bitmap[0], $zone->barsLeft, $zone->barsWidth));
        LabelCodeZoneTest::assertQrIs('A{1', $zone, $bitmap);
    }

    public function testACode128WithNoRoomForTheQrPrintsTheSameBarsWithoutIt(): void
    {
        $reference = str_repeat('A', 21);
        $label = new Label('Bolsa', null, 1.0, (new BarcodeSelector())->select(null, null, $reference));
        $bitmap = self::zoneBitmap((new LabelEscPosRenderer())->render([$label], new LabelSettings(30, false)));

        // 286 modules at 2 dots, centred: the whole row is the bars.
        self::assertSame('00' . self::expand(BarcodeModules::code128($reference, 'B'), 2) . '00', $bitmap[0]);
    }

    public function testEveryLabelFeedsExactlyItsHeightOnThePaper(): void
    {
        $renderer = new LabelEscPosRenderer();

        foreach ([25, 30, 40, 60] as $mm) {
            foreach ([$this->shirt(), $this->cap()] as $label) {
                $one = self::tape($renderer->render([$label], new LabelSettings($mm, true)));
                self::assertSame($mm * 8, $one['dots'], $mm . ' mm, cut after it');
            }
            $strip = self::tape($renderer->render([$this->shirt(), $this->cap(), $this->shirt()], new LabelSettings($mm, false)));
            self::assertSame(3 * $mm * 8, $strip['dots'], $mm . ' mm strip');
        }
    }

    public function testEveryLabelTakesItsConfiguredHeight(): void
    {
        $renderer = new LabelEscPosRenderer();

        foreach ([25, 30, 45, 60] as $mm) {
            self::assertSame($mm * 8, $renderer->labelDots($this->shirt(), new LabelSettings($mm, false)), $mm . ' mm');
            self::assertSame($mm * 8, $renderer->labelDots($this->cap(), new LabelSettings($mm, false)), $mm . ' mm');
        }
        self::assertGolden('label-60mm.bin', $renderer->render([$this->shirt()], new LabelSettings(60, false)));
    }

    public function testALongNameKeepsTwoLinesAndEndsWithAnEllipsis(): void
    {
        $name = str_repeat('Camiseta técnica de natación ', 6);
        $label = new Label($name, null, 23.14, (new BarcodeSelector())->select('8412345678905', null, null));
        $bytes = (new LabelEscPosRenderer())->render([$label], new LabelSettings(30, false));

        preg_match("/\x1BE\x01(.*?)\x1BE\x00/s", $bytes, $m);
        $lines = explode("\n", rtrim($m[1], "\n"));
        self::assertCount(2, $lines);
        self::assertLessThanOrEqual(48, \strlen($lines[0]));
        self::assertLessThanOrEqual(48, \strlen($lines[1]));
        self::assertStringEndsWith("\x85", $lines[1]);
    }

    public function testTheHeightOutsideTwentyFiveToSixtyIsRefused(): void
    {
        self::assertSame(30, LabelSettings::defaults()->heightMm);
        self::assertFalse(LabelSettings::defaults()->cutEach);
        foreach ([24, 61, 0] as $mm) {
            try {
                LabelSettings::validated($mm, false);
                self::fail($mm . ' mm was accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Alto de etiqueta no válido (25-60 mm)', $e->getMessage());
            }
        }
        self::assertSame(25, LabelSettings::validated(25, true)->heightMm);
        self::assertSame(60, LabelSettings::validated(60, false)->heightMm);
        // A stored value out of bounds (edited by hand) prints with the default.
        self::assertSame(30, LabelSettings::fromStored(200, 0)->heightMm);
    }

    private static function expand(string $modules, int $dots): string
    {
        $out = '';
        foreach (str_split($modules) as $module) {
            $out .= str_repeat($module, $dots);
        }

        return $out;
    }

    /** @return list<string> the rows of the only code-zone raster, '1' for a black dot */
    private static function zoneBitmap(string $bytes): array
    {
        $zones = array_values(array_filter(self::tape($bytes)['rasters'], static fn (array $r): bool => $r['height'] > 2));
        self::assertCount(1, $zones);

        return $zones[0]['rows'];
    }

    /**
     * Plays the bytes like the printer does with the commands the renderer uses and
     * returns the dots of paper fed and the raster images.
     *
     * @return array{dots: int, rasters: list<array{width: int, height: int, rows: list<string>}>}
     */
    private static function tape(string $bytes): array
    {
        $dots = 0;
        $spacing = 30; // ESC @ default (1/6 inch); every job sets it with ESC 3.
        $rasters = [];
        $n = \strlen($bytes);
        for ($i = 0; $i < $n;) {
            $c = $bytes[$i];
            if ($c === "\x1B") {
                $cmd = $bytes[$i + 1];
                if ($cmd === '@') {
                    $i += 2;
                    continue;
                }
                if ($cmd === '3') {
                    $spacing = \ord($bytes[$i + 2]);
                } elseif ($cmd === 'J') {
                    $dots += \ord($bytes[$i + 2]);
                } elseif (!\in_array($cmd, ['t', 'a', 'E'], true)) {
                    self::fail(sprintf('Unexpected ESC %s at %d', bin2hex($cmd), $i));
                }
                $i += 3;
            } elseif ($c === "\x1D") {
                $cmd = $bytes[$i + 1];
                if ($cmd === 'v') {
                    $widthBytes = \ord($bytes[$i + 4]) + 256 * \ord($bytes[$i + 5]);
                    $height = \ord($bytes[$i + 6]) + 256 * \ord($bytes[$i + 7]);
                    $rows = [];
                    for ($y = 0; $y < $height; ++$y) {
                        $row = '';
                        foreach (str_split(substr($bytes, $i + 8 + $y * $widthBytes, $widthBytes)) as $byte) {
                            $row .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
                        }
                        $rows[] = $row;
                    }
                    $rasters[] = ['width' => 8 * $widthBytes, 'height' => $height, 'rows' => $rows];
                    $dots += $height;
                    $i += 8 + $widthBytes * $height;
                } elseif ($cmd === '!') {
                    $i += 3;
                } elseif ($cmd === 'V') {
                    $i += 4;
                } else {
                    self::fail(sprintf('Unexpected GS %s at %d', bin2hex($cmd), $i));
                }
            } else {
                if ($c === "\n") {
                    $dots += $spacing;
                }
                ++$i;
            }
        }

        return ['dots' => $dots, 'rasters' => $rasters];
    }

    private static function assertGolden(string $file, string $bytes): void
    {
        $path = \dirname(__DIR__, 3) . '/Fixtures/labels/' . $file;
        if (getenv('UPDATE_LABEL_FIXTURES') === '1') {
            file_put_contents($path, $bytes);
        }
        self::assertFileExists($path);
        self::assertSame(bin2hex((string) file_get_contents($path)), bin2hex($bytes), $file);
    }
}
