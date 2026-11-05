<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Invoice;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Invoice\InvoicePdfGenerator;

/** docs/prd/factura-completa.md F6 and criterion 9: A4 with every item of RD 1619/2012 art. 6.1. */
final class InvoicePdfGeneratorTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function invoice(): array
    {
        return [
            'id' => 1,
            'number' => 'F26000002',
            'invoice_type' => 'F3',
            'replaced_reference' => 'A26000123',
            'replaced_date' => '2026-11-03',
            'issuer' => ['name' => 'Club Natación Ejemplo', 'tax_id' => 'G12345674', 'address' => 'Avenida del Agua 3, 33001 Oviedo'],
            'recipient' => ['customer_id' => 5, 'name' => 'Deportes Ejemplo SL', 'country' => 'ES', 'tax_id_type' => 'NI', 'tax_id' => 'B12345674', 'address' => 'Calle Mayor 1', 'postcode' => '33002', 'city' => 'Oviedo', 'province' => 'Asturias'],
            'lines' => [
                ['name' => 'Gorro de silicona', 'reference' => 'GOR-1', 'quantity' => 3, 'unit_price' => 9.99, 'unit_price_net' => 8.26, 'discount_pct' => 0.0, 'discount_net' => 2.48, 'net_amount' => 22.29, 'vat_rate' => 21.0, 'paid_amount' => 26.97],
                ['name' => 'Libro de técnica', 'reference' => '', 'quantity' => 1, 'unit_price' => 11.0, 'unit_price_net' => 10.0, 'discount_pct' => 0.0, 'discount_net' => 0.0, 'net_amount' => 10.0, 'vat_rate' => 10.0, 'paid_amount' => 11.0],
            ],
            'tax_breakdown' => [['rate' => 21.0, 'base' => 22.29, 'vat' => 4.68], ['rate' => 10.0, 'base' => 10.0, 'vat' => 1.0]],
            'payments' => [['method' => 'card', 'label' => 'Tarjeta', 'amount' => 30.0], ['method' => 'exchange_credit', 'label' => 'Crédito de cambio', 'amount' => 7.97]],
            'tax_total' => 5.68,
            'total' => 37.97,
            'issue_date' => '2026-11-05',
            'operation_date' => '2026-11-03',
            'email_consent_at' => null,
        ];
    }

    private function generator(): InvoicePdfGenerator
    {
        return new InvoicePdfGenerator(new Environment(new FilesystemLoader(\dirname(__DIR__, 4) . '/templates')));
    }

    public function testTheInvoiceShowsEveryItemTheRegulationAsksFor(): void
    {
        $html = html_entity_decode($this->generator()->html(self::invoice()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ([
            'Factura', 'F26000002',                                     // 6.1.a number
            'Fecha de expedición: 05/11/2026',                          // 6.1.b
            'Fecha de operación: 03/11/2026',                           // 6.1.i
            'Club Natación Ejemplo', 'NIF: G12345674', 'Avenida del Agua 3, 33001 Oviedo', // issuer 6.1.c-e
            'Deportes Ejemplo SL', 'NIF: B12345674', 'Calle Mayor 1', '33002 Oviedo (Asturias)', // recipient
            'Gorro de silicona', 'GOR-1', '8,26 €', '2,48 €', '22,29 €',  // 6.1.f
            'Precio unitario sin IVA', 'Descuento sin IVA',
            '21 %', '4,68 €', '10 %', '10,00 €', '1,00 €',               // 6.1.g-h, 6.2.c
            'Total factura', '37,97 €',
            'Forma de pago', 'Tarjeta: 30,00 €', 'Crédito de cambio: 7,97 €',
            'Sustituye a la factura simplificada A26000123 de 03/11/2026.',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    public function testTheOperationDateIsLeftOutWhenItIsTheIssueDate(): void
    {
        $html = $this->generator()->html(['operation_date' => '2026-11-05'] + self::invoice());

        self::assertStringNotContainsString('Fecha de operación', $html);
    }

    public function testAForeignCustomerShowsItsDocumentTypeAndCountry(): void
    {
        $invoice = self::invoice();
        $invoice['recipient'] = ['country' => 'FR', 'tax_id_type' => '02', 'tax_id' => 'FR123', 'province' => ''] + $invoice['recipient'];
        $html = html_entity_decode($this->generator()->html($invoice), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertStringContainsString('NIF-IVA: FR123', $html);
        self::assertStringContainsString('33002 Oviedo — FR', $html);
    }

    public function testThePdfIsA4(): void
    {
        $pdf = $this->generator()->generate(self::invoice());

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertMatchesRegularExpression('#/MediaBox \[0(?:\.0+)? 0(?:\.0+)? 595\.280* 841\.890*\]#', $pdf, 'A4: 595 × 842 pt');
        self::assertSame('factura-F26000002.pdf', InvoicePdfGenerator::fileName(self::invoice()));
    }
}
