<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Invoice;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;
use VenPosTPV\Service\Fiscal\FiscalHash;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Invoice\InvoiceException;
use VenPosTPV\Service\Invoice\InvoiceIssuer;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketVerificationService;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/factura-completa.md phase 1: a ticket redeemed for an F3 invoice (F3),
 * numbered in the shop's F series (F4), with the VAT box of the ticket (F5) and its
 * own chained fiscal record (F7). Acceptance criteria 3-6 and 8, edge cases of §5.
 */
final class InvoiceIssuerMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;
    private InvoiceFixtures $fixtures;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixtures = new InvoiceFixtures($this->connection);
        $this->fixtures->reset();
        $this->fixtures->till();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->fixtures->reset();
            $this->connection->close();
        }
    }

    private function issuer(?FiscalRecorder $fiscal = null): InvoiceIssuer
    {
        \assert($this->connection !== null);

        return new InvoiceIssuer($this->connection, fiscal: $fiscal);
    }

    /** @return array{invoice: array<string, mixed>, created: bool, warnings: list<string>} */
    private function redeem(int $orderId, int $customerId, ?string $requestId = null, string $at = '2026-11-05 10:00:00', bool $consent = false): array
    {
        return $this->issuer()->redeem($orderId, $customerId, InvoiceFixtures::TILL, 1, $consent, $requestId, InvoiceFixtures::now($at));
    }

    private function refused(callable $issue): InvoiceException
    {
        try {
            $issue();
        } catch (InvoiceException $e) {
            return $e;
        }
        self::fail('The invoice was issued');
    }

    private function ticket1210(string $reference = 'A26000123', string $soldAt = '2026-11-03 18:30:00'): int
    {
        return $this->fixtures->sale($reference, $soldAt, [['name' => 'Gafas de natación', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]]);
    }

    // Criterion 3.
    public function testATicketIsRedeemedForAnF3InvoiceThatReplacesItAndIsChained(): void
    {
        \assert($this->connection !== null);
        $orderId = $this->ticket1210();
        $customerId = $this->fixtures->customer();
        $f2 = $this->connection->fetchAssociative('SELECT * FROM fiscal_record WHERE order_id = ?', [$orderId]);

        $result = $this->redeem($orderId, $customerId, consent: true);

        self::assertTrue($result['created']);
        self::assertSame([], $result['warnings']);
        $invoice = $result['invoice'];
        self::assertSame('F26000001', $invoice['number']);
        self::assertSame(['F3', 'F', 2026, 1], [$invoice['invoice_type'], $invoice['series'], $invoice['year'], $invoice['seq']]);
        self::assertSame($orderId, $invoice['sale_order_id']);
        self::assertSame('A26000123', $invoice['replaced_reference']);
        self::assertSame('2026-11-03', $invoice['replaced_date']);
        self::assertSame('2026-11-03', $invoice['operation_date']);
        self::assertSame('2026-11-05', $invoice['issue_date']);
        self::assertSame([['rate' => 21.0, 'base' => 10.0, 'vat' => 2.1]], $invoice['tax_breakdown']);
        self::assertSame([12.1, 2.1], [$invoice['total'], $invoice['tax_total']]);
        self::assertSame(['name' => 'Club Natación Ejemplo', 'tax_id' => 'G12345674', 'address' => 'Avenida del Agua 3, 33001 Oviedo'], $invoice['issuer']);
        self::assertSame([
            'customer_id' => $customerId, 'name' => 'Deportes Ejemplo SL', 'country' => 'ES', 'tax_id_type' => 'NI', 'tax_id' => 'B12345674',
            'address' => 'Calle Mayor 1', 'postcode' => '33002', 'city' => 'Oviedo', 'province' => 'Asturias',
        ], $invoice['recipient']);
        self::assertSame([['method' => 'cash', 'label' => 'Efectivo', 'amount' => 12.1]], $invoice['payments']);
        self::assertNotNull($invoice['email_consent_at'], 'the consent is stored with the invoice');
        self::assertSame(1, $this->fixtures->lastInvoiceNumber());

        $records = $this->connection->fetchAllAssociative('SELECT * FROM fiscal_record ORDER BY id');
        self::assertCount(2, $records);
        self::assertSame($f2, $records[0], 'the ticket keeps its F2 record unchanged');
        $f3 = $records[1];
        self::assertSame(['F3', 'F26000001', '12.10', '2.10', '05-11-2026', 'G12345674'], [$f3['invoice_type'], $f3['number'], $f3['total'], $f3['tax_total'], $f3['issue_date'], $f3['issuer_nif']]);
        self::assertSame([null, null, (string) $invoice['id']], [$f3['order_id'], $f3['refund_id'], (string) $f3['invoice_id']]);
        self::assertSame($f2['hash'], $f3['previous_hash']);
        self::assertSame(FiscalHash::alta('G12345674', 'F26000001', '05-11-2026', 'F3', '2.10', '12.10', (string) $f2['hash'], (string) $f3['generated_at']), $f3['hash']);
        self::assertSame($f3['hash'], $this->connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1'));
        $check = (new FiscalChainVerifier($this->connection))->verify();
        self::assertTrue($check['ok'], (string) $check['reason']);
        self::assertSame(2, $check['records']);
    }

    // Criterion 4 and §5 «Dos cajeros canjean el mismo ticket a la vez».
    public function testATicketIsRedeemedOnlyOnceAndASecondTryTakesNoNumber(): void
    {
        $orderId = $this->ticket1210();
        $customerId = $this->fixtures->customer();
        $this->redeem($orderId, $customerId);

        $e = $this->refused(fn () => $this->redeem($orderId, $customerId));

        self::assertSame(409, $e->status);
        self::assertSame('Este ticket ya se canjeó por la factura F26000001', $e->getMessage());
        self::assertSame('F26000001', $e->details['invoice_number']);
        self::assertSame(1, $this->fixtures->lastInvoiceNumber(), 'no number consumed');
        $other = $this->redeem($this->ticket1210('A26000124'), $customerId);
        self::assertSame('F26000002', $other['invoice']['number'], 'the series goes on without gaps');
    }

    public function testARepeatedRequestAnswersTheInvoiceItCreated(): void
    {
        $orderId = $this->ticket1210();
        $customerId = $this->fixtures->customer();
        $first = $this->redeem($orderId, $customerId, 'req-invoice-1');

        $again = $this->redeem($orderId, $customerId, 'req-invoice-1');

        self::assertFalse($again['created']);
        self::assertSame($first['invoice'], $again['invoice']);
        self::assertSame(1, $this->fixtures->lastInvoiceNumber());
    }

    // Criterion 5.
    public function testEachVatRateHasItsOwnBaseAndVat(): void
    {
        $orderId = $this->fixtures->sale('A26000130', '2026-11-05 09:00:00', [
            ['name' => 'Gorro', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00],
            ['name' => 'Libro', 'qty' => 1, 'price' => 11.00, 'price_net' => 10.00],
        ]);

        $invoice = $this->redeem($orderId, $this->fixtures->customer())['invoice'];

        self::assertSame([['rate' => 21.0, 'base' => 10.0, 'vat' => 2.1], ['rate' => 10.0, 'base' => 10.0, 'vat' => 1.0]], $invoice['tax_breakdown']);
        self::assertSame([23.1, 3.1], [$invoice['total'], $invoice['tax_total']]);
        self::assertSame('2026-11-05', $invoice['operation_date'], 'sold the same day');
    }

    // Criterion 6.
    public function testACartDiscountIsShownWithoutVatOnTheLineAndRoundedPerRate(): void
    {
        $orderId = $this->fixtures->sale('A26000131', '2026-11-05 09:00:00', [
            ['name' => 'Gorro de silicona', 'qty' => 3, 'price' => 9.99, 'price_net' => 8.26],
        ], 26.97);

        $invoice = $this->redeem($orderId, $this->fixtures->customer())['invoice'];

        self::assertSame([['rate' => 21.0, 'base' => 22.29, 'vat' => 4.68]], $invoice['tax_breakdown']);
        self::assertSame(26.97, $invoice['total']);
        $line = $invoice['lines'][0];
        self::assertSame([3, 9.99, 8.26, 2.48, 22.29, 21.0, 26.97], [$line['quantity'], $line['unit_price'], $line['unit_price_net'], $line['discount_net'], $line['net_amount'], $line['vat_rate'], $line['paid_amount']]);
    }

    // §5: an exchange credit is a payment form; the base is the whole sale.
    public function testAnExchangeCreditIsAPaymentAndDoesNotLowerTheBase(): void
    {
        $orderId = $this->fixtures->sale('A26000132', '2026-11-05 09:00:00', [
            ['name' => 'Bañador', 'qty' => 1, 'price' => 36.30, 'price_net' => 30.00],
        ], null, ['cash' => 0.0, 'card' => 26.30, 'exchange_credit' => 10.00]);

        $invoice = $this->redeem($orderId, $this->fixtures->customer())['invoice'];

        self::assertSame([['rate' => 21.0, 'base' => 30.0, 'vat' => 6.3]], $invoice['tax_breakdown']);
        self::assertSame([
            ['method' => 'card', 'label' => 'Tarjeta', 'amount' => 26.3],
            ['method' => 'exchange_credit', 'label' => 'Crédito de cambio', 'amount' => 10.0],
        ], $invoice['payments']);
    }

    // Criterion 8.
    public function testAFailedFiscalRecordLeavesNoInvoiceAndGivesTheNumberBack(): void
    {
        \assert($this->connection !== null);
        $orderId = $this->ticket1210();
        $customerId = $this->fixtures->customer();
        $failing = new class($this->connection) extends FiscalRecorder {
            public function recordInvoice(int $invoiceId, string $invoiceType, string $number, int $terminalId, float $total, float $tax, \DateTimeImmutable $issuedAt): void
            {
                throw new \RuntimeException('fiscal chain unavailable');
            }
        };

        try {
            $this->issuer($failing)->redeem($orderId, $customerId, InvoiceFixtures::TILL, 1, false, null, InvoiceFixtures::now());
            self::fail('The failure must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('fiscal chain unavailable', $e->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoice'));
        self::assertSame(0, $this->fixtures->lastInvoiceNumber());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fiscal_record'), 'only the F2 of the ticket');
        self::assertSame('F26000001', $this->redeem($orderId, $customerId)['invoice']['number'], 'the next invoice takes the same number');
    }

    // P3.
    public function testATicketWithRefundsIsNotRedeemed(): void
    {
        \assert($this->connection !== null);
        $orderId = $this->ticket1210();
        $this->connection->insert('refund', [
            'request_id' => 'invoice-test-refund', 'status' => 'done', 'order_id' => $orderId, 'terminal_id' => InvoiceFixtures::TILL,
            'session_id' => 0, 'user_id' => 1, 'reason_code' => 'other', 'total' => 12.10, 'cash_amount' => 12.10, 'card_amount' => 0,
        ]);

        $e = $this->refused(fn () => $this->redeem($orderId, $this->fixtures->customer()));

        self::assertSame([422, 'Este ticket tiene devoluciones; no se puede canjear'], [$e->status, $e->getMessage()]);
        self::assertSame(0, $this->fixtures->lastInvoiceNumber());
    }

    // P8: only tickets with exact sale_line rows.
    public function testATicketWithoutExactLinesIsNotRedeemed(): void
    {
        \assert($this->connection !== null);
        $customerId = $this->fixtures->customer();
        $noLines = $this->fixtures->sale('A26000140', '2026-11-05 09:00:00', [['name' => 'Gorro', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]], withLines: false);
        $approximate = $this->fixtures->sale('A26000141', '2026-11-05 09:00:00', [['name' => 'Gorro', 'qty' => 1, 'price' => 12.10, 'price_net' => null]]);

        foreach ([$noLines, $approximate] as $orderId) {
            $e = $this->refused(fn () => $this->redeem($orderId, $customerId));
            self::assertSame([422, InvoiceIssuer::NO_LINES_MESSAGE], [$e->status, $e->getMessage()]);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoice'));
    }

    // P4 and F5: 0 % needs an exemption cause, and none is configured.
    public function testASaleWithAZeroRateLineCannotBeInvoiced(): void
    {
        $orderId = $this->fixtures->sale('A26000142', '2026-11-05 09:00:00', [
            ['name' => 'Gorro', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00],
            ['name' => 'Cuota', 'qty' => 1, 'price' => 20.00, 'price_net' => 20.00],
        ]);

        $e = $this->refused(fn () => $this->redeem($orderId, $this->fixtures->customer()));

        self::assertSame([422, 'La venta tiene artículos sin IVA (0 %) y no hay una causa de exención configurada; no se puede facturar'], [$e->status, $e->getMessage()]);
    }

    // §5 «Venta de 0 €».
    public function testASaleOfZeroHasNothingToInvoice(): void
    {
        $orderId = $this->fixtures->sale('A26000143', '2026-11-05 09:00:00', [['name' => 'Regalo', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00, 'discount' => 100.0]]);

        $e = $this->refused(fn () => $this->redeem($orderId, $this->fixtures->customer()));

        self::assertSame([422, 'No hay importe que facturar'], [$e->status, $e->getMessage()]);
    }

    // §5 «Cliente sin NIF ni documento», F1 missing fields.
    public function testACustomerWithoutCompleteTaxDataIsAskedForThem(): void
    {
        $orderId = $this->ticket1210();

        $e = $this->refused(fn () => $this->redeem($orderId, $this->fixtures->customer('sinnif', ['tax_id' => null])));
        self::assertSame([422, 'Falta el NIF del cliente', ['tax_id']], [$e->status, $e->getMessage(), $e->details['missing']]);

        $e = $this->refused(fn () => $this->redeem($orderId, $this->fixtures->customer('sindomicilio', ['address' => null, 'city' => ''])));
        self::assertSame(['Faltan datos fiscales del cliente: domicilio, población', ['address', 'city']], [$e->getMessage(), $e->details['missing']]);

        $e = $this->refused(fn () => $this->redeem($orderId, $this->fixtures->customer('nifmalo', ['tax_id' => 'B12345675'])));
        self::assertSame('El NIF no es válido', $e->getMessage());

        $e = $this->refused(fn () => $this->redeem($orderId, 0));
        self::assertSame(['Elige el cliente de la factura', ['customer']], [$e->getMessage(), $e->details['missing']]);

        self::assertSame(0, $this->fixtures->lastInvoiceNumber());
    }

    public function testAForeignCustomerWithAnL7DocumentIsInvoiced(): void
    {
        $orderId = $this->ticket1210();
        $customerId = $this->fixtures->customer('foreign', ['country' => 'FR', 'tax_id_type' => '02', 'tax_id' => 'FR12345678901', 'postcode' => null, 'province' => null]);

        $recipient = $this->redeem($orderId, $customerId)['invoice']['recipient'];

        self::assertSame(['FR', '02', 'FR12345678901', ''], [$recipient['country'], $recipient['tax_id_type'], $recipient['tax_id'], $recipient['postcode']]);
    }

    public function testAShopWithoutNifCannotIssueInvoices(): void
    {
        \assert($this->connection !== null);
        $orderId = $this->ticket1210();
        $this->connection->update('terminal', ['tax_id' => null], ['id' => InvoiceFixtures::TILL]);

        $e = $this->refused(fn () => $this->redeem($orderId, $this->fixtures->customer()));

        self::assertSame([422, 'Faltan datos de la tienda para facturar (Ajustes → Tienda): NIF'], [$e->status, $e->getMessage()]);
    }

    // §5 «Ticket de otro año» and P7.
    public function testATicketOfAnotherYearTakesThisYearsNumberAndKeepsItsOperationDate(): void
    {
        $orderId = $this->fixtures->sale('A25000999', '2025-12-30 12:00:00', [['name' => 'Gorro', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]]);

        $result = $this->redeem($orderId, $this->fixtures->customer(), at: '2026-01-02 10:00:00');

        self::assertSame(['F26000001', '2026-01-02', '2025-12-30', '2025-12-30'], [$result['invoice']['number'], $result['invoice']['issue_date'], $result['invoice']['operation_date'], $result['invoice']['replaced_date']]);
        self::assertSame([], $result['warnings']);
    }

    public function testATicketOlderThanFourYearsIsRedeemedWithAWarning(): void
    {
        $orderId = $this->fixtures->sale('A21000005', '2021-03-01 12:00:00', [['name' => 'Gorro', 'qty' => 1, 'price' => 12.10, 'price_net' => 10.00]]);

        $result = $this->redeem($orderId, $this->fixtures->customer());

        self::assertTrue($result['created']);
        self::assertSame([InvoiceIssuer::OLD_TICKET_WARNING], $result['warnings']);
    }

    // F1: the invoice keeps a copy; later edits do not change it.
    public function testEditingTheCustomerAfterwardsDoesNotChangeTheInvoice(): void
    {
        \assert($this->connection !== null);
        $orderId = $this->ticket1210();
        $customerId = $this->fixtures->customer();
        $this->redeem($orderId, $customerId);
        $before = $this->fixtures->invoiceRow($orderId);

        (new CustomerDirectory($this->connection))->updateTaxData($customerId, ['tax_name' => 'Otra Razón SL', 'address' => 'Calle Nueva 9']);

        self::assertSame($before, $this->fixtures->invoiceRow($orderId));
        self::assertSame('Deportes Ejemplo SL', json_decode((string) $before['recipient'], true)['name']);
    }

    public function testThePreviewShowsTheSummaryWithoutTakingANumber(): void
    {
        $orderId = $this->ticket1210();
        $customerId = $this->fixtures->customer();

        $preview = $this->issuer()->preview($orderId, $customerId, InvoiceFixtures::TILL, InvoiceFixtures::now());

        self::assertSame(['A26000123', '2026-11-03', 12.1, 2.1, 'F26000001', 'Deportes Ejemplo SL'], [$preview['replaced_reference'], $preview['replaced_date'], $preview['total'], $preview['tax_total'], $preview['next_number'], $preview['recipient']['name']]);
        self::assertSame(0, $this->fixtures->lastInvoiceNumber());
        self::assertSame(0, (int) $this->connection?->fetchOne('SELECT COUNT(*) FROM invoice'));
    }

    public function testAnUnknownSaleIsNotFound(): void
    {
        $e = $this->refused(fn () => $this->redeem(987654321, $this->fixtures->customer()));

        self::assertSame([404, 'No se encuentra la venta'], [$e->status, $e->getMessage()]);
    }

    public function testAReprintOfTheRedeemedTicketSaysWhichInvoiceReplacedIt(): void
    {
        $orderId = $this->ticket1210();
        $resolver = new TicketDataResolver($this->connection, $this->createMock(TicketVerificationService::class), 'Tienda', '');
        self::assertNull($resolver->resolve(TicketDataResolver::saleKey($orderId))->replacedByInvoice);

        $this->redeem($orderId, $this->fixtures->customer());

        self::assertSame('F26000001', $resolver->resolve(TicketDataResolver::saleKey($orderId))->replacedByInvoice);
    }
}
