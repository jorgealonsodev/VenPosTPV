<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Report;

/**
 * The CSV export of a report (docs/prd/informes-ventas.md I3), made to open in Excel
 * with Spanish settings: UTF-8 with BOM, `;` separator, CRLF line ends, RFC 4180
 * quotes, comma decimals without thousands separator nor `€`, dates dd/mm/aaaa.
 * Text cells that start with = + - @ get a leading `'` so Excel never runs them
 * as a formula; numeric cells are written as they are.
 */
final class ReportCsv
{
    private const BOM = "\xEF\xBB\xBF";

    /** The type in the file name, in Spanish (informe-<tipo>-<desde>-<hasta>.csv). */
    private const FILE_TYPES = ['summary' => 'resumen', 'daily' => 'dias', 'payments' => 'formas-pago'];

    /** Label and key of each summary row, in screen order. */
    private const SUMMARY_ROWS = [
        'gross_before_discounts' => 'Importe antes de descuentos',
        'discounts' => 'Descuentos',
        'gross_sales' => 'Ventas brutas',
        'refunds' => 'Devoluciones',
        'net_sales' => 'Ventas netas',
        'tickets' => 'Tickets',
        'average_ticket' => 'Ticket medio',
        'units_sold' => 'Unidades vendidas',
        'units_refunded' => 'Unidades devueltas',
        'units_net' => 'Unidades netas',
        'exchange_credit' => 'Crédito de cambio',
        'online_refunds' => 'Devoluciones online (informativo)',
    ];

    private const PAYMENT_ROWS = [
        'sales_cash' => 'Ventas en efectivo',
        'sales_card' => 'Ventas con tarjeta',
        'exchange_credit' => 'Crédito de cambio',
        'refunds_cash' => 'Devoluciones en efectivo',
        'refunds_card' => 'Devoluciones con tarjeta',
        'refunds_voucher' => 'Devoluciones con vale',
        'exchange_vouchers' => 'Vales de cambios cerrados (incluidos en devoluciones con vale)',
    ];

    private const METHOD_ROWS = [
        'cash' => 'Tickets en efectivo',
        'card' => 'Tickets con tarjeta',
        'mixed' => 'Tickets mixtos',
        'exchange' => 'Tickets pagados con crédito de cambio',
    ];

    /** @param array<string, mixed> $data the `data` of the report */
    public function render(string $type, array $data): string
    {
        return match ($type) {
            'summary' => $this->summary($data),
            'daily' => $this->daily($data),
            'payments' => $this->payments($data),
            default => throw new \InvalidArgumentException(sprintf('No CSV for report type "%s"', $type)),
        };
    }

    public function fileName(string $type, ReportFilter $filter): string
    {
        $name = 'informe-' . (self::FILE_TYPES[$type] ?? $type);
        if ($filter->sessionId !== null) {
            return sprintf('%s-sesion-%d.csv', $name, $filter->sessionId);
        }

        return sprintf('%s-%s-%s.csv', $name, $filter->from()->format('Y-m-d'), $filter->to()->format('Y-m-d'));
    }

    /**
     * Strings are text cells, ints and floats are numbers (floats with two
     * decimals), null is an empty cell.
     *
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public static function encode(array $headers, array $rows): string
    {
        $out = self::BOM . self::line($headers);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /** @param list<string|int|float|null> $cells */
    private static function line(array $cells): string
    {
        return implode(';', array_map(self::cell(...), $cells)) . "\r\n";
    }

    private static function cell(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            return number_format(round($value, 2) + 0.0, 2, ',', '');
        }
        if ($value !== '' && \in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }
        if (strpbrk($value, ";\"\r\n") !== false) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function summary(array $data): string
    {
        $rows = [];
        foreach (self::SUMMARY_ROWS as $key => $label) {
            $rows[] = [$label, $data[$key], $data['previous'][$key], $data['comparison'][$key]['difference'], $this->percent($data['comparison'][$key]['percent'])];
        }
        $rows[] = ['Ventas pendientes de subir', (int) $data['pending_upload']['sales'], null, null, null];
        $rows[] = ['Devoluciones pendientes de subir', (int) $data['pending_upload']['refunds'], null, null, null];

        return self::encode(['Concepto', 'Periodo', 'Periodo anterior', 'Diferencia', 'Diferencia %'], $rows);
    }

    /** @param array<string, mixed> $data */
    private function daily(array $data): string
    {
        $rows = [];
        foreach ($data['days'] as $day) {
            $rows[] = [$this->date((string) $day['date']), (int) $day['tickets'], (int) $day['units'], (float) $day['gross_sales'], (float) $day['refunds'], (float) $day['net_sales']];
        }
        $t = $data['totals'];
        $rows[] = ['Total', (int) $t['tickets'], (int) $t['units'], (float) $t['gross_sales'], (float) $t['refunds'], (float) $t['net_sales']];

        return self::encode(['Fecha', 'Tickets', 'Unidades', 'Ventas brutas', 'Devoluciones', 'Ventas netas'], $rows);
    }

    /** @param array<string, mixed> $data */
    private function payments(array $data): string
    {
        $rows = [];
        foreach (self::PAYMENT_ROWS as $key => $label) {
            $rows[] = [$label, (float) $data[$key]];
        }
        foreach ($data['tickets_by_method'] as $method => $count) {
            $rows[] = [self::METHOD_ROWS[$method] ?? 'Tickets ' . $method, (int) $count];
        }
        $rows[] = ['Total', (float) $data['net_total']];

        return self::encode(['Concepto', 'Importe'], $rows);
    }

    private function percent(int|float|null $percent): ?float
    {
        return $percent === null ? null : (float) $percent;
    }

    private function date(string $ymd): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $date === false ? $ymd : $date->format('d/m/Y');
    }
}
