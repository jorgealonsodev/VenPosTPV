<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Customer;

use VenPosTPV\Service\Customer\Exception\CustomerException;
use VenPosTPV\Service\Fiscal\SpanishTaxId;

/**
 * The customer's tax data for a full invoice (docs/prd/factura-completa.md F1):
 * legal name, country, document type and number, address. A Spanish customer has a
 * NIF/NIE/CIF checked with SpanishTaxId; a foreign one a document of the AEAT list
 * L7 (02 NIF-IVA, 03 passport, 04 official document of the country of residence,
 * 05 residence certificate, 06 other) without a control character.
 *
 * The data are optional on the customer and only required to issue an invoice.
 */
final class CustomerTaxData
{
    public const FIELDS = ['tax_name', 'country', 'tax_id_type', 'tax_id', 'address', 'postcode', 'city', 'province'];

    public const DEFAULT_COUNTRY = 'ES';

    /** tax_id_type of a Spanish NIF. */
    public const SPANISH_NIF = 'NI';

    /** AEAT list L7 for customers without a Spanish NIF. */
    public const FOREIGN_TYPES = [
        '02' => 'NIF-IVA',
        '03' => 'Pasaporte',
        '04' => 'Documento oficial del país de residencia',
        '05' => 'Certificado de residencia',
        '06' => 'Otro documento probatorio',
    ];

    public const INVALID_NIF_MESSAGE = 'El NIF no es válido';
    public const MISSING_NIF_MESSAGE = 'Falta el NIF del cliente';

    private const MAX_LENGTH = ['tax_name' => 120, 'tax_id' => 20, 'address' => 255, 'postcode' => 12, 'city' => 100, 'province' => 100];

    private const LABELS = [
        'tax_name' => 'razón social o nombre',
        'tax_id_type' => 'tipo de documento',
        'tax_id' => 'NIF o documento',
        'address' => 'domicilio',
        'postcode' => 'código postal',
        'city' => 'población',
    ];

    /**
     * The columns to store from a form: only the fields present in $input change;
     * the result is checked together with what the customer already has.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current the customer row
     *
     * @return array<string, ?string> every tax column, normalized (empty = null)
     *
     * @throws CustomerException 422 with the message to show
     */
    public static function normalize(array $input, array $current = []): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $raw = \array_key_exists($field, $input) ? $input[$field] : ($current[$field] ?? null);
            if ($raw !== null && !\is_string($raw)) {
                throw CustomerException::invalid(sprintf('El campo %s no es válido.', $field));
            }
            $value = $raw === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $raw));
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw CustomerException::invalid(sprintf('El campo %s no es válido.', $field));
            }
            $values[$field] = $value !== '' ? $value : null;
        }

        $country = strtoupper($values['country'] ?? self::DEFAULT_COUNTRY);
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw CustomerException::invalid('El país debe ser un código de dos letras (ES, FR, PT...).');
        }
        $values['country'] = $country;

        foreach (self::MAX_LENGTH as $field => $max) {
            if ($values[$field] !== null && mb_strlen($values[$field]) > $max) {
                throw CustomerException::invalid(sprintf('El campo %s no puede tener más de %d caracteres.', self::LABELS[$field] ?? $field, $max));
            }
        }

        if ($country === self::DEFAULT_COUNTRY) {
            $values['tax_id_type'] = self::SPANISH_NIF;
            if ($values['tax_id'] !== null) {
                $nif = SpanishTaxId::normalize($values['tax_id']);
                if (!SpanishTaxId::isValid($nif)) {
                    throw CustomerException::invalid(self::INVALID_NIF_MESSAGE);
                }
                $values['tax_id'] = $nif;
            }
        } else {
            $type = $values['tax_id_type'];
            if ($type === self::SPANISH_NIF) {
                $type = null;
            }
            if ($type !== null && !\array_key_exists($type, self::FOREIGN_TYPES)) {
                throw CustomerException::invalid('El tipo de documento no es válido (02, 03, 04, 05 o 06).');
            }
            $values['tax_id_type'] = $type;
            if ($values['tax_id'] !== null) {
                $values['tax_id'] = strtoupper($values['tax_id']);
            }
        }

        return $values;
    }

    /**
     * What the customer lacks to receive a full invoice.
     *
     * @param array<string, mixed> $row the customer row
     *
     * @return array{missing: list<string>, message: ?string} message null when complete
     */
    public static function invoiceCheck(array $row): array
    {
        $country = strtoupper(trim((string) ($row['country'] ?? ''))) ?: self::DEFAULT_COUNTRY;
        $spanish = $country === self::DEFAULT_COUNTRY;
        $required = $spanish ? ['tax_name', 'tax_id', 'address', 'postcode', 'city'] : ['tax_name', 'tax_id_type', 'tax_id', 'address', 'city'];
        $missing = [];
        foreach ($required as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        if (!$spanish && !\in_array('tax_id_type', $missing, true) && !\array_key_exists((string) ($row['tax_id_type'] ?? ''), self::FOREIGN_TYPES)) {
            $missing[] = 'tax_id_type';
        }

        if ($missing === []) {
            if ($spanish && !SpanishTaxId::isValid((string) $row['tax_id'])) {
                return ['missing' => ['tax_id'], 'message' => self::INVALID_NIF_MESSAGE];
            }

            return ['missing' => [], 'message' => null];
        }
        if ($spanish && $missing === ['tax_id']) {
            return ['missing' => $missing, 'message' => self::MISSING_NIF_MESSAGE];
        }

        return [
            'missing' => $missing,
            'message' => 'Faltan datos fiscales del cliente: ' . implode(', ', array_map(static fn (string $f): string => self::LABELS[$f], $missing)),
        ];
    }

    /**
     * The copy of the recipient an invoice keeps (later edits never change it).
     *
     * @param array<string, mixed> $row the customer row
     *
     * @return array<string, mixed>
     */
    public static function recipient(array $row): array
    {
        $country = strtoupper(trim((string) ($row['country'] ?? ''))) ?: self::DEFAULT_COUNTRY;

        return [
            'customer_id' => (int) ($row['id'] ?? 0),
            'name' => (string) $row['tax_name'],
            'country' => $country,
            'tax_id_type' => $country === self::DEFAULT_COUNTRY ? self::SPANISH_NIF : (string) $row['tax_id_type'],
            'tax_id' => (string) $row['tax_id'],
            'address' => (string) $row['address'],
            'postcode' => (string) ($row['postcode'] ?? ''),
            'city' => (string) $row['city'],
            'province' => (string) ($row['province'] ?? ''),
        ];
    }
}
