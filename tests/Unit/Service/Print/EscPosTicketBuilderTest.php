<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\CreditNoteInfo;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;

final class EscPosTicketBuilderTest extends TestCase
{
    private function createBuilder(): EscPosTicketBuilder
    {
        return new EscPosTicketBuilder([
            'type' => 'network',
            'host' => '127.0.0.1',
            'port' => 9100,
            'paperWidth' => 80,
            'enabled' => true,
        ]);
    }

    private function createTicketData(): TicketData
    {
        return new TicketData(
            orderId: 42,
            reference: 'ORD-042',
            storeName: 'Mi Tienda',
            storeAddress: 'Calle Falsa 123, 28001 Madrid',
            ticketHeader: 'Horario: L-V 09:00-20:00',
            ticketFooter: 'Gracias por su compra',
            ticketLogo: '/uploads/tickets/logo.png',
            ticketWidthPx: 576,
            date: new \DateTimeImmutable('2024-01-15 14:30:00'),
            cashierName: 'Juan',
            lines: [
                new TicketLine('Café Ñoño €1.50', 2, 1.5, 3.0),
                new TicketLine('Pan', 1, 0.8, 0.8),
            ],
            subtotal: 3.8,
            tax: 0.2,
            total: 4.0,
            paymentMethod: 'cash',
            amountTendered: 5.0,
            changeDue: 1.0,
            verificationUrl: 'https://example.com/verify?token=abc',
        );
    }

    public function testBuildReturnsNonEmptyByteString(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertNotEmpty($bytes);
        self::assertGreaterThan(100, \strlen($bytes));
    }

    public function testBuildContainsHeaderText(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('Mi Tienda', $bytes);
        self::assertStringContainsString('Horario: L-V 09:00-20:00', $bytes);
        self::assertStringContainsString('ORD-042', $bytes);
        self::assertStringContainsString('Juan', $bytes);
    }

    public function testBuildContainsSpanishCharacters(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        // In CodePage 858, é=0x82, Ñ=0xA5, ñ=0xA4, €=0xD5
        // The ASCII prefix should be present
        self::assertStringContainsString('Caf', $bytes);
        // Verify codepage selection command is present: ESC t 16
        self::assertStringContainsString("\x1b\x74\x10", $bytes);
    }

    public function testBuildContainsLineItems(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('Pan', $bytes);
        self::assertStringContainsString('TOTAL', $bytes);
    }

    public function testBuildContainsTotalsAndPayment(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('4.00', $bytes);
        self::assertStringContainsString('Efectivo', $bytes);
        self::assertStringContainsString('Cambio', $bytes);
    }

    public function testBuildContainsFooterAndCut(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('Gracias por su compra', $bytes);
        // ESC/POS cut command: GS V
        self::assertStringContainsString("\x1d\x56", $bytes);
    }

    public function testBuildWithGiftModeContainsItemNamesButNoPrices(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData(), true);

