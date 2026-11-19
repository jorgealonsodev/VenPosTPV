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

/**
 * The gift ticket printed next to the sale ticket: titled «TICKET REGALO», the same
 * lines and quantities without any price, and none of the QRs (the VERI*FACTU one
 * carries the amount); the barcode stays so the refund screen can scan it.
 */
final class GiftTicketTest extends TestCase
{
    private const FISCAL_URL = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=G12345678&numserie=A26000123&fecha=05-11-2026&importe=38.00';

    private function ticket(): TicketData
    {
        return new TicketData(
            orderId: 42, reference: 'A26000123', storeName: 'Mi Tienda', storeAddress: 'Calle Falsa 123',
            ticketHeader: '', ticketFooter: '', ticketLogo: null, ticketWidthPx: 302,
            date: new \DateTimeImmutable('2026-11-05 10:00:00'), cashierName: 'Ana',
            lines: [new TicketLine('Bañador', 2, 19.0, 38.0)], subtotal: 38.0, tax: 6.6, total: 38.0,
            paymentMethod: 'cash', amountTendered: 40.0, changeDue: 2.0,
            verificationUrl: 'https://example.com/verify?token=abc', ticketCode: 'both', fiscalQrUrl: self::FISCAL_URL,
        );
    }

    private function escPos(bool $gift): string
    {
        return (new EscPosTicketBuilder(['paperWidth' => 80]))->build($this->ticket(), $gift);
    }

    public function testTheEscPosGiftTicketHasTheTitleTheLinesAndTheBarcodeButNoPriceNorQr(): void
    {
        $bytes = $this->escPos(true);

        self::assertStringContainsString('TICKET REGALO', $bytes);
        self::assertStringContainsString('Cant: 2', $bytes);
        self::assertStringContainsString('A26000123', $bytes);
        self::assertStringContainsString("\x1dk", $bytes, 'the Code128 barcode is printed');
        self::assertStringNotContainsString('€', $bytes);
        self::assertStringNotContainsString('38.00', $bytes);
        self::assertStringNotContainsString('QR tributario', $bytes);
        self::assertStringNotContainsString(self::FISCAL_URL, $bytes);
        self::assertStringNotContainsString('verify?token=abc', $bytes);
    }

    public function testTheEscPosSaleTicketKeepsItsPricesAndQrs(): void
    {
        $bytes = $this->escPos(false);

        self::assertStringNotContainsString('TICKET REGALO', $bytes);
        self::assertStringContainsString('38.00', $bytes);
        self::assertStringContainsString(self::FISCAL_URL, $bytes);
        self::assertStringContainsString('verify?token=abc', $bytes);
    }

    /** @return array<string, mixed> */
    private function pdfContext(bool $gift): array
    {
        $twig = $this->createMock(Environment::class);
        $context = [];
        $twig->method('render')->willReturnCallback(static function (string $template, array $c) use (&$context): string {
            $context = $c;

            return '<html></html>';
        });
        (new TicketPdfGenerator($twig))->generate($this->ticket(), $gift);

        return $context;
    }

    public function testThePdfGiftTicketGetsNoQrButKeepsTheBarcode(): void
    {
        $gift = $this->pdfContext(true);
        self::assertSame('', $gift['qrPath']);
        self::assertSame('', $gift['fiscalQrPath']);
        self::assertNotSame('', $gift['barcodePath']);

        $sale = $this->pdfContext(false);
        self::assertNotSame('', $sale['qrPath']);
        self::assertNotSame('', $sale['fiscalQrPath']);
    }

    public function testTheGiftTemplateIsTitledAndShowsNoPrice(): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates'));
        $render = fn (bool $gift): string => $twig->render('ticket/print.html.twig', [
            'ticket' => $this->ticket(), 'giftMode' => $gift, 'qrPath' => '', 'barcodePath' => '', 'logoPath' => '',
            'fiscalQrPath' => '', 'fiscalQrTitle' => '', 'fiscalQrLegend' => '',
        ]);

        $gift = $render(true);
        self::assertStringContainsString('TICKET REGALO', $gift);
        self::assertStringContainsString('Cant: 2', $gift);
        self::assertStringNotContainsString('€', $gift);

        $sale = $render(false);
        self::assertStringNotContainsString('TICKET REGALO', $sale);
        self::assertStringContainsString('38,00 €', $sale);
    }
}
