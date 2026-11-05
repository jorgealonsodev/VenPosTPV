<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;

/** VERI*FACTU on: «QR tributario:», the cotejo QR and «VERI*FACTU», first on the ticket. */
final class FiscalQrPrintingTest extends TestCase
{
    private const URL = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=G12345678&numserie=A26000123&fecha=05-11-2026&importe=4.00';

    private function ticket(?string $fiscalQrUrl): TicketData
    {
        return new TicketData(
            orderId: 42,
            reference: 'A26000123',
            storeName: 'Mi Tienda',
            storeAddress: 'Calle Falsa 123',
            ticketHeader: '',
            ticketFooter: '',
            ticketLogo: null,
            ticketWidthPx: 302,
            date: new \DateTimeImmutable('2026-11-05 10:00:00'),
            cashierName: 'Ana',
            lines: [new TicketLine('Pan', 1, 4.0, 4.0)],
            subtotal: 4.0,
            tax: 0.69,
            total: 4.0,
            paymentMethod: 'cash',
            amountTendered: 5.0,
            changeDue: 1.0,
            verificationUrl: 'https://example.com/verify?token=abc',
            ticketCode: 'barcode',
            fiscalQrUrl: $fiscalQrUrl,
        );
    }

    private function escPos(?string $fiscalQrUrl): string
    {
        return (new EscPosTicketBuilder(['type' => 'network', 'host' => '127.0.0.1', 'port' => 9100, 'paperWidth' => 80, 'enabled' => true]))
            ->build($this->ticket($fiscalQrUrl));
    }

    public function testEscPosPrintsTheTaxQrBeforeEverythingElse(): void
    {
        $bytes = $this->escPos(self::URL);

        $title = strpos($bytes, 'QR tributario:');
        self::assertNotFalse($title);
        self::assertLessThan(strpos($bytes, 'Mi Tienda'), $title, 'the tax QR comes first');
        self::assertNotFalse(strpos($bytes, self::URL, $title));
        self::assertNotFalse(strpos($bytes, 'VERI*FACTU', $title));
        // GS ( k: error correction M (function 69, value '1') and 6 dots per module (function 67).
        self::assertStringContainsString("\x1d(k\x03\x001E1", $bytes);
        self::assertStringContainsString("\x1d(k\x03\x001C\x06", $bytes);
    }

    public function testEscPosWithoutTheSwitchHasNoTaxQr(): void
    {
        $bytes = $this->escPos(null);

        self::assertStringNotContainsString('QR tributario', $bytes);
        self::assertStringNotContainsString('VERI*FACTU', $bytes);
    }

    public function testPdfShowsTheTitleAndTheLegendAroundTheQr(): void
    {
        $generator = new TicketPdfGenerator(new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates')));
        $dir = sys_get_temp_dir() . '/tpv_fiscal_pdf_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (['on' => self::URL, 'off' => null] as $name => $url) {
                file_put_contents("$dir/$name.pdf", $generator->generate($this->ticket($url)));
                exec('pdftotext ' . escapeshellarg("$dir/$name.pdf") . ' ' . escapeshellarg("$dir/$name.txt"), $out, $code);
                self::assertSame(0, $code);
            }
            $on = (string) file_get_contents("$dir/on.txt");
            self::assertStringContainsString('QR tributario:', $on);
            self::assertStringContainsString('VERI*FACTU', $on);
            self::assertLessThan(strpos($on, 'Mi Tienda'), strpos($on, 'QR tributario:'));
            self::assertStringNotContainsString('QR tributario', (string) file_get_contents("$dir/off.txt"));
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }
}
