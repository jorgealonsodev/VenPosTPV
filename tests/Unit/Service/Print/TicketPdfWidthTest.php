<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;

/** Nothing on the PDF ticket may run past the right edge of the 80 mm paper. */
final class TicketPdfWidthTest extends TestCase
{
    private const PAGE_WIDTH_PT = 226.77;

    /** @return iterable<string, array{?int}> */
    public static function widths(): iterable
    {
        yield 'default 80 mm' => [null];
        yield 'the 302 px the settings suggest' => [302];
        yield 'a width wider than the paper' => [576];
    }

    #[DataProvider('widths')]
    public function testEveryWordFitsInsideThePaper(?int $widthPx): void
    {
        $words = $this->words($this->ticket(widthPx: $widthPx));

        self::assertLessThanOrEqual(self::PAGE_WIDTH_PT, max(array_column($words, 1)), 'a word runs past the right edge');
    }

    /** @return iterable<string, array{float, float}> */
    public static function margins(): iterable
    {
        yield 'no margins' => [0.0, 0.0];
        yield '10 mm each side' => [10.0, 10.0];
        yield '10 mm on the right only' => [0.0, 10.0];
    }

    #[DataProvider('margins')]
    public function testWithTheTillMarginsEveryWordStillFitsInsideThePaper(float $left, float $right): void
    {
        $words = $this->words($this->ticket(left: $left, right: $right));

        // 1 mm = 72 / 25.4 pt; half a point of rounding is allowed.
        $rightEdge = self::PAGE_WIDTH_PT - $right * 72 / 25.4 + 0.5;
        self::assertLessThanOrEqual($rightEdge, max(array_column($words, 1)), 'a word runs into the right margin');
        self::assertGreaterThanOrEqual($left * 72 / 25.4 - 0.5, min(array_column($words, 0)), 'a word runs into the left margin');
    }

    public function testATenMillimetreLeftMarginMovesTheTextRight(): void
    {
        $words = $this->words($this->ticket(left: 10.0, right: 2.0));

        self::assertGreaterThanOrEqual(28.0, min(array_column($words, 0)));
    }

    /** The logo is as wide as its percentage of the printable width, centred. */
    public function testTheLogoTakesItsPercentageOfThePrintableWidth(): void
    {
        $html = $this->html($this->ticket(left: 5.0, right: 5.0, logoPct: 60, logo: self::pngDataUri()));

        self::assertStringContainsString('style="width:60%; height:auto;" alt="Logo"', $html);
        self::assertStringContainsString('width: 264.2px;', $html, '302 - 2 x 18.9 px (5 mm)');
        self::assertStringContainsString('padding: 8px 18.9px 8px 18.9px;', $html);
    }

    public function testWithoutSettingsTheLogoOnlyShrinksAndTheMarginsStayAtEightPixels(): void
    {
        $html = $this->html($this->ticket(logo: self::pngDataUri()));

        self::assertStringContainsString('style="max-width:100%; height:auto;" alt="Logo"', $html);
        self::assertStringContainsString('padding: 8px 8px 8px 8px;', $html);
    }

    private function ticket(?int $widthPx = null, ?float $left = null, ?float $right = null, ?int $logoPct = null, ?string $logo = null): TicketData
    {
        return new TicketData(
            orderId: 1, reference: 'A26000003', storeName: 'Nada Más',
            storeAddress: 'RÚA EUGENIO ARBONES,N4, local 36210 VIGO Pontevedra',
            ticketHeader: 'https://clubnatacion.com/', ticketFooter: 'Gracias por su compra', ticketLogo: $logo,
            ticketWidthPx: $widthPx, date: new \DateTimeImmutable('2026-11-05 11:15:00'), cashierName: 'ana',
            lines: [new TicketLine('Bañador Mujer TURBO PROSKIN VANGUARD CLOSED BACK MOON (Talla: 20)', 1, 380.0, 380.0)],
            subtotal: 380.0, tax: 0.0, total: 380.0, paymentMethod: 'cash', amountTendered: 380.0, changeDue: 0.0,
            verificationUrl: 'x', ticketCode: 'barcode', legalName: 'PRODENA PALLARES, S.L.U.', taxId: 'B27881135',
            ticketMarginLeftMm: $left, ticketMarginRightMm: $right, ticketLogoWidthPct: $logoPct,
        );
    }

    private static function pngDataUri(): string
    {
        $image = imagecreatetruecolor(400, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    private function generator(): TicketPdfGenerator
    {
        return new TicketPdfGenerator(new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates')));
    }

    /** The HTML dompdf receives, caught through a Twig environment that records it. */
    private function html(TicketData $ticket): string
    {
        $twig = new class (new FilesystemLoader(__DIR__ . '/../../../../templates')) extends Environment {
            public string $last = '';

            public function render($name, array $context = []): string
            {
                return $this->last = parent::render($name, $context);
            }
        };
        (new TicketPdfGenerator($twig))->generate($ticket);

        return $twig->last;
    }

    /** @return list<array{float, float}> xMin and xMax, in points, of every word on the PDF */
    private function words(TicketData $ticket): array
    {
        exec('command -v pdftotext', $found, $missing);
        if ($missing !== 0) {
            self::markTestSkipped('pdftotext (poppler-utils) is not installed');
        }
        $file = tempnam(sys_get_temp_dir(), 'tpv_width_');
        file_put_contents($file, $this->generator()->generate($ticket));
        try {
            exec('pdftotext -bbox ' . escapeshellarg($file) . ' -', $html, $code);
            self::assertSame(0, $code);
            preg_match_all('/xMin="([\d.]+)"[^>]*xMax="([\d.]+)"/', implode("\n", $html), $m, PREG_SET_ORDER);
            self::assertNotEmpty($m);

            return array_map(static fn (array $word): array => [(float) $word[1], (float) $word[2]], $m);
        } finally {
            @unlink($file);
        }
    }
}
