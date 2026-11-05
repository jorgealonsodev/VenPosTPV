<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Invoice;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use VenPosTPV\Service\Customer\CustomerTaxData;

/**
 * The full invoice as an A4 PDF (docs/prd/factura-completa.md F6), never on the
 * ticket printer. Built only from the stored invoice, so a copy is the same
 * document as the original.
 */
class InvoicePdfGenerator
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /** @param array<string, mixed> $invoice InvoiceRepository::view() */
    public function html(array $invoice): string
    {
        return $this->twig->render('invoice/invoice.html.twig', [
            'invoice' => $invoice,
            'documentTypes' => [CustomerTaxData::SPANISH_NIF => 'NIF'] + CustomerTaxData::FOREIGN_TYPES,
        ]);
    }

    /** @param array<string, mixed> $invoice InvoiceRepository::view() */
    public function generate(array $invoice): string
    {
        // Text only: nothing may be loaded, neither remote URLs nor local files.
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($invoice), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param array<string, mixed> $invoice */
    public static function fileName(array $invoice): string
    {
        return sprintf('factura-%s.pdf', preg_replace('/[^A-Za-z0-9-]/', '', (string) $invoice['number']));
    }
}
