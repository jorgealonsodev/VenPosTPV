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
    private function render(?CreditNoteInfo $note, string $barcodePath = '', bool $giftMode = false): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 4) . '/templates'));

        return $twig->render('ticket/print.html.twig', [
            'ticket' => new TicketData(
                orderId: 42, reference: $note?->number ?? 'REF42', storeName: 'Tienda', storeAddress: '', ticketHeader: '', ticketFooter: '',
                ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-03 12:00'), cashierName: 'Ana',
                lines: [new TicketLine('Camisa M', 1, 24.18, 24.18)], subtotal: 19.98, tax: 4.2, total: 24.18,
                paymentMethod: 'cash', amountTendered: 30.0, changeDue: 5.82, verificationUrl: 'http://tpv/v', creditNote: $note,
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
}
