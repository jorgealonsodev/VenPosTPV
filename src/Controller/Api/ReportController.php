<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Report\InvalidReportFilter;
use VenPosTPV\Service\Report\ReportCsv;
use VenPosTPV\Service\Report\ReportFilter;
use VenPosTPV\Service\Report\ReportNotFound;
use VenPosTPV\Service\Report\SalesReportService;

/**
 * Sales reports (docs/prd/informes-ventas.md I4): administrators only, as JSON
 * {data, approximate_amount, missing_lines} or as a CSV file (format=csv). Each CSV
 * export is written to audit_log (report_export); a query is not.
 */
#[AsController]
final class ReportController
{
    public function __construct(
        private readonly SalesReportService $reports,
        private readonly ReportCsv $csv,
        private readonly Connection $connection,
    ) {
    }

    #[Route('/reports/{type}', methods: ['GET'])]
    public function show(string $type, Request $request): Response
    {
        $userId = (int) ($request->getSession()->get('user_id') ?? 0);
        if ($userId <= 0) {
            return new JsonResponse(['error' => 'No hay ninguna sesión iniciada'], Response::HTTP_UNAUTHORIZED);
        }
        if ($request->getSession()->get('role') !== 'admin') {
            return new JsonResponse(['error' => 'Solo un administrador puede ver los informes'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->reports->supports($type)) {
            return new JsonResponse(['error' => 'Informe no encontrado'], Response::HTTP_NOT_FOUND);
        }

        try {
            $filter = ReportFilter::fromQuery($type, $request->query->all());
            $report = $this->reports->build($type, $filter);
        } catch (InvalidReportFilter $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ReportNotFound $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($filter->format !== 'csv') {
            return new JsonResponse($report);
        }

        $body = $this->csv->render($type, $report['data']);
        $this->audit($userId, $type, $filter, $request);

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->csv->fileName($type, $filter)),
        ]);
    }

    /** Who exported which report and range; same row shape as the other audit entries. */
    private function audit(int $userId, string $type, ReportFilter $filter, Request $request): void
    {
        $device = $request->attributes->get(DeviceContext::ATTRIBUTE);
        try {
            $this->connection->insert('audit_log', [
                'user_id' => $userId,
                'terminal_id' => \is_int($device) && $device > 0 ? $device : null,
                'action' => 'report_export',
                'target' => $type,
                'payload' => json_encode($filter->describe($type), JSON_THROW_ON_ERROR),
                'ip' => $request->getClientIp(),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best-effort logging, like the other audit entries: the export still answers.
        }
    }
}
