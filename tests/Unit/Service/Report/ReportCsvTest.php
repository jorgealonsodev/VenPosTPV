<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Report;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Report\ReportCsv;
use VenPosTPV\Service\Report\ReportFilter;

/**
 * docs/prd/informes-ventas.md I3 and criterion 12: UTF-8 with BOM, `;`, CRLF, RFC 4180
 * quotes, comma decimals, dd/mm/aaaa, a «Total» row and no formula injection.
 */
final class ReportCsvTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    public function testEncodeWritesBomSemicolonsCrlfCommaDecimalsAndQuotes(): void
    {
        $csv = ReportCsv::encode(
            ['Nombre', 'Unidades', 'Importe'],
            [
                ['Bañador "Pro"; azul', 3, 1234.5],
                ["Gorro\nlargo", -1, -4.0],
                ['Camiseta €', 0, 0.0],
            ],
        );

        $this->assertSame(
            self::BOM . "Nombre;Unidades;Importe\r\n"
            . "\"Bañador \"\"Pro\"\"; azul\";3;1234,50\r\n"
            . "\"Gorro\nlargo\";-1;-4,00\r\n"
            . "Camiseta €;0;0,00\r\n",
            $csv
        );
        $this->assertTrue(mb_check_encoding($csv, 'UTF-8'));
    }

    public function testTextCellsStartingWithAFormulaCharacterAreEscapedButNumbersAreNot(): void
    {
        $csv = ReportCsv::encode(['Nombre', 'Importe'], [['=HYPERLINK("x")', -25.0], ['+34 600', 1.0], ['-x', 2.0], ['@SUM(A1)', 3.0], ['a=b', 4.0], [null, 5.0]]);

        $this->assertSame(
            self::BOM . "Nombre;Importe\r\n"
            . "\"'=HYPERLINK(\"\"x\"\")\";-25,00\r\n"
            . "'+34 600;1,00\r\n"
            . "'-x;2,00\r\n"
            . "'@SUM(A1);3,00\r\n"
            . "a=b;4,00\r\n"
            . ";5,00\r\n",
            $csv
        );
    }

    public function testDailyReportHasSpanishHeadersDatesAndATotalRow(): void
    {
        $data = [
            'days' => [
                ['date' => '2026-03-10', 'tickets' => 3, 'units' => 5, 'gross_sales' => 106.0, 'refunds' => 0.0, 'net_sales' => 106.0],
                ['date' => '2026-03-11', 'tickets' => 1, 'units' => 1, 'gross_sales' => 25.0, 'refunds' => 25.0, 'net_sales' => 0.0],
            ],
            'totals' => ['tickets' => 4, 'units' => 6, 'gross_sales' => 131.0, 'refunds' => 25.0, 'net_sales' => 106.0],
        ];

        $this->assertSame(
            self::BOM . "Fecha;Tickets;Unidades;Ventas brutas;Devoluciones;Ventas netas\r\n"
            . "10/03/2026;3;5;106,00;0,00;106,00\r\n"
            . "11/03/2026;1;1;25,00;25,00;0,00\r\n"
            . "Total;4;6;131,00;25,00;106,00\r\n",
            (new ReportCsv())->render('daily', $data)
        );
    }

    public function testSummaryReportListsEachMetricWithThePreviousPeriod(): void
    {
        $metrics = ['gross_before_discounts' => 110.0, 'discounts' => 4.0, 'gross_sales' => 106.0, 'refunds' => 0.0, 'net_sales' => 106.0, 'tickets' => 3,
            'average_ticket' => 35.33, 'units_sold' => 5, 'units_refunded' => 0, 'units_net' => 5, 'exchange_credit' => 0.0, 'online_refunds' => 0.0];
        $previous = ['from' => '2026-03-09', 'to' => '2026-03-09'] + array_map(static fn (int|float $v): int|float => \is_int($v) ? 0 : 0.0, $metrics);
        $comparison = [];
        foreach ($metrics as $key => $value) {
            $comparison[$key] = ['difference' => $value, 'percent' => null];
        }
        $comparison['net_sales'] = ['difference' => 6.0, 'percent' => 6.0];
        $data = ['from' => '2026-03-10', 'to' => '2026-03-10'] + $metrics + ['pending_upload' => ['sales' => 2, 'refunds' => 1], 'previous' => $previous, 'comparison' => $comparison];

        $csv = (new ReportCsv())->render('summary', $data);
        $lines = explode("\r\n", substr($csv, 3));

        $this->assertStringStartsWith(self::BOM, $csv);
        $this->assertSame('Concepto;Periodo;Periodo anterior;Diferencia;Diferencia %', $lines[0]);
        $this->assertSame('Importe antes de descuentos;110,00;0,00;110,00;', $lines[1]);
        $this->assertSame('Ventas netas;106,00;0,00;6,00;6,00', $lines[5]);
        $this->assertSame('Tickets;3;0;3;', $lines[6]);
        $this->assertSame('Ticket medio;35,33;0,00;35,33;', $lines[7]);
        $this->assertSame('Ventas pendientes de subir;2;;;', $lines[13]);
        $this->assertSame('Devoluciones pendientes de subir;1;;;', $lines[14]);
        $this->assertSame('', $lines[15]);
        $this->assertCount(16, $lines);
    }

    public function testPaymentsReportEndsWithTheNetAsTotal(): void
    {
        $data = ['sales_cash' => 64.0, 'sales_card' => 42.0, 'sales_total' => 106.0, 'exchange_credit' => 0.0, 'refunds_cash' => 0.0, 'refunds_card' => 0.0,
            'refunds_voucher' => 0.0, 'refunds_total' => 0.0, 'exchange_vouchers' => 0.0, 'net_total' => 106.0,
            'tickets_by_method' => ['cash' => 1, 'card' => 1, 'mixed' => 1, 'exchange' => 0]];

        $this->assertSame(
            self::BOM . "Concepto;Importe\r\n"
            . "Ventas en efectivo;64,00\r\n"
            . "Ventas con tarjeta;42,00\r\n"
            . "Crédito de cambio;0,00\r\n"
            . "Devoluciones en efectivo;0,00\r\n"
            . "Devoluciones con tarjeta;0,00\r\n"
            . "Devoluciones con vale;0,00\r\n"
            . "Vales de cambios cerrados (incluidos en devoluciones con vale);0,00\r\n"
            . "Tickets en efectivo;1\r\n"
            . "Tickets con tarjeta;1\r\n"
            . "Tickets mixtos;1\r\n"
            . "Tickets pagados con crédito de cambio;0\r\n"
            . "Total;106,00\r\n",
            (new ReportCsv())->render('payments', $data)
        );
    }

    public function testFileNameCarriesTheSpanishTypeAndTheRange(): void
    {
        $csv = new ReportCsv();

        $this->assertSame('informe-resumen-2026-03-01-2026-03-31.csv', $csv->fileName('summary', ReportFilter::fromQuery('summary', ['from' => '2026-03-01', 'to' => '2026-03-31'])));
        $this->assertSame('informe-dias-2026-03-10-2026-03-12.csv', $csv->fileName('daily', ReportFilter::fromQuery('daily', ['from' => '2026-03-10', 'to' => '2026-03-12'])));
        $this->assertSame('informe-formas-pago-2026-03-10-2026-03-10.csv', $csv->fileName('payments', ReportFilter::fromQuery('payments', ['from' => '2026-03-10', 'to' => '2026-03-10'])));
        $this->assertSame('informe-formas-pago-sesion-12.csv', $csv->fileName('payments', ReportFilter::fromQuery('payments', ['session_id' => '12'])));
    }
}
