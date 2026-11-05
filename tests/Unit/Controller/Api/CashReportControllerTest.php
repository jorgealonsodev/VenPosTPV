<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use VenPosTPV\Controller\Api\CashReportController;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Print\CashReport;
use VenPosTPV\Service\Print\CashReportService;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * Informe X (open till) and Informe Z (a close): print on the till or PDF, and the
 * list of closes in Ajustes (administrators only).
 */
final class CashReportControllerTest extends TestCase
{
    private function report(string $kind, int $terminalId = 3): CashReport
    {
        return new CashReport(
            kind: $kind, sessionId: 41, terminalId: $terminalId, terminalName: 'Caja 1', terminalLetter: null, storeName: 'Tienda',
            legalName: null, taxId: null, cashierName: 'Ana', openedAt: new \DateTimeImmutable('2026-11-05 09:00'),
            closedAt: $kind === CashReport::Z ? new \DateTimeImmutable('2026-11-05 21:00') : null,
            issuedAt: new \DateTimeImmutable('2026-11-05 21:00'), totals: [],
            countedCash: $kind === CashReport::Z ? 10.0 : null, difference: $kind === CashReport::Z ? 0.0 : null,
            signatureHash: $kind === CashReport::Z ? str_repeat('a', 64) : null,
        );
    }

    private function controller(?CashReportService $reports = null, ?CashSessionService $cash = null): CashReportController
    {
        return new CashReportController($reports ?? $this->createMock(CashReportService::class), $cash ?? $this->createMock(CashSessionService::class));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    public function testEveryRouteNeedsALoggedInUser(): void
    {
        $reports = $this->createMock(CashReportService::class);
        $reports->expects($this->never())->method($this->anything());
        $controller = $this->controller($reports);

        foreach ([
            $controller->printX(SessionRequest::create()),
            $controller->pdfX(SessionRequest::create()),
            $controller->listZ(SessionRequest::create()),
            $controller->pdfZ(SessionRequest::create(), 41),
            $controller->printZ(SessionRequest::create(), 41),
        ] as $response) {
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        }
    }

    public function testTheXReportPrintsOnTheTillOfTheOpenSession(): void
    {
        $reports = $this->createMock(CashReportService::class);
        $reports->method('currentX')->with(7)->willReturn($this->report(CashReport::X, 3));
        $reports->expects($this->once())->method('print')->with($this->isInstanceOf(CashReport::class), 3, 7)
            ->willReturn(['print_status' => 'queued', 'print_job_id' => 55]);

        $response = $this->controller($reports)->printX(SessionRequest::create([], ['user_id' => 7]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['data' => ['print_status' => 'queued', 'print_job_id' => 55]], $this->json($response));
    }

    public function testWithoutAnOpenSessionThereIsNoXReport(): void
    {
        $reports = $this->createMock(CashReportService::class);
        $reports->method('currentX')->willThrowException(new \RuntimeException('No open cash session'));
        $reports->expects($this->never())->method('print');

        $controller = $this->controller($reports);
        $print = $controller->printX(SessionRequest::create([], ['user_id' => 7]));
        $pdf = $controller->pdfX(SessionRequest::create([], ['user_id' => 7]));

        $this->assertSame(Response::HTTP_CONFLICT, $print->getStatusCode());
        $this->assertSame('No hay ninguna caja abierta en este equipo', $this->json($print)['error']);
        $this->assertSame(Response::HTTP_CONFLICT, $pdf->getStatusCode());
    }

    public function testTheXReportPdfIsAnAttachment(): void
    {
        $reports = $this->createMock(CashReportService::class);
        $reports->method('currentX')->willReturn($this->report(CashReport::X));
        $reports->method('pdf')->willReturn('%PDF-x');

        $response = $this->controller($reports)->pdfX(SessionRequest::create([], ['user_id' => 7]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="informe-x-41.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-x', $response->getContent());
    }

    public function testOnlyAnAdministratorListsTheCloses(): void
    {
        $cash = $this->createMock(CashSessionService::class);
        $rows = [['session_id' => 41, 'closed_at' => '2026-11-05 21:00:00', 'cashier' => 'Ana', 'counted_cash' => 10.0, 'expected_cash' => 10.0, 'difference' => 0.0]];
        $cash->expects($this->once())->method('listClosedSessions')->with(50)->willReturn($rows);
        $controller = $this->controller(null, $cash);

        $denied = $controller->listZ(SessionRequest::create([], ['user_id' => 7, 'role' => 'cashier']));
        $allowed = $controller->listZ(SessionRequest::create([], ['user_id' => 1, 'role' => 'admin']));

        $this->assertSame(Response::HTTP_FORBIDDEN, $denied->getStatusCode());
        $this->assertSame(Response::HTTP_OK, $allowed->getStatusCode());
        $this->assertEquals(['data' => $rows], $this->json($allowed));
    }

    public function testTheZReportOfACloseIsPrintedOnTheAskingTillOrGivenAsPdf(): void
    {
        $reports = $this->createMock(CashReportService::class);
        $reports->method('closedZ')->willReturnCallback(fn (int $id): ?CashReport => $id === 41 ? $this->report(CashReport::Z, 3) : null);
        $reports->expects($this->once())->method('print')->with($this->isInstanceOf(CashReport::class), 9, 7)->willReturn(['fallback' => 'pdf']);
        $reports->method('pdf')->willReturn('%PDF-z');
        $controller = $this->controller($reports);

        // The cashier who just closed (not an administrator) gets their own Z.
        $request = SessionRequest::create([], ['user_id' => 7, 'role' => 'cashier']);
        $request->attributes->set(DeviceContext::ATTRIBUTE, 9);
        $print = $controller->printZ($request, 41);
        $pdf = $controller->pdfZ(SessionRequest::create([], ['user_id' => 7]), 41);

        $this->assertSame(['data' => ['fallback' => 'pdf']], $this->json($print));
        $this->assertSame('attachment; filename="informe-z-41.pdf"', $pdf->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-z', $pdf->getContent());
        $this->assertSame(Response::HTTP_NOT_FOUND, $controller->printZ(SessionRequest::create([], ['user_id' => 7]), 42)->getStatusCode());
        $this->assertSame(Response::HTTP_NOT_FOUND, $controller->pdfZ(SessionRequest::create([], ['user_id' => 7]), 42)->getStatusCode());
    }

    public function testWithoutADeviceTheZIsPrintedOnItsOwnTill(): void
    {
        $reports = $this->createMock(CashReportService::class);
        $reports->method('closedZ')->willReturn($this->report(CashReport::Z, 3));
        $reports->expects($this->once())->method('print')->with($this->anything(), 3, 7)->willReturn(['print_status' => 'printed']);

        $response = $this->controller($reports)->printZ(SessionRequest::create([], ['user_id' => 7]), 41);

        $this->assertSame(['data' => ['print_status' => 'printed']], $this->json($response));
    }

    public function testAPrinterErrorIsReportedWithoutDetails(): void
    {
        $reports = $this->createMock(CashReportService::class);
        $reports->method('closedZ')->willReturn($this->report(CashReport::Z));
        $reports->method('print')->willThrowException(new \RuntimeException('The print agent queue is not available'));

        $response = $this->controller($reports)->printZ(SessionRequest::create([], ['user_id' => 7]), 41);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame('No se ha podido imprimir el informe', $this->json($response)['error']);
    }
}
