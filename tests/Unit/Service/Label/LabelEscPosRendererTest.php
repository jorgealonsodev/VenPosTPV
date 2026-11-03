<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\Label;
use VenPosTPV\Service\Label\LabelEscPosRenderer;
use VenPosTPV\Service\Label\LabelSettings;

/**
 * docs/prd/agente-impresion.md L3, L5-L7, AC5, AC6: ESC/POS labels for the TM-T20,
 * checked byte for byte. Full width (576 dots), height 25-60 mm (8 dots per mm),
 * one strip per job with a dotted line between labels and a single cut at the end
 * (or one cut per label when the till asks for it), the printer's own barcode
 * commands (GS k) and character table 16 (WPC1252) for "€".
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
            . "\x1Dh\x68" . "\x1Dw\x03" . "\x1DH\x02" . "\x1Df\x00" . "\x1Dk\x43\x0C" . '841234567890';

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

    public function testANumericReferenceIsSentAsCode128SetCPairs(): void
    {
        $bytes = (new LabelEscPosRenderer())->render([$this->cap()], new LabelSettings(30, false));

        // GS k 73 n "{C" 00 12 34 56
        self::assertStringContainsString("\x1Dk\x49\x06{C\x00\x0C\x22\x38", $bytes);
        self::assertStringContainsString("9,50 \x80\n", $bytes);
    }

    public function testATextReferenceIsSentAsCode128SetBWithBracesDoubled(): void
    {
        $label = new Label('Bolsa', null, 1.0, (new BarcodeSelector())->select(null, null, 'A{1'));
        $bytes = (new LabelEscPosRenderer())->render([$label], new LabelSettings(30, false));

        self::assertStringContainsString("\x1Dk\x49\x06{BA{{1", $bytes);
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
