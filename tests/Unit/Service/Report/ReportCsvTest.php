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
        $data = ['sales_cash' => 64.0, 'sales_card' => 42.0, 'sales_transfer' => 30.0, 'sales_total' => 136.0, 'exchange_credit' => 0.0, 'refunds_cash' => 0.0, 'refunds_card' => 0.0,
            'refunds_transfer' => 0.0, 'refunds_voucher' => 0.0, 'refunds_total' => 0.0, 'exchange_vouchers' => 0.0, 'net_total' => 136.0,
            'tickets_by_method' => ['cash' => 1, 'card' => 1, 'mixed' => 1, 'transfer' => 1, 'exchange' => 0]];

        $this->assertSame(
            self::BOM . "Concepto;Importe\r\n"
            . "Ventas en efectivo;64,00\r\n"
            . "Ventas con tarjeta;42,00\r\n"
            . "Ventas por transferencia;30,00\r\n"
            . "Transferencias pendientes de pago (incluidas en ventas por transferencia);0,00\r\n"
            . "Crédito de cambio;0,00\r\n"
            . "Devoluciones en efectivo;0,00\r\n"
            . "Devoluciones con tarjeta;0,00\r\n"
            . "Devoluciones por transferencia;0,00\r\n"
            . "Devoluciones con vale;0,00\r\n"
            . "Vales de cambios cerrados (incluidos en devoluciones con vale);0,00\r\n"
            . "Tickets en efectivo;1\r\n"
            . "Tickets con tarjeta;1\r\n"
            . "Tickets mixtos;1\r\n"
            . "Tickets por transferencia;1\r\n"
            . "Tickets pagados con crédito de cambio;0\r\n"
            . "Total;136,00\r\n",
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

    public function testProductsReportHasOneRowPerProductFreeItemsLabelledAndATotal(): void
    {
        $data = [
            'view' => 'sold',
            'rows' => [
                ['product_id' => 1, 'id_product_attribute' => 0, 'reference' => 'CAM-01', 'name' => 'Camiseta ñ €', 'free_item' => false, 'units_sold' => 3, 'units_refunded' => 1,
                    'units_net' => 2, 'sales_amount' => 75.0, 'refunds_amount' => 25.0, 'net_amount' => 50.0, 'percent' => 47.17],
                ['product_id' => null, 'id_product_attribute' => null, 'reference' => null, 'name' => 'Cuota club', 'free_item' => true, 'units_sold' => 1, 'units_refunded' => 0,
                    'units_net' => 1, 'sales_amount' => 15.0, 'refunds_amount' => 0.0, 'net_amount' => 15.0, 'percent' => 13.04],
                ['product_id' => 2, 'id_product_attribute' => 0, 'reference' => '-REF', 'name' => '=Gorro', 'free_item' => false, 'units_sold' => 1, 'units_refunded' => 0,
                    'units_net' => 1, 'sales_amount' => 50.0, 'refunds_amount' => 0.0, 'net_amount' => 50.0, 'percent' => null],
            ],
            'totals' => ['units_sold' => 5, 'units_refunded' => 1, 'units_net' => 4, 'sales_amount' => 140.0, 'refunds_amount' => 25.0, 'net_amount' => 115.0],
        ];

        $this->assertSame(
            self::BOM . "Referencia;Nombre;Unidades vendidas;Unidades devueltas;Unidades netas;Ventas;Devoluciones;Importe neto;% del total\r\n"
            . "CAM-01;Camiseta ñ €;3;1;2;75,00;25,00;50,00;47,17\r\n"
            . ";Artículo libre: Cuota club;1;0;1;15,00;0,00;15,00;13,04\r\n"
            . "'-REF;'=Gorro;1;0;1;50,00;0,00;50,00;\r\n"
            . "Total;;5;1;4;140,00;25,00;115,00;100,00\r\n",
            (new ReportCsv())->render('products', $data)
        );
    }

    public function testUnsoldProductsListReferenceNameAndCategory(): void
    {
        $data = ['view' => 'unsold', 'rows' => [['product_id' => 5, 'reference' => 'GAF-01', 'name' => 'Gafas', 'category_name' => null]], 'totals' => ['products' => 1]];

        $this->assertSame(
            self::BOM . "Referencia;Nombre;Categoría\r\nGAF-01;Gafas;Sin categoría\r\n",
            (new ReportCsv())->render('products', $data)
        );
    }

    public function testCategoriesHourlyCashiersAndVatReports(): void
    {
        $csv = new ReportCsv();
        $this->assertSame(
            self::BOM . "Categoría;Unidades vendidas;Unidades devueltas;Unidades netas;Ventas;Devoluciones;Importe neto;% del total\r\n"
            . "Ropa;4;1;3;111,00;25,00;86,00;89,58\r\n"
            . "Total;4;1;3;111,00;25,00;86,00;100,00\r\n",
            $csv->render('categories', [
                'rows' => [['category_id' => 1, 'name' => 'Ropa', 'units_sold' => 4, 'units_refunded' => 1, 'units_net' => 3, 'sales_amount' => 111.0, 'refunds_amount' => 25.0, 'net_amount' => 86.0, 'percent' => 89.58]],
                'totals' => ['units_sold' => 4, 'units_refunded' => 1, 'units_net' => 3, 'sales_amount' => 111.0, 'refunds_amount' => 25.0, 'net_amount' => 86.0],
            ])
        );

        $hours = [];
        for ($h = 0; $h < 24; ++$h) {
            $hours[] = ['hour' => $h, 'tickets' => $h === 9 ? 1 : 0, 'gross_sales' => $h === 9 ? 60.0 : 0.0];
        }
        $lines = explode("\r\n", substr($csv->render('hourly', ['hours' => $hours, 'totals' => ['tickets' => 1, 'gross_sales' => 60.0]]), 3));
        $this->assertSame('Hora;Tickets;Ventas brutas', $lines[0]);
        $this->assertSame('00:00;0;0,00', $lines[1]);
        $this->assertSame('09:00;1;60,00', $lines[10]);
        $this->assertSame('23:00;0;0,00', $lines[24]);
        $this->assertSame('Total;1;60,00', $lines[25]);
        $this->assertCount(27, $lines);

        $this->assertSame(
            self::BOM . "Empleado;Tickets;Ventas brutas;Ticket medio;Devoluciones;Ventas netas\r\n"
            . "Ana;3;95,00;31,67;25,00;70,00\r\n"
            . "Total;3;95,00;31,67;25,00;70,00\r\n",
            $csv->render('cashiers', [
                'rows' => [['user_id' => 1, 'name' => 'Ana', 'tickets' => 3, 'gross_sales' => 95.0, 'average_ticket' => 31.67, 'refunds' => 25.0, 'net_sales' => 70.0]],
                'totals' => ['tickets' => 3, 'gross_sales' => 95.0, 'average_ticket' => 31.67, 'refunds' => 25.0, 'net_sales' => 70.0],
            ])
        );

        $split = static fn (float $base, float $vat, float $total): array => ['base' => $base, 'vat' => $vat, 'total' => $total];
        $this->assertSame(
            self::BOM . "Tipo de IVA (%);Ventas base;Ventas cuota;Ventas total;Devoluciones base;Devoluciones cuota;Devoluciones total;Neto base;Neto cuota;Neto total\r\n"
            . "21,00;87,59;18,41;106,00;0,00;0,00;0,00;87,59;18,41;106,00\r\n"
            . "10,00;0,00;0,00;0,00;10,00;1,00;11,00;-10,00;-1,00;-11,00\r\n"
            . "Total;87,59;18,41;106,00;10,00;1,00;11,00;77,59;17,41;95,00\r\n",
            $csv->render('vat', [
                'rows' => [
                    ['rate' => 21.0, 'sales' => $split(87.59, 18.41, 106.0), 'refunds' => $split(0.0, 0.0, 0.0), 'net' => $split(87.59, 18.41, 106.0)],
                    ['rate' => 10.0, 'sales' => $split(0.0, 0.0, 0.0), 'refunds' => $split(10.0, 1.0, 11.0), 'net' => $split(-10.0, -1.0, -11.0)],
                ],
                'totals' => ['sales' => $split(87.59, 18.41, 106.0), 'refunds' => $split(10.0, 1.0, 11.0), 'net' => $split(77.59, 17.41, 95.0)],
            ])
        );
    }

    public function testFileNamesOfTheDetailReports(): void
    {
        $csv = new ReportCsv();
        $name = static fn (string $type, array $extra = []): string => $csv->fileName($type, ReportFilter::fromQuery($type, ['from' => '2026-03-01', 'to' => '2026-03-31'] + $extra));

        $this->assertSame('informe-productos-2026-03-01-2026-03-31.csv', $name('products'));
        $this->assertSame('informe-productos-sin-ventas-2026-03-01-2026-03-31.csv', $name('products', ['view' => 'unsold']));
        $this->assertSame('informe-categorias-2026-03-01-2026-03-31.csv', $name('categories'));
        $this->assertSame('informe-horas-2026-03-01-2026-03-31.csv', $name('hourly'));
        $this->assertSame('informe-cajeros-2026-03-01-2026-03-31.csv', $name('cashiers'));
        $this->assertSame('informe-iva-2026-03-01-2026-03-31.csv', $name('vat'));
    }
}
