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
 * A voucher worth more than the sale leaves a new one with the rest: the ticket
 * says so («Saldo pendiente del vale: ABCD1234-2 — 14,00 €»), on the PDF and on
 * ESC/POS, and only when there is one.
 */
final class TicketLeftoverVoucherTest extends TestCase
{
    /** @param array{code: string, amount: float}|null $leftover */
    private function ticket(?array $leftover): TicketData
    {
        return new TicketData(
            orderId: 42, reference: 'XKBKNABJK', storeName: 'Tienda', storeAddress: '', ticketHeader: '', ticketFooter: '',
            ticketLogo: null, ticketWidthPx: null, date: new \DateTimeImmutable('2026-11-09 12:00'), cashierName: 'Ana',
            lines: [new TicketLine('Chaqueta', 1, 36.0, 36.0)],
            subtotal: 29.75, tax: 6.25, total: 0.0,
            paymentMethod: 'cash', amountTendered: 0.0, changeDue: 0.0, verificationUrl: 'http://tpv/v',
            leftoverVoucher: $leftover,
        );
    }

    private function html(TicketData $ticket): string
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 4) . '/templates'));

        return $twig->render('ticket/print.html.twig', ['ticket' => $ticket, 'giftMode' => false, 'qrPath' => '', 'barcodePath' => '', 'logoPath' => '']);
    }

    private function escPos(TicketData $ticket): string
    {
        return (new EscPosTicketBuilder(['type' => 'network', 'host' => '127.0.0.1', 'port' => 9100, 'paperWidth' => 80, 'enabled' => true]))->build($ticket);
    }

    public function testThePdfTicketShowsTheVoucherRest(): void
    {
        $html = $this->html($this->ticket(['code' => 'ABCD1234-2', 'amount' => 14.0]));

        $this->assertStringContainsString('<div class="bold">Saldo pendiente del vale: ABCD1234-2 — 14,00 €</div>', $html);
    }

    public function testTheEscPosTicketShowsTheVoucherRest(): void
    {
        // The printer code page has no long dash, so ESC/POS writes «-»; € is 0x80.
        $this->assertStringContainsString("Saldo pendiente del vale: ABCD1234-2 - 14.00 \x80\n", $this->escPos($this->ticket(['code' => 'ABCD1234-2', 'amount' => 14.0])));
    }

    public function testATicketWithoutVoucherRestIsUnchanged(): void
    {
        $this->assertStringNotContainsString('Saldo pendiente', $this->html($this->ticket(null)));
        $this->assertStringNotContainsString('Saldo pendiente', $this->escPos($this->ticket(null)));
    }
}
