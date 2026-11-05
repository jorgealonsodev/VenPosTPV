<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Customer;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Customer\CustomerTaxData;
use VenPosTPV\Service\Customer\Exception\CustomerException;

/** docs/prd/factura-completa.md F1: the customer's tax data for a full invoice. */
final class CustomerTaxDataTest extends TestCase
{
    private const COMPLETE = [
        'id' => 7,
        'tax_name' => 'Deportes Ejemplo SL',
        'country' => 'ES',
        'tax_id_type' => 'NI',
        'tax_id' => 'B12345674',
        'address' => 'Calle Mayor 1',
        'postcode' => '33001',
        'city' => 'Oviedo',
        'province' => 'Asturias',
    ];

    public function testASpanishNifIsNormalizedAndChecked(): void
    {
        $values = CustomerTaxData::normalize(['tax_name' => '  Deportes   Ejemplo SL ', 'tax_id' => 'b-1234 5674', 'address' => 'Calle Mayor 1']);

        self::assertSame('Deportes Ejemplo SL', $values['tax_name']);
        self::assertSame('B12345674', $values['tax_id']);
        self::assertSame('ES', $values['country'], 'Spain by default');
        self::assertSame('NI', $values['tax_id_type']);
        self::assertNull($values['city'], 'empty fields are stored as NULL');
    }

    public function testAnInvalidSpanishNifIsRefused(): void
    {
        try {
            CustomerTaxData::normalize(['tax_id' => 'B12345675']);
            self::fail('B12345675 has a wrong control digit');
        } catch (CustomerException $e) {
            self::assertSame('El NIF no es válido', $e->getMessage());
            self::assertSame(422, $e->getCode());
        }
    }

    public function testAForeignDocumentNeedsAnL7TypeButNoControlDigit(): void
    {
        $values = CustomerTaxData::normalize(['country' => 'fr', 'tax_id_type' => '02', 'tax_id' => 'fr12345678901']);
        self::assertSame(['FR', '02', 'FR12345678901'], [$values['country'], $values['tax_id_type'], $values['tax_id']]);

        try {
            CustomerTaxData::normalize(['country' => 'FR', 'tax_id_type' => '07', 'tax_id' => 'X']);
            self::fail('07 is not offered');
        } catch (CustomerException $e) {
            self::assertSame('El tipo de documento no es válido (02, 03, 04, 05 o 06).', $e->getMessage());
        }

        try {
            CustomerTaxData::normalize(['country' => 'FR', 'tax_id' => str_repeat('1', 21)]);
            self::fail('21 characters');
        } catch (CustomerException $e) {
            self::assertStringContainsString('20 caracteres', $e->getMessage());
        }
    }

    public function testOnlyTheFieldsSentChange(): void
    {
        $values = CustomerTaxData::normalize(['city' => 'Gijón'], self::COMPLETE);

        self::assertSame('Gijón', $values['city']);
        self::assertSame('B12345674', $values['tax_id']);
        self::assertSame('Calle Mayor 1', $values['address']);
    }

    public function testAnInvoiceNeedsNameNifAddressPostcodeAndCity(): void
    {
        self::assertSame(['missing' => [], 'message' => null], CustomerTaxData::invoiceCheck(self::COMPLETE));
        self::assertSame(
            ['missing' => ['tax_id'], 'message' => 'Falta el NIF del cliente'],
            CustomerTaxData::invoiceCheck(['tax_id' => null] + self::COMPLETE)
        );
        self::assertSame(
            ['missing' => ['tax_id'], 'message' => 'El NIF no es válido'],
            CustomerTaxData::invoiceCheck(['tax_id' => 'B12345675'] + self::COMPLETE)
        );
        self::assertSame(
            ['missing' => ['address', 'postcode'], 'message' => 'Faltan datos fiscales del cliente: domicilio, código postal'],
            CustomerTaxData::invoiceCheck(['address' => '', 'postcode' => null] + self::COMPLETE)
        );
        self::assertSame(
            ['missing' => ['tax_id_type'], 'message' => 'Faltan datos fiscales del cliente: tipo de documento'],
            CustomerTaxData::invoiceCheck(['country' => 'PT', 'tax_id_type' => null, 'tax_id' => 'P123'] + self::COMPLETE),
            'a foreign customer needs the document type'
        );
    }

    public function testTheRecipientIsACopyOfTheCustomer(): void
    {
        self::assertSame([
            'customer_id' => 7,
            'name' => 'Deportes Ejemplo SL',
            'country' => 'ES',
            'tax_id_type' => 'NI',
            'tax_id' => 'B12345674',
            'address' => 'Calle Mayor 1',
            'postcode' => '33001',
            'city' => 'Oviedo',
            'province' => 'Asturias',
        ], CustomerTaxData::recipient(self::COMPLETE));
    }
}
