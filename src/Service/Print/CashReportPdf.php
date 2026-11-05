<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * The PDF of an X or Z report: the same lines as the ticket, on 80 mm paper, for a
 * till without printer and for the closes consulted from Ajustes.
 */
class CashReportPdf
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function generate(CashReport $report): string
    {
        $html = $this->twig->render('cash/report.html.twig', [
            'report' => $report,
            'rows' => $report->rows(32),
        ]);

        // Text only: nothing may be loaded, neither remote URLs nor local files.
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 226.77, 841.89]); // 80 mm wide, like the tickets
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
