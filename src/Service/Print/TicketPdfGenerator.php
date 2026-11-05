<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Twig\Environment;
use VenPosTPV\Service\Fiscal\FiscalQr;

class TicketPdfGenerator
{
    /** CSS pixels per millimetre (96 dpi). */
    private const PX_PER_MM = 3.7795;

    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function generate(TicketData $ticketData, bool $giftMode = false): string
    {
        // Images are embedded as data: URIs, never written to disk: the web server
        // user may not be allowed to write in the project, and then the QR, the
        // barcode and the logo silently disappeared from the PDF.
        $qrPath = $ticketData->showsQr() ? $this->qrDataUri($ticketData->verificationUrl) : '';

        // Code128 of the sale reference (sale and gift tickets only, not credit notes)
        $barcodePath = $ticketData->creditNote === null && $ticketData->showsBarcode() ? $this->barcodeDataUri($ticketData->reference) : '';

        $logoPath = '';
        if ($ticketData->ticketLogo !== null && preg_match('/^data:image\/(png|jpe?g|gif);base64,[A-Za-z0-9+\/=\s]+$/', $ticketData->ticketLogo)) {
            $logoPath = $ticketData->ticketLogo;
        }

        // VERI*FACTU: the tax QR (error correction M) at the top of the ticket.
        $fiscalQrPath = $ticketData->fiscalQrUrl !== null ? $this->qrDataUri($ticketData->fiscalQrUrl, true) : '';

        // Ajustes → Cajas: the till's margins (mm to CSS px) and logo width. Unset ones
        // are left out so the template keeps its 8 px margins and full-width logo.
        $layout = [];
        foreach (['marginLeftPx' => $ticketData->ticketMarginLeftMm, 'marginRightPx' => $ticketData->ticketMarginRightMm] as $var => $mm) {
            if ($mm !== null) {
                $layout[$var] = round($mm * self::PX_PER_MM, 2);
            }
        }
        if ($ticketData->ticketLogoWidthPct !== null) {
            $layout['logoWidthPct'] = $ticketData->ticketLogoWidthPct;
        }

        $html = $this->twig->render('ticket/print.html.twig', $layout + [
            'ticket'   => $ticketData,
            'giftMode' => $giftMode,
            'qrPath'   => $qrPath,
            'barcodePath' => $barcodePath,
            'logoPath' => $logoPath,
            'fiscalQrPath' => $fiscalQrPath,
            'fiscalQrTitle' => FiscalQr::TITLE,
            'fiscalQrLegend' => FiscalQr::LEGEND,
        ]);

        $options = new Options();
        // Logo, QR and barcode are all data: URIs, so nothing else may be loaded:
        // no remote URLs and no local files (the project holds .env).
        $options->setIsRemoteEnabled(false);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 226.77, 841.89]); // 80mm width in points
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function qrDataUri(string $url, bool $fiscal = false): string
    {
        try {
            $qrCode = QrCode::create($url)->setSize(150)->setMargin(4);
            if ($fiscal) {
                $qrCode = $qrCode->setErrorCorrectionLevel(new ErrorCorrectionLevelMedium());
            }

            return 'data:image/png;base64,' . base64_encode((new PngWriter())->write($qrCode)->getString());
        } catch (\Throwable) {
            return '';
        }
    }

    private function barcodeDataUri(string $reference): string
    {
        if (!Code128Encoder::canEncode($reference) || !\function_exists('imagecreatetruecolor')) {
            return '';
        }

        try {
            return 'data:image/png;base64,' . base64_encode((new Code128Encoder())->png($reference));
        } catch (\Throwable) {
            return '';
        }
    }
}
