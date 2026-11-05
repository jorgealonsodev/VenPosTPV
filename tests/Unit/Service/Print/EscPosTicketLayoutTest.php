<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;

/**
 * Ajustes → Cajas on the ESC/POS ticket: the side margins become GS L (left margin)
 * and GS W (print area width) at 8 dots per mm, the text columns follow the printable
 * width (12 dots per Font A character) and the logo is scaled to a % of it.
 */
final class EscPosTicketLayoutTest extends TestCase
{
    private const LONG_NAME = 'Swimsuit TURBO PROSKIN VANGUARD CLOSED BACK MOON size 20';

    private function ticket(?float $left = null, ?float $right = null, ?int $logoPct = null, ?string $logo = null): TicketData
    {
        return new TicketData(
            orderId: 1, reference: 'A26000003', storeName: 'Nada Mas', storeAddress: 'Calle 1', ticketHeader: '',
            ticketFooter: '', ticketLogo: $logo, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-05 11:15:00'),
            cashierName: 'ana', lines: [new TicketLine(self::LONG_NAME, 1, 38.0, 38.0)], subtotal: 38.0, tax: 0.0,
            total: 38.0, paymentMethod: 'cash', amountTendered: 40.0, changeDue: 2.0, verificationUrl: 'x',
            ticketCode: 'barcode', ticketMarginLeftMm: $left, ticketMarginRightMm: $right, ticketLogoWidthPct: $logoPct,
        );
    }

    private function build(TicketData $ticket, int $paperWidth = 80): string
    {
        return (new EscPosTicketBuilder(['paperWidth' => $paperWidth]))->build($ticket);
    }

    private static function pngDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /** @return array{int, int} width in bytes and height in dots of the GS v 0 raster image */
    private static function rasterSize(string $bytes): array
    {
        self::assertSame(1, preg_match('/\x1dv0\x00(..)(..)/s', $bytes, $m), 'a raster logo is printed');
        $word = static fn (string $lowHigh): int => \ord($lowHigh[0]) + 256 * \ord($lowHigh[1]);

        return [$word($m[1]), $word($m[2])];
    }

    public function testWithoutSettingsTheMarginsAreTwoMillimetresEachSide(): void
    {
        $bytes = $this->build($this->ticket());

        self::assertStringContainsString("\x1dL\x10\x00", $bytes, 'GS L 16 dots = 2 mm');
        self::assertStringContainsString("\x1dW\x20\x02", $bytes, 'GS W 544 dots = 576 - 2 x 16');
        self::assertStringContainsString("\n" . str_repeat('-', 45) . "\n", $bytes, '544 dots hold 45 characters');
    }

    public function testTheTillMarginsSetTheLeftMarginAndThePrintAreaWidth(): void
    {
        $bytes = $this->build($this->ticket(left: 5.0, right: 3.0));

        self::assertStringContainsString("\x1dL\x28\x00", $bytes, 'GS L 40 dots = 5 mm');
        self::assertStringContainsString("\x1dW\x00\x02", $bytes, 'GS W 512 dots = 576 - 40 - 24');
        self::assertLessThan(strpos($bytes, 'Nada Mas'), strpos($bytes, "\x1dW"), 'the print area is set before the first text');
    }

    public function testTheMarginsApplyToThe58MillimetrePaper(): void
    {
        $bytes = $this->build($this->ticket(left: 0.0, right: 1.5), 58);

        self::assertStringContainsString("\x1dL\x00\x00", $bytes);
        self::assertStringContainsString("\x1dW\x74\x01", $bytes, 'GS W 372 dots = 384 - 12');
        self::assertStringContainsString("\n" . str_repeat('-', 31) . "\n", $bytes);
    }

    public function testALongLineWrapsWithinTheColumnsLeftByTheMargins(): void
    {
        $bytes = $this->build($this->ticket(left: 10.0, right: 10.0));

        // 576 - 2 x 80 = 416 dots: 34 characters.
        self::assertStringContainsString("\n" . str_repeat('-', 34) . "\n", $bytes);
        self::assertStringContainsString("Swimsuit TURBO PROSKIN VANGUARD\nCLOSED BACK MOON size 20\n", $bytes);
        self::assertStringNotContainsString(self::LONG_NAME, $bytes);
    }

    public function testTheLogoIsScaledToItsPercentageOfThePrintableWidth(): void
    {
        $bytes = $this->build($this->ticket(logoPct: 50, logo: self::pngDataUri(1000, 200)));

        // 50 % of 544 dots = 272 dots = 34 bytes, keeping the 5:1 shape.
        self::assertSame([34, 54], self::rasterSize($bytes));
    }

    public function testASmallLogoIsEnlargedToItsPercentage(): void
    {
        $bytes = $this->build($this->ticket(left: 0.0, right: 0.0, logoPct: 100, logo: self::pngDataUri(100, 50)));

        self::assertSame([72, 288], self::rasterSize($bytes), '100 % of 576 dots');
    }

    public function testWithoutAPercentageTheLogoFitsThePrintableWidthAndIsNeverEnlarged(): void
    {
        self::assertSame([68, 109], self::rasterSize($this->build($this->ticket(logo: self::pngDataUri(1000, 200)))), 'capped at 544 dots');
        self::assertSame([13, 50], self::rasterSize($this->build($this->ticket(logo: self::pngDataUri(100, 50)))), 'kept at 100 dots');
    }
}
