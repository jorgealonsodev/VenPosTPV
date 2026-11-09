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

    public function testTheTicketSaysWhoServedTheCustomer(): void
    {
        $bytes = $this->createBuilder()->build($this->createTicketData());

        self::assertStringContainsString('Le ha atendido Juan', $bytes);
        self::assertStringNotContainsString('Cajero:', $bytes);
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

    private function withLogo(?string $logo): TicketData
    {
        $t = $this->createTicketData();

        return new TicketData(
            orderId: $t->orderId, reference: $t->reference, storeName: $t->storeName, storeAddress: $t->storeAddress,
            ticketHeader: $t->ticketHeader, ticketFooter: $t->ticketFooter, ticketLogo: $logo, ticketWidthPx: $t->ticketWidthPx,
            date: $t->date, cashierName: $t->cashierName, lines: $t->lines, subtotal: $t->subtotal, tax: $t->tax, total: $t->total,
            paymentMethod: $t->paymentMethod, amountTendered: $t->amountTendered, changeDue: $t->changeDue, verificationUrl: $t->verificationUrl,
        );
    }

    private function pngDataUri(int $width, int $height, bool $transparent): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, $transparent ? 127 : 0));
        imagefilledrectangle($img, (int) ($width / 4), (int) ($height / 4), (int) ($width * 3 / 4), (int) ($height * 3 / 4), imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    public function testLogoIsPrintedAsARasterImageScaledToThePaper(): void
    {
        $out = $this->createBuilder()->build($this->withLogo($this->pngDataUri(1254, 1254, true)));

        // GS v 0: raster bit image. xL xH is the width in bytes: the logo is shrunk to
        // the print area, 544 dots = 68 bytes with the default 2 mm margins.
        $pos = strpos($out, "\x1dv0");
        $this->assertNotFalse($pos, 'the logo is sent as a raster image');
        $widthBytes = ord($out[$pos + 4]) + 256 * ord($out[$pos + 5]);
        $this->assertSame(68, $widthBytes);
        $this->assertLessThan(strpos($out, 'Mi Tienda'), $pos, 'logo goes above the store name');
    }

    public function testInvalidOrMissingLogoNeverBreaksTheTicket(): void
    {
        foreach ([null, '', 'data:image/png;base64,not-an-image', '/uploads/logo.png'] as $logo) {
            $out = $this->createBuilder()->build($this->withLogo($logo));

            $this->assertStringNotContainsString("\x1dv0", $out);
            $this->assertStringContainsString('Mi Tienda', $out);
        }
    }

    /**
     * The bytes as they were before the till margins existed: the only differences
     * since then are the default print area (GS L 16 dots, GS W 544 dots, sent once
     * after the character table) and the separators, now as wide as its 45 columns
     * instead of a fixed 32. Every text line of these tickets is unchanged.
     */
    private static function withoutPrintArea(string $bytes): string
    {
        $area = "\x1b\x74\x10\x1dL\x10\x00\x1dW\x20\x02";
        self::assertSame(1, substr_count($bytes, $area), 'the default print area follows the character table once');

        return str_replace([$area, "\n" . str_repeat('-', 45) . "\n"], ["\x1b\x74\x10", "\n" . str_repeat('-', 32) . "\n"], $bytes);
    }

    /**
     * Sale or credit note sharing one layout, to compare against the bytes printed
     * before the exchange credit existed.
     */
    private function documentTicket(?CreditNoteInfo $note, string $method = 'cash', float $tendered = 30.0, float $change = 5.82, ?float $exchangeCredit = null, ?string $exchangeCreditNote = null): TicketData
    {
        return new TicketData(
            orderId: 42, reference: $note?->number ?? 'REF42', storeName: 'Tienda', storeAddress: '', ticketHeader: '', ticketFooter: '',
            ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-03 12:00'), cashierName: 'Ana',
            lines: [new TicketLine('Camisa M', 1, 24.18, 24.18)], subtotal: 19.98, tax: 4.2, total: 24.18,
            paymentMethod: $method, amountTendered: $tendered, changeDue: $change, verificationUrl: 'http://tpv/v', creditNote: $note,
            exchangeCredit: $exchangeCredit, exchangeCreditNote: $exchangeCreditNote,
        );
    }

    public function testOrdinarySaleTicketPrintsTheSameBytesAsBeforeExchanges(): void
    {
        $out = $this->createBuilder()->build($this->documentTicket(null));

        // sha1 of the bytes printed before the exchange credit was added (HEAD 8f64b2a).
        $this->assertSame('538ac0deb92143ce0c89ef764a17be8b94e52ee8', sha1(self::withOldCashierLine(self::withoutPrintArea($out))));
        $this->assertStringNotContainsString('Abono', $out);
    }

    public function testOrdinaryCreditNotePrintsTheSameBytesAsBeforeExchanges(): void
    {
        $note = new CreditNoteInfo('R2026-000007', 'REF42', new \DateTimeImmutable('2026-10-30 10:00'), 'Defectuoso', 10.08, 14.1);
        $out = $this->createBuilder()->build($this->documentTicket($note, 'mixed', 0.0, 0.0));

        $this->assertSame('7869077be0a458105af52ff1160d4c65f6dbd1be', sha1(self::withOldCashierLine(self::withoutPrintArea($out))));
        $this->assertStringNotContainsString('cambio', $out);
    }

    public function testExchangeCreditNoteStatesTheAmountAppliedToTheExchange(): void
    {
        $note = new CreditNoteInfo('R2026-000009', 'REF42', null, 'Talla o color', 0.0, 0.0, exchangeCredit: 24.18);
        $out = $this->createBuilder()->build($this->documentTicket($note, 'cash', 0.0, 0.0));

        $this->assertStringContainsString('TOTAL DEVUELTO: 24.18', $out);
        $this->assertStringContainsString('Aplicado a cambio: 24.18', $out);
        $this->assertStringNotContainsString('Efectivo:', $out);
        $this->assertStringNotContainsString('Tarjeta:', $out);
    }

    public function testExchangeCreditNoteWithMoneyBackPrintsOnlyThePaidOutPart(): void
    {
        // 55 returned for 40 of new goods: 40 applied, 15 back to the card.
        $note = new CreditNoteInfo('R2026-000009', 'REF42', null, 'Talla o color', 0.0, 15.0, exchangeCredit: 40.0);
        $out = $this->createBuilder()->build($this->documentTicket($note, 'card', 0.0, 0.0));

        $applied = strpos($out, 'Aplicado a cambio: 40.00');
        $card = strpos($out, 'Tarjeta: 15.00');
        $this->assertNotFalse($applied);
        $this->assertNotFalse($card);
        $this->assertLessThan($card, $applied);
        $this->assertStringNotContainsString('Efectivo:', $out);
    }

    public function testExchangeSaleShowsTheAppliedCreditBeforeTheTenderedMoney(): void
    {
        $out = $this->createBuilder()->build($this->documentTicket(null, 'cash', 20.0, 5.0, 9.18, 'R2026-000001'));

        $credit = strpos($out, 'Abono aplicado: 9.18');
        $this->assertNotFalse($credit);
        $this->assertStringContainsString('Abono: R2026-000001', $out);
        $this->assertStringContainsString('Metodo: Abono + Efectivo', $out);
        $this->assertLessThan(strpos($out, 'Entrega: 20.00'), $credit);
        $this->assertStringContainsString('Cambio: 5.00', $out);
    }

    public function testSalePaidEntirelyByTheCreditPrintsAbonoAndNoTenderedCash(): void
    {
        $out = $this->createBuilder()->build($this->documentTicket(null, 'exchange', 0.0, 0.0, 24.18));

        $this->assertStringContainsString('Abono aplicado: 24.18', $out);
        $this->assertStringContainsString('Metodo: Abono', $out);
        $this->assertStringNotContainsString('Metodo: exchange', $out);
        $this->assertStringNotContainsString('Abono: ', $out, 'no credit note number known');
        $this->assertStringNotContainsString('Entrega', $out);
    }

    public function testOnlineOrderCreditNotePrintsThePendingWebRefundInsteadOfCashOrCard(): void
    {
        $note = new CreditNoteInfo('R2026-000011', 'Pedido online XKBKNABJK', new \DateTimeImmutable('2026-10-20 10:00'), 'Talla o color', 0.0, 0.0, webRefundAmount: 24.18);
        $out = $this->createBuilder()->build($this->documentTicket($note, 'online', 0.0, 0.0));

        $this->assertStringContainsString('Rectifica: Pedido online XKBKNABJK (20/10/2026)', $out);
        $this->assertStringContainsString('TOTAL DEVUELTO: 24.18', $out);
        $this->assertStringContainsString('Reembolso pendiente en la web: 24.18', $out);
        $this->assertStringNotContainsString('Efectivo:', $out);
        $this->assertStringNotContainsString('Tarjeta:', $out);
    }

    public function testOnlineExchangeCreditNoteShowsTheAppliedCreditAndOnlyTheWebRefundOwed(): void
    {
        $note = new CreditNoteInfo('R2026-000012', 'Pedido online XKBKNABJK', null, 'Talla o color', 0.0, 0.0, exchangeCredit: 40.0, webRefundAmount: 15.0);
        $out = $this->createBuilder()->build($this->documentTicket($note, 'online', 0.0, 0.0));

        $this->assertStringContainsString('Aplicado a cambio: 40.00', $out);
        $this->assertStringContainsString('Reembolso pendiente en la web: 15.00', $out);

        $nothingOwed = new CreditNoteInfo('R2026-000013', 'Pedido online XKBKNABJK', null, 'Talla o color', 0.0, 0.0, exchangeCredit: 24.18, webRefundAmount: 0.0);
        $this->assertStringNotContainsString('Reembolso pendiente', $this->createBuilder()->build($this->documentTicket($nothingOwed, 'online', 0.0, 0.0)));
    }

    public function testIssuerLegalNameAndTaxIdArePrintedUnderTheStoreName(): void
    {
        $sale = $this->createTicketData();
        $ticket = new TicketData(
            orderId: 42, reference: 'ORD-042', storeName: 'Mi Tienda', storeAddress: $sale->storeAddress, ticketHeader: '', ticketFooter: '',
            ticketLogo: null, ticketWidthPx: null, date: $sale->date, cashierName: 'Juan', lines: $sale->lines, subtotal: 3.8, tax: 0.2,
            total: 4.0, paymentMethod: 'cash', amountTendered: 5.0, changeDue: 1.0, verificationUrl: 'https://example.com/v',
            legalName: 'Mi Tienda Moda SL', taxId: 'B12345674',
        );

        $bytes = $this->createBuilder()->build($ticket);

        self::assertStringContainsString("Mi Tienda Moda SL\n", $bytes);
        self::assertStringContainsString("NIF: B12345674\n", $bytes);
        self::assertLessThan(strpos($bytes, 'Calle Falsa'), strpos($bytes, 'NIF: B12345674'));
    }

    public function testNoIssuerLinesWhenTaxIdAndLegalNameAreEmpty(): void
    {
        $bytes = $this->createBuilder()->build($this->createTicketData());

        self::assertStringNotContainsString('NIF:', $bytes);
    }

    private function localSale(?string $webOrderReference = null): TicketData
    {
        return new TicketData(
            orderId: 42, reference: 'A26000123', storeName: 'Mi Tienda', storeAddress: 'Calle Falsa 123', ticketHeader: '', ticketFooter: 'Gracias',
            ticketLogo: null, ticketWidthPx: 576, date: new \DateTimeImmutable('2024-01-15 14:30:00'), cashierName: 'Juan',
            lines: [new TicketLine('Pan', 1, 0.8, 0.8)], subtotal: 0.66, tax: 0.14, total: 0.8, paymentMethod: 'cash',
            amountTendered: 1.0, changeDue: 0.2, verificationUrl: 'https://example.com/verify?token=abc',
            webOrderReference: $webOrderReference,
        );
    }

    /** PRD S5: PrestaShop answered another reference; the reprint names it under the ticket code. */
    public function testASaleWithADifferentPrestaShopReferencePrintsPedidoWebUnderTheReference(): void
    {
        $bytes = $this->createBuilder()->build($this->localSale('XKQWERTYU'));

        self::assertStringContainsString("Ref: A26000123\nPedido web: XKQWERTYU\n", $bytes);
    }

    public function testATicketWithoutAWebReferencePrintsTheSameBytesAsBefore(): void
    {
        // sha1 of the bytes built before "Pedido web" existed (HEAD e868074).
        self::assertSame('fa4af74b27a71be4d891fd22ccca15fdf83c07a5', sha1(self::withOldCashierLine(self::withoutPrintArea($this->createBuilder()->build($this->localSale())))));
        self::assertSame('0e1c9ffe288876ade23487e4f07824b11ab5e3e1', sha1(self::withOldCashierLine(self::withoutPrintArea($this->createBuilder()->build($this->localSale(), true)))));
        self::assertStringNotContainsString('Pedido web', $this->createBuilder()->build($this->localSale('')));
    }

    /** The output as it was before the cashier line read «Le ha atendido» (it was «Cajero: »). */
    private static function withOldCashierLine(string $output): string
    {
        return str_replace('Le ha atendido ', 'Cajero: ', $output);
    }
}
