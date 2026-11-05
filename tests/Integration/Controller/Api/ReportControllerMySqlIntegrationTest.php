<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\ReportController;
use VenPosTPV\Service\Report\ReportCsv;
use VenPosTPV\Service\Report\SalesReportService;
use VenPosTPV\Tests\Integration\Service\Report\SalesReportFixture;
use VenPosTPV\Tests\Support\SessionRequest;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/informes-ventas.md I3, I4 and criteria 12 and 13: GET /api/reports/{type}
 * is for administrators only, validates its filters, answers {data, approximate_amount,
 * missing_lines} and exports CSV, auditing each export.
 */
final class ReportControllerMySqlIntegrationTest extends TestCase
{
    private const ADMIN = ['user_id' => SalesReportFixture::ANA, 'role' => 'admin', 'shop_id' => 1];
    private const TYPES = ['summary', 'daily', 'hourly', 'products', 'categories', 'cashiers', 'payments', 'vat', 'nope'];

    private ?Connection $connection = null;
    private SalesReportFixture $fixture;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->fixture = new SalesReportFixture($this->connection);
        $this->fixture->load();
        $this->cleanAudit();
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanAudit();
            $this->fixture->clean();
            $this->connection->close();
        }
        $this->connection = null;
    }

    private function cleanAudit(): void
    {
        $this->connection->executeStatement("DELETE FROM audit_log WHERE action = 'report_export' AND user_id IN (?, ?)", [SalesReportFixture::ANA, SalesReportFixture::LUIS]);
    }

    private function controller(): ReportController
    {
        return new ReportController(new SalesReportService($this->connection), new ReportCsv(), $this->connection);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    private function audits(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'report_export' AND user_id IN (?, ?)", [SalesReportFixture::ANA, SalesReportFixture::LUIS]);
    }

    public function testWithoutSessionEveryTypeIs401(): void
    {
        foreach (self::TYPES as $type) {
            $response = $this->controller()->show($type, SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-10']));
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), $type);
            $this->assertSame(['error' => 'No hay ninguna sesión iniciada'], $this->json($response));
        }
    }

    public function testACashierGets403OnEveryTypeCriterion13(): void
    {
        foreach (self::TYPES as $type) {
            foreach (['json', 'csv'] as $format) {
                $response = $this->controller()->show($type, SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-10', 'format' => $format],
                    ['user_id' => SalesReportFixture::LUIS, 'role' => 'cashier', 'shop_id' => 1]));
                $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $type);
                $this->assertSame(['error' => 'Solo un administrador puede ver los informes'], $this->json($response));
            }
        }
        $this->assertSame(0, $this->audits());
    }

    public function testAnUnknownTypeIs404(): void
    {
        $response = $this->controller()->show('nope', SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-10'], self::ADMIN));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(['error' => 'Informe no encontrado'], $this->json($response));
    }

    public function testEveryReportTypeAnswersTheJsonEnvelopeAndExportsCsv(): void
    {
        $names = ['summary' => 'resumen', 'daily' => 'dias', 'hourly' => 'horas', 'products' => 'productos', 'categories' => 'categorias',
            'cashiers' => 'cajeros', 'payments' => 'formas-pago', 'vat' => 'iva'];
        foreach ($names as $type => $name) {
            $json = $this->controller()->show($type, SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-11'], self::ADMIN));
            $this->assertSame(Response::HTTP_OK, $json->getStatusCode(), $type);
            $this->assertSame(['data', 'approximate_amount', 'missing_lines'], array_keys($this->json($json)), $type);

            $csv = $this->controller()->show($type, SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-11', 'format' => 'csv'], self::ADMIN));
            $this->assertSame(Response::HTTP_OK, $csv->getStatusCode(), $type);
            $this->assertSame('attachment; filename="informe-' . $name . '-2026-03-10-2026-03-11.csv"', $csv->headers->get('Content-Disposition'), $type);
            $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $csv->getContent(), $type);
        }
        $this->assertSame(\count($names), $this->audits());
    }

    public function testProductsCsvHasEveryRowAndATotalCriterion12(): void
    {
        $response = $this->controller()->show('products', SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-11', 'format' => 'csv', 'cursor' => '50'], self::ADMIN));

        $this->assertSame(
            "\xEF\xBB\xBFReferencia;Nombre;Unidades vendidas;Unidades devueltas;Unidades netas;Ventas;Devoluciones;Importe neto;% del total\r\n"
            . ";Camiseta;3;1;2;75,00;25,00;50,00;47,17\r\n"
            . ";Bañador;1;0;1;36,00;0,00;36,00;33,96\r\n"
            . ";Gorro;2;0;2;20,00;0,00;20,00;18,87\r\n"
            . "Total;;6;1;5;131,00;25,00;106,00;100,00\r\n",
            $response->getContent()
        );
        $rows = $this->connection->fetchAllAssociative("SELECT target, payload FROM audit_log WHERE action = 'report_export' AND user_id = ?", [SalesReportFixture::ANA]);
        $this->assertCount(1, $rows);
        $this->assertSame('products', $rows[0]['target']);
    }

    public function testInvalidProductOptionsAre422(): void
    {
        $cases = [
            ['products', ['sort' => 'name'], 'Orden no válido: usa amount o units'],
            ['products', ['cursor' => 'abc'], 'Cursor no válido'],
            ['daily', ['sort' => 'units'], 'Solo el informe por producto admite sort'],
        ];
        foreach ($cases as [$type, $query, $message]) {
            $response = $this->controller()->show($type, SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-10'] + $query, self::ADMIN));
            $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $message);
            $this->assertSame(['error' => $message], $this->json($response));
        }
    }

    public function testInvalidRangesAre422Criterion13(): void
    {
        $cases = [
            [['from' => '2026-03-11', 'to' => '2026-03-10'], 'La fecha desde no puede ser posterior a la fecha hasta'],
            [['from' => '2026-01-01', 'to' => '2027-02-04'], 'El rango máximo es de 366 días'],
            [['from' => '10/03/2026', 'to' => '2026-03-10'], 'Fecha no válida: usa el formato AAAA-MM-DD'],
        ];
        foreach (['summary', 'daily', 'hourly', 'products', 'categories', 'cashiers', 'payments', 'vat'] as $type) {
            foreach ($cases as [$query, $message]) {
                $response = $this->controller()->show($type, SessionRequest::create($query + ['format' => 'csv'], self::ADMIN));
                $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), $type);
                $this->assertSame(['error' => $message], $this->json($response));
            }
        }
        $this->assertSame(0, $this->audits());
    }

    public function testAnUnknownSessionIs404(): void
    {
        $response = $this->controller()->show('payments', SessionRequest::create(['session_id' => '9559'], self::ADMIN));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(['error' => 'Sesión de caja no encontrada'], $this->json($response));
    }

    public function testAdminGetsTheJsonEnvelopeWithoutAnAuditRow(): void
    {
        $this->fixture->addSaleWithoutLines();

        $response = $this->controller()->show('summary', SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-10'], self::ADMIN));
        $body = $this->json($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['data', 'approximate_amount', 'missing_lines'], array_keys($body));
        $this->assertSame(1, $body['missing_lines']);
        $this->assertEquals(0, $body['approximate_amount']);
        $this->assertEquals(106, $body['data']['gross_sales']);
        $this->assertSame(4, $body['data']['tickets']);
        $this->assertSame(0, $this->audits(), 'a query is not an export');
    }

    public function testCsvExportIsAFileAndIsAudited(): void
    {
        $response = $this->controller()->show('daily', SessionRequest::create(['from' => '2026-03-10', 'to' => '2026-03-12', 'format' => 'csv', 'terminal_id' => (string) SalesReportFixture::TILL_A], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="informe-dias-2026-03-10-2026-03-12.csv"', $response->headers->get('Content-Disposition'));
        $this->assertSame(
            "\xEF\xBB\xBFFecha;Tickets;Unidades;Ventas brutas;Devoluciones;Ventas netas\r\n"
            . "10/03/2026;2;4;70,00;0,00;70,00\r\n"
            . "11/03/2026;1;1;25,00;25,00;0,00\r\n"
            . "12/03/2026;0;0;0,00;0,00;0,00\r\n"
            . "Total;3;5;95,00;25,00;70,00\r\n",
            $response->getContent()
        );

        $rows = $this->connection->fetchAllAssociative("SELECT user_id, terminal_id, action, target, payload FROM audit_log WHERE action = 'report_export' AND user_id = ?", [SalesReportFixture::ANA]);
        $this->assertCount(1, $rows);
        $this->assertSame(SalesReportFixture::ANA, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['terminal_id']);
        $this->assertSame('daily', $rows[0]['target']);
        // A JSON column does not keep the key order.
        $payload = json_decode((string) $rows[0]['payload'], true);
        ksort($payload);
        $this->assertSame(
            ['from' => '2026-03-10', 'session_id' => null, 'terminal_id' => SalesReportFixture::TILL_A, 'to' => '2026-03-12', 'type' => 'daily', 'user_id' => null],
            $payload
        );
    }

    public function testPaymentsCsvBySessionIsNamedAfterTheSession(): void
    {
        $response = $this->controller()->show('payments', SessionRequest::create(['session_id' => (string) SalesReportFixture::SESSION_1, 'format' => 'csv'], self::ADMIN));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('attachment; filename="informe-formas-pago-sesion-' . SalesReportFixture::SESSION_1 . '.csv"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("Ventas en efectivo;89,00\r\n", (string) $response->getContent());
        $this->assertStringEndsWith("Total;70,00\r\n", (string) $response->getContent());
        $this->assertSame(1, $this->audits());
    }
}
