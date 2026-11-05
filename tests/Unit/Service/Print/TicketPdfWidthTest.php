<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

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

    /** @dataProvider widths */
    public function testEveryWordFitsInsideThePaper(?int $widthPx): void
    {
        exec('command -v pdftotext', $found, $missing);
        if ($missing !== 0) {
            self::markTestSkipped('pdftotext (poppler-utils) is not installed');
        }
        $ticket = new TicketData(
            orderId: 1, reference: 'A26000003', storeName: 'Nada Más',
            storeAddress: 'RÚA EUGENIO ARBONES,N4, local 36210 VIGO Pontevedra',
            ticketHeader: 'https://clubnatacion.com/', ticketFooter: 'Gracias por su compra', ticketLogo: null,
            ticketWidthPx: $widthPx, date: new \DateTimeImmutable('2026-11-05 11:15:00'), cashierName: 'ana',
            lines: [new TicketLine('Bañador Mujer TURBO PROSKIN VANGUARD CLOSED BACK MOON (Talla: 20)', 1, 380.0, 380.0)],
            subtotal: 380.0, tax: 0.0, total: 380.0, paymentMethod: 'cash', amountTendered: 380.0, changeDue: 0.0,
            verificationUrl: 'x', ticketCode: 'barcode', legalName: 'PRODENA PALLARES, S.L.U.', taxId: 'B27881135',
        );
        $pdf = (new TicketPdfGenerator(new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates'))))->generate($ticket);
        $file = tempnam(sys_get_temp_dir(), 'tpv_width_');
        file_put_contents($file, $pdf);
        try {
            exec('pdftotext -bbox ' . escapeshellarg($file) . ' -', $html, $code);
            self::assertSame(0, $code);
            preg_match_all('/xMax="([\d.]+)"/', implode("\n", $html), $m);
            self::assertNotEmpty($m[1]);
            self::assertLessThanOrEqual(self::PAGE_WIDTH_PT, max(array_map('floatval', $m[1])), 'a word runs past the right edge');
        } finally {
            @unlink($file);
        }
    }
}
