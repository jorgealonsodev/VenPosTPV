<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\Code128Encoder;

final class Code128EncoderTest extends TestCase
{
    public function testSymbolsAreStartBDataChecksumAndStop(): void
    {
        // Checksum: (104 + 33*1 + 34*2 + 35*3) % 103 = 310 % 103 = 1
        self::assertSame([104, 33, 34, 35, 1, 106], (new Code128Encoder())->symbols('ABC'));
    }

    public function testChecksumOfLongerReference(): void
    {
        // W=55 i=73 k=75 i=73 p=80 e=69 d=68 i=73 a=65 -> weighted sum 3281 % 103 = 88
        $symbols = (new Code128Encoder())->symbols('Wikipedia');

        self::assertSame(88, $symbols[\count($symbols) - 2]);
    }

    public function testWidthsUseTheStandardCode128Table(): void
    {
        // Start B, 'A' (33), 'B' (34), 'C' (35), checksum 1, stop
        self::assertSame(
            ['211214', '111323', '131123', '131321', '222122', '2331112'],
            (new Code128Encoder())->widths('ABC'),
        );
    }

    public function testEveryTablePatternIsWellFormed(): void
    {
        $patterns = Code128Encoder::PATTERNS;
        self::assertCount(107, $patterns);
        self::assertCount(107, array_unique($patterns));
        foreach ($patterns as $value => $pattern) {
            $digits = array_map('intval', str_split($pattern));
            $expectedModules = $value === 106 ? 13 : 11;
            self::assertSame($expectedModules, array_sum($digits), "pattern {$value} module count");
            $barModules = 0;
            foreach ($digits as $i => $width) {
                $barModules += $i % 2 === 0 ? $width : 0;
            }
            self::assertSame(0, $barModules % 2, "pattern {$value} bar parity");
        }
    }

    public function testModulesStartWithABarAndEndWithTheStopTerminationBar(): void
    {
        $modules = (new Code128Encoder())->modules('ABC');

        // 6 symbols: 5 * 11 + 13 = 68 modules
        self::assertSame(68, \strlen($modules));
        self::assertStringStartsWith('11010010000', $modules); // Start B 211214
        self::assertStringEndsWith('1100011101011', $modules); // Stop 2331112
    }

    public function testRejectsCharactersOutsideCodeSetB(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Code128Encoder())->symbols("Ñ1");
    }

    public function testRejectsEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Code128Encoder())->symbols('');
    }

    public function testPngHasQuietZonesAndScaledBars(): void
    {
        if (!\function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD not available');
        }
        $png = (new Code128Encoder())->png('ABC', 2, 30);
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);

        // (68 modules + 2 * 10 quiet zone) * 2 px
        self::assertSame(176, imagesx($image));
        self::assertSame(30, imagesy($image));
        self::assertSame(0xFFFFFF, imagecolorat($image, 0, 15) & 0xFFFFFF, 'quiet zone is white');
        self::assertSame(0x000000, imagecolorat($image, 20, 15) & 0xFFFFFF, 'first bar is black');
    }
}