        self::assertStringContainsString('Pan', $bytes);
        self::assertStringContainsString('Caf', $bytes);
        // No monetary totals should appear
        self::assertStringNotContainsString('TOTAL:', $bytes);
        self::assertStringNotContainsString('Subtotal:', $bytes);
        self::assertStringNotContainsString('IVA:', $bytes);
        self::assertStringNotContainsString('Efectivo', $bytes);
        self::assertStringNotContainsString('Entrega:', $bytes);
        self::assertStringNotContainsString('Cambio:', $bytes);
    }

    public function testBuildWithGiftModeOmitsUnitPricesAndLineTotals(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData(), true);

        // No price symbols or formatted prices in item lines (product name may contain digits)
        self::assertStringNotContainsString(' x ', $bytes);
        self::assertStringNotContainsString(' = ', $bytes);
        self::assertStringNotContainsString('€', $bytes);
    }

    public function testBuildWithGiftModeFalseIsIdenticalToDefault(): void
    {
        $builder = $this->createBuilder();
        $bytesDefault = $builder->build($this->createTicketData());
        $bytesExplicit = $builder->build($this->createTicketData(), false);

        self::assertSame($bytesDefault, $bytesExplicit);
    }

    public function testCreditNoteWithoutTicketPrintsSinTicketAndTheVoucherCodeAndAmount(): void
    {
        $sale = $this->createTicketData();
        $ticket = new TicketData(
            orderId: 0, reference: 'R2026-000008', storeName: $sale->storeName, storeAddress: $sale->storeAddress,
            ticketHeader: '', ticketFooter: '', ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-03 12:00'),
            cashierName: 'Ana', lines: [new TicketLine('Camisa (Talla: M)', 1, 30.25, 30.25)], subtotal: 25.0, tax: 5.25, total: 30.25,
            paymentMethod: 'voucher', amountTendered: 0.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
            creditNote: new CreditNoteInfo('R2026-000008', 'Sin ticket', null, 'No le gusta', 0.0, 0.0, 'VALE2345', 30.25),
        );

        $out = $this->createBuilder()->build($ticket);

        $this->assertStringContainsString('Rectifica: Sin ticket', $out);
        $this->assertStringNotContainsString('Rectifica: Sin ticket (', $out);
        $this->assertStringContainsString('Vale: VALE2345  30.25', $out);
        $this->assertStringNotContainsString('Efectivo:', $out);
        $this->assertStringNotContainsString('Tarjeta:', $out);
    }

    public function testCreditNoteWithTicketHasNoVoucherLine(): void
    {
        $sale = $this->createTicketData();
        $ticket = new TicketData(
            orderId: 42, reference: 'R2026-000007', storeName: $sale->storeName, storeAddress: $sale->storeAddress,
            ticketHeader: '', ticketFooter: '', ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-03 12:00'),
            cashierName: 'Ana', lines: [new TicketLine('Camisa M', 1, 24.18, 24.18)], subtotal: 19.98, tax: 4.2, total: 24.18,
            paymentMethod: 'cash', amountTendered: 0.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
            creditNote: new CreditNoteInfo('R2026-000007', 'REF42', null, 'Defectuoso', 24.18, 0.0),
        );

        $this->assertStringNotContainsString('Vale:', $this->createBuilder()->build($ticket));
    }

    public function testCreditNoteShowsNumberRectifiedTicketRefundSplitAndNoTenderedCash(): void
    {
        $sale = $this->createTicketData();
        $ticket = new TicketData(
            orderId: $sale->orderId, reference: 'R2026-000007', storeName: $sale->storeName, storeAddress: $sale->storeAddress,
            ticketHeader: '', ticketFooter: '', ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-03 12:00'),
            cashierName: 'Ana', lines: [new TicketLine('Camisa M', 1, 24.18, 24.18)], subtotal: 19.98, tax: 4.2, total: 24.18,
            paymentMethod: 'mixed', amountTendered: 0.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
            creditNote: new CreditNoteInfo('R2026-000007', 'REF42', new \DateTimeImmutable('2026-10-30 10:00'), 'Talla o color', 10.08, 14.1),
        );

        $out = $this->createBuilder()->build($ticket);

        $this->assertStringContainsString('ABONO', $out);
        $this->assertStringContainsString('R2026-000007', $out);
        $this->assertStringContainsString('Rectifica: REF42 (30/10/2026)', $out);
        $this->assertStringContainsString('Motivo: Talla o color', $out);
        $this->assertStringContainsString('TOTAL DEVUELTO: 24.18', $out);
        $this->assertStringContainsString('Efectivo: 10.08', $out);
        $this->assertStringContainsString('Tarjeta: 14.10', $out);
        $this->assertStringNotContainsString('Entrega', $out);
    }

    public function testSaleTicketPrintsCode128OfTheReferenceWithTextBelow(): void
    {
        $out = $this->createBuilder()->build($this->createTicketData());

        // GS k 73 (CODE128, function B) <len> "{B" + data
        self::assertStringContainsString("\x1dk\x49\x09{BORD-042", $out);
        self::assertStringContainsString("\x1dH\x02", $out, 'human readable reference below the bars');
    }

    public function testGiftTicketKeepsTheCode128(): void
    {
        $out = $this->createBuilder()->build($this->createTicketData(), true);

        self::assertStringContainsString("\x1dk\x49\x09{BORD-042", $out);
    }

    public function testLiteralBraceInReferenceIsEscapedForCode128(): void
    {
        $sale = $this->createTicketData();
        $ticket = new TicketData(
            orderId: 1, reference: 'A{B', storeName: 'T', storeAddress: '', ticketHeader: '', ticketFooter: '', ticketLogo: null,
            ticketWidthPx: null, date: $sale->date, cashierName: 'Ana', lines: $sale->lines, subtotal: 1.0, tax: 0.0, total: 1.0,
            paymentMethod: 'cash', amountTendered: 1.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
        );

        self::assertStringContainsString("\x1dk\x49\x06{BA{{B", $this->createBuilder()->build($ticket));
    }

    public function testReferenceThatCode128CannotEncodeSkipsTheBarcode(): void
    {
        $sale = $this->createTicketData();
        $ticket = new TicketData(
            orderId: 1, reference: 'Ñ-1', storeName: 'T', storeAddress: '', ticketHeader: '', ticketFooter: '', ticketLogo: null,
            ticketWidthPx: null, date: $sale->date, cashierName: 'Ana', lines: $sale->lines, subtotal: 1.0, tax: 0.0, total: 1.0,
            paymentMethod: 'cash', amountTendered: 1.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
        );

        $out = $this->createBuilder()->build($ticket);

        self::assertStringNotContainsString("\x1dk\x49", $out);
        self::assertStringContainsString("\x1d(k", $out, 'QR is still printed');
    }

    public function testCreditNoteHasNoCode128(): void
    {
        $sale = $this->createTicketData();
        $ticket = new TicketData(
            orderId: 42, reference: 'R2026-000007', storeName: 'T', storeAddress: '', ticketHeader: '', ticketFooter: '', ticketLogo: null,
            ticketWidthPx: null, date: $sale->date, cashierName: 'Ana', lines: $sale->lines, subtotal: 1.0, tax: 0.0, total: 1.0,
            paymentMethod: 'cash', amountTendered: 0.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
            creditNote: new CreditNoteInfo('R2026-000007', 'ORD-042', null, 'Defectuoso', 1.0, 0.0),
        );

        self::assertStringNotContainsString("\x1dk\x49", $this->createBuilder()->build($ticket));
    }

    private function withTicketCode(string $mode): TicketData
    {
        $t = $this->createTicketData();

        return new TicketData(
            orderId: $t->orderId, reference: $t->reference, storeName: $t->storeName, storeAddress: $t->storeAddress,
            ticketHeader: $t->ticketHeader, ticketFooter: $t->ticketFooter, ticketLogo: $t->ticketLogo, ticketWidthPx: $t->ticketWidthPx,
            date: $t->date, cashierName: $t->cashierName, lines: $t->lines, subtotal: $t->subtotal, tax: $t->tax, total: $t->total,
            paymentMethod: $t->paymentMethod, amountTendered: $t->amountTendered, changeDue: $t->changeDue, verificationUrl: $t->verificationUrl,
            ticketCode: $mode,
        );
    }

    public function testTicketCodeSettingChoosesBarcodeQrOrBoth(): void
    {
        $qr = "\x1d(k";
        $barcode = "\x1dk";

        $barcodeOnly = $this->createBuilder()->build($this->withTicketCode('barcode'));
        $this->assertStringContainsString($barcode, $barcodeOnly);
        $this->assertStringNotContainsString($qr, $barcodeOnly);

        $qrOnly = $this->createBuilder()->build($this->withTicketCode('qr'));
        $this->assertStringContainsString($qr, $qrOnly);
        $this->assertStringNotContainsString($barcode, $qrOnly);

        $both = $this->createBuilder()->build($this->withTicketCode('both'));
        $this->assertStringContainsString($qr, $both);
        $this->assertStringContainsString($barcode, $both);
    }
}
