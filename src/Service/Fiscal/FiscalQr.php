<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal;

/**
 * The cotejo URL a VERI*FACTU ticket carries in its QR, per the AEAT "Detalle de
 * las especificaciones técnicas del código QR de la factura y de la URL del
 * servicio de cotejo" v0.5.0: nif, numserie, fecha (dd-mm-aaaa) and importe, each
 * URL-encoded in UTF-8. The QR goes at the top of the ticket, between 30 and 40 mm,
 * error correction M, with «QR tributario:» above and «VERI*FACTU» below.
 */
final class FiscalQr
{
    public const PRODUCTION_BASE = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR';
    public const TEST_BASE = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
    public const TITLE = 'QR tributario:';
    public const LEGEND = 'VERI*FACTU';

    public static function url(string $nif, string $number, string $issueDate, string $total, string $base = self::PRODUCTION_BASE): string
    {
        return $base . '?' . http_build_query([
            'nif' => $nif,
            'numserie' => $number,
            'fecha' => $issueDate,
            'importe' => $total,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The URL of an issued ticket or credit note, from its stored fiscal record. */
    public static function forRecord(array $record): string
    {
        return self::url((string) $record['issuer_nif'], (string) $record['number'], (string) $record['issue_date'], (string) $record['total']);
    }
}
