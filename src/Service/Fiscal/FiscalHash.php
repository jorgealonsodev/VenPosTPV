<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

/**
 * The record fingerprint ("huella") of VERI*FACTU, as the AEAT specifies it in
 * "Detalle de las especificaciones técnicas para generación de la huella o hash
 * de los registros de facturación" v0.1.2: the fields, in a fixed order, joined
 * as name=value&name=value, each value trimmed, encoded in UTF-8 and hashed with
 * SHA-256, written as 64 upper-case hexadecimal characters. A missing value keeps
 * its "name=" (the first record has "Huella=").
 */
final class FiscalHash
{
    public static function alta(
        string $issuerNif,
        string $number,
        string $issueDate,
        string $invoiceType,
        string $taxTotal,
        string $total,
        string $previousHash,
        string $generatedAt,
    ): string {
        return self::hash([
            'IDEmisorFactura' => $issuerNif,
            'NumSerieFactura' => $number,
            'FechaExpedicionFactura' => $issueDate,
            'TipoFactura' => $invoiceType,
            'CuotaTotal' => $taxTotal,
            'ImporteTotal' => $total,
            'Huella' => $previousHash,
            'FechaHoraHusoGenRegistro' => $generatedAt,
        ]);
    }

    public static function anulacion(
        string $issuerNif,
        string $number,
        string $issueDate,
        string $previousHash,
        string $generatedAt,
    ): string {
        return self::hash([
            'IDEmisorFacturaAnulada' => $issuerNif,
            'NumSerieFacturaAnulada' => $number,
            'FechaExpedicionFacturaAnulada' => $issueDate,
            'Huella' => $previousHash,
            'FechaHoraHusoGenRegistro' => $generatedAt,
        ]);
    }

    /** An amount as the record stores and the XML will carry it: 2 decimals, a dot. */
    public static function amount(float|int $value): string
    {
        $formatted = number_format(round((float) $value, 2), 2, '.', '');

        return $formatted === '-0.00' ? '0.00' : $formatted;
    }

    /** FechaExpedicionFactura: dd-mm-yyyy. */
    public static function date(\DateTimeInterface $date): string
    {
        return $date->format('d-m-Y');
    }

    /** FechaHoraHusoGenRegistro: ISO 8601 with the time zone offset. */
    public static function timestamp(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    /** @param array<string, string> $fields */
    private static function hash(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $name => $value) {
            $pairs[] = $name . '=' . trim($value);
        }

        return strtoupper(hash('sha256', implode('&', $pairs)));
    }
}
