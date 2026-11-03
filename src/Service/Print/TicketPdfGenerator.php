<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Twig\Environment;

class TicketPdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function generate(TicketData $ticketData, bool $giftMode = false): string
    {
        // Images are embedded as data: URIs, never written to disk: the web server
        // user may not be allowed to write in the project, and then the QR, the
        // barcode and the logo silently disappeared from the PDF.
        $qrPath = $this->qrDataUri($ticketData->verificationUrl);

        // Code128 of the sale reference (sale and gift tickets only, not credit notes)
        $barcodePath = $ticketData->creditNote === null ? $this->barcodeDataUri($ticketData->reference) : '';

        $logoPath = '';
        if ($ticketData->ticketLogo !== null && preg_match('/^data:image\/(png|jpe?g|gif);base64,[A-Za-z0-9+\/=\s]+$/', $ticketData->ticketLogo)) {
            $logoPath = $ticketData->ticketLogo;
        }

        $html = $this->twig->render('ticket/print.html.twig', [
            'ticket'   => $ticketData,
            'giftMode' => $giftMode,
            'qrPath'   => $qrPath,
            'barcodePath' => $barcodePath,
            'logoPath' => $logoPath,
        ]);

        $options = new Options();
        $options->setIsRemoteEnabled(true);
        $options->setChroot($this->projectDir());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 226.77, 841.89]); // 80mm width in points
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function qrDataUri(string $url): string
    {
        try {
            $qrCode = QrCode::create($url)->setSize(150)->setMargin(4);

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

    private function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
