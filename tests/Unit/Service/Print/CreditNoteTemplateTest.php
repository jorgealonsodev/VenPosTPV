<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Print\CreditNoteInfo;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;

final class CreditNoteTemplateTest extends TestCase
{
    private function render(?CreditNoteInfo $note, string $barcodePath = '', bool $giftMode = false, string $method = 'cash', float $tendered = 30.0, float $change = 5.82, ?float $exchangeCredit = null, ?string $exchangeCreditNote = null): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 4) . '/templates'));

        return $twig->render('ticket/print.html.twig', [
            'ticket' => new TicketData(
                orderId: 42, reference: $note?->number ?? 'REF42', storeName: 'Tienda', storeAddress: '', ticketHeader: '', ticketFooter: '',
                ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-03 12:00'), cashierName: 'Ana',
                lines: [new TicketLine('Camisa M', 1, 24.18, 24.18)], subtotal: 19.98, tax: 4.2, total: 24.18,
                paymentMethod: $method, amountTendered: $tendered, changeDue: $change, verificationUrl: 'http://tpv/v', creditNote: $note,
                exchangeCredit: $exchangeCredit, exchangeCreditNote: $exchangeCreditNote,
            ),
            'giftMode' => $giftMode,
            'qrPath' => '',
            'barcodePath' => $barcodePath,
            'logoPath' => '',
        ]);
    }

    public function testCreditNoteTemplateShowsDocumentDataInsteadOfTenderedCash(): void
    {
        $html = $this->render(new CreditNoteInfo('R2026-000007', 'REF42', new \DateTimeImmutable('2026-10-30 10:00'), 'Defectuoso', 24.18, 0.0));

        $this->assertStringContainsString('ABONO R2026-000007', $html);
        $this->assertStringContainsString('Rectifica: REF42 (30/10/2026)', $html);
        $this->assertStringContainsString('Motivo: Defectuoso', $html);
        $this->assertStringContainsString('TOTAL DEVUELTO: 24,18', $html);
        $this->assertStringContainsString('Efectivo: 24,18', $html);
        $this->assertStringNotContainsString('Entrega', $html);
        $this->assertStringNotContainsString('Tarjeta:', $html);
    }

    public function testCreditNoteWithoutTicketShowsSinTicketAndTheVoucher(): void
    {
        $html = $this->render(new CreditNoteInfo('R2026-000008', 'Sin ticket', null, 'No le gusta', 0.0, 0.0, 'VALE2345', 24.18));

        $this->assertStringContainsString('Rectifica: Sin ticket</div>', $html);
        $this->assertStringContainsString('Vale: VALE2345  24,18 €', $html);
        $this->assertStringNotContainsString('Efectivo:', $html);
    }

    public function testCreditNoteWithTicketHasNoVoucherLine(): void
    {
        $html = $this->render(new CreditNoteInfo('R2026-000007', 'REF42', null, 'Defectuoso', 24.18, 0.0));

        $this->assertStringNotContainsString('Vale:', $html);
    }

    public function testSaleTicketIsUnchanged(): void
    {
        $html = $this->render(null);

        $this->assertStringContainsString('TOTAL: 24,18', $html);
        $this->assertStringContainsString('Entrega: 30,00', $html);
        $this->assertStringNotContainsString('ABONO', $html);
    }

    public function testSaleTicketShowsTheCode128WithItsReferenceUnderIt(): void
    {
        $html = $this->render(null, 'var/ticket-assets/tpv_barcode_abc.png');

        $this->assertMatchesRegularExpression('#<div class="barcode">\s*<img src="var/ticket-assets/tpv_barcode_abc.png"[^>]*>\s*<div>REF42</div>#', $html);
    }

    public function testGiftTicketKeepsTheCode128ForExchanges(): void
    {
        $html = $this->render(null, 'var/ticket-assets/tpv_barcode_abc.png', true);

        $this->assertStringContainsString('tpv_barcode_abc.png', $html);
        $this->assertStringNotContainsString('TOTAL:', $html);
    }

    public function testCreditNoteHasNoCode128(): void
    {
        $html = $this->render(new CreditNoteInfo('R2026-000007', 'REF42', null, 'Defectuoso', 24.18, 0.0), 'var/ticket-assets/tpv_barcode_abc.png');

        $this->assertStringNotContainsString('tpv_barcode_abc.png', $html);
        $this->assertStringNotContainsString('class="barcode"', $html);
    }

    public function testOrdinarySaleTicketRendersTheSameHtmlAsBeforeExchanges(): void
    {
        // sha1 of the HTML rendered before the exchange credit was added (HEAD 8f64b2a).
        $this->assertSame('42b86e5e5a967cf0534d91890dc10da23c4cc10a', sha1($this->render(null)));
    }

    public function testOrdinaryCreditNoteRendersTheSameHtmlAsBeforeExchanges(): void
    {
        $note = new CreditNoteInfo('R2026-000007', 'REF42', new \DateTimeImmutable('2026-10-30 10:00'), 'Defectuoso', 10.08, 14.1);

        $this->assertSame('30bc21b9cc97d2e52bc501e4c385b8c1b1ba7073', sha1($this->render($note, method: 'mixed', tendered: 0.0, change: 0.0)));
    }

    public function testExchangeCreditNoteStatesTheAmountAppliedAndOnlyThePaidOutPart(): void
    {
        $note = new CreditNoteInfo('R2026-000009', 'REF42', null, 'Talla o color', 0.0, 15.0, exchangeCredit: 40.0);
        $html = $this->render($note, method: 'card', tendered: 0.0, change: 0.0);

        $this->assertStringContainsString('<div>Aplicado a cambio: 40,00 €</div>', $html);
        $this->assertStringContainsString('Tarjeta: 15,00', $html);
        $this->assertLessThan(strpos($html, 'Tarjeta: 15,00'), strpos($html, 'Aplicado a cambio'));
        $this->assertStringNotContainsString('Efectivo:', $html);
    }

    public function testExchangeSaleShowsTheAppliedCreditBeforeTheTenderedMoney(): void
    {
        $html = $this->render(null, tendered: 20.0, change: 5.0, exchangeCredit: 9.18, exchangeCreditNote: 'R2026-000001');

        $this->assertStringContainsString('<div>Abono aplicado: 9,18 €</div>', $html);
        $this->assertStringContainsString('<div>Abono: R2026-000001</div>', $html);
        $this->assertStringContainsString('<div>Metodo: Abono + Efectivo</div>', $html);
        $this->assertLessThan(strpos($html, 'Entrega: 20,00'), strpos($html, 'Abono aplicado'));
        $this->assertStringContainsString('Cambio: 5,00', $html);
    }

    public function testSalePaidEntirelyByTheCreditShowsAbonoAndNoTenderedCash(): void
    {
        $html = $this->render(null, method: 'exchange', tendered: 0.0, change: 0.0, exchangeCredit: 24.18);

        $this->assertStringContainsString('<div>Abono aplicado: 24,18 €</div>', $html);
        $this->assertStringContainsString('<div>Metodo: Abono</div>', $html);
        $this->assertStringNotContainsString('Exchange', $html);
        $this->assertStringNotContainsString('Abono: ', $html);
        $this->assertStringNotContainsString('Entrega', $html);
    }

    public function testOnlineOrderCreditNoteShowsThePendingWebRefundInsteadOfCashOrCard(): void
    {
        $note = new CreditNoteInfo('R2026-000011', 'Pedido online XKBKNABJK', new \DateTimeImmutable('2026-10-20 10:00'), 'Talla o color', 0.0, 0.0, webRefundAmount: 24.18);
        $html = $this->render($note, method: 'online', tendered: 0.0, change: 0.0);

        $this->assertStringContainsString('Rectifica: Pedido online XKBKNABJK (20/10/2026)', $html);
        $this->assertStringContainsString('<div class="bold">Reembolso pendiente en la web: 24,18 €</div>', $html);
        $this->assertStringNotContainsString('Efectivo:', $html);
        $this->assertStringNotContainsString('Tarjeta:', $html);
    }
}
