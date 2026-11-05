<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;

/**
 * docs/prd/factura-completa.md phase 0 (art. 7.1.f RD 1619/2012): a sale ticket shows
 * the VAT rate, one line per rate with its VAT and base, on the PDF and on ESC/POS.
 */
final class TicketVatBreakdownTest extends TestCase
{
    /** @param list<array{rate: float, base: float, vat: float}> $breakdown */
    private function ticket(array $breakdown, ?string $replacedBy = null): TicketData
    {
        return new TicketData(
            orderId: 42, reference: 'A26000042', storeName: 'Tienda', storeAddress: '', ticketHeader: '', ticketFooter: '',
            ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-05 12:00'), cashierName: 'Ana',
            lines: [new TicketLine('Camiseta', 4, 26.5, 106.0), new TicketLine('Libro', 1, 11.0, 11.0)],
            subtotal: 97.6, tax: 19.4, total: 117.0,
            paymentMethod: 'card', amountTendered: 117.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
            vatBreakdown: $breakdown,
            replacedByInvoice: $replacedBy,
        );
    }

    private const BREAKDOWN = [
        ['rate' => 21.0, 'base' => 87.6, 'vat' => 18.4],
        ['rate' => 10.0, 'base' => 10.0, 'vat' => 1.0],
    ];

    private function html(TicketData $ticket): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 4) . '/templates'));

        return $twig->render('ticket/print.html.twig', ['ticket' => $ticket, 'giftMode' => false, 'qrPath' => '', 'barcodePath' => '', 'logoPath' => '']);
    }

    private function escPos(TicketData $ticket): string
    {
        return (new EscPosTicketBuilder(['type' => 'network', 'host' => '127.0.0.1', 'port' => 9100, 'paperWidth' => 80, 'enabled' => true]))->build($ticket);
    }

    public function testThePdfTicketShowsOneLinePerVatRate(): void
    {
        $html = $this->html($this->ticket(self::BREAKDOWN));

        $this->assertStringContainsString("<div class=\"right\">IVA 21 %: 18,40 € (base 87,60 €)</div>\n        <div class=\"right\">IVA 10 %: 1,00 € (base 10,00 €)</div>\n        <div class=\"right bold large\">TOTAL: 117,00 €</div>", $html);
        $this->assertStringNotContainsString('IVA: ', $html);
    }

    public function testThePdfTicketOfASaleWithoutStoredLinesKeepsTheSingleVatLine(): void
    {
        $html = $this->html($this->ticket([]));

        $this->assertStringContainsString("<div class=\"right\">IVA: 19,40 €</div>\n        <div class=\"right bold large\">TOTAL: 117,00 €</div>", $html);
    }

    public function testTheEscPosTicketShowsOneLinePerVatRate(): void
    {
        $bytes = $this->escPos($this->ticket(self::BREAKDOWN));

        // The printer code page writes € as 0x80.
        $this->assertStringContainsString("IVA 21 %: 18.40 \x80 (base 87.60 \x80)\nIVA 10 %: 1.00 \x80 (base 10.00 \x80)\n", $bytes);
        $this->assertStringNotContainsString('IVA: ', $bytes);
    }

    public function testTheEscPosTicketOfASaleWithoutStoredLinesKeepsTheSingleVatLine(): void
    {
        $this->assertStringContainsString("IVA: 19.40 \x80\n", $this->escPos($this->ticket([])));
    }

    public function testRatesWithDecimalsAreWrittenWithAComma(): void
    {
        $this->assertSame('21 %', TicketData::formatRate(21.0));
        $this->assertSame('5,5 %', TicketData::formatRate(5.5));
        $this->assertSame('0 %', TicketData::formatRate(0.0));
    }

    public function testAReprintOfARedeemedTicketSaysWhichInvoiceReplacedIt(): void
    {
        $this->assertStringContainsString("<div class=\"center bold\">Sustituido por la factura F26000004</div>\n    <div class=\"footer\">", $this->html($this->ticket([], 'F26000004')));
        $this->assertStringContainsString("Sustituido por la factura F26000004\n", $this->escPos($this->ticket([], 'F26000004')));
        $this->assertStringNotContainsString('Sustituido', $this->html($this->ticket([])));
        $this->assertStringNotContainsString('Sustituido', $this->escPos($this->ticket([])));
    }
}
