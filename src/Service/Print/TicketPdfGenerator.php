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
        $tmpFiles = [];

        // QR — generate locally as temp file
        $qrPath = $this->generateQrTempFile($ticketData->verificationUrl, $tmpFiles);

        // Logo — write base64 to temp file; return relative path for Dompdf chroot
        $logoPath = '';
        if ($ticketData->ticketLogo !== null && $ticketData->ticketLogo !== '') {
            $logoPath = $this->base64ToTempFile($ticketData->ticketLogo, $tmpFiles);
        }

        $html = $this->twig->render('ticket/print.html.twig', [
            'ticket'   => $ticketData,
            'giftMode' => $giftMode,
            'qrPath'   => $qrPath,
            'logoPath' => $logoPath,
        ]);

        $options = new Options();
        $options->setIsRemoteEnabled(true);
        $options->setChroot($this->projectDir());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 226.77, 841.89]); // 80mm width in points
        $dompdf->render();

        $output = (string) $dompdf->output();

        // Clean up temp files
        foreach ($tmpFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        return $output;
    }

    /** @param string[] $tmpFiles */
    private function generateQrTempFile(string $url, array &$tmpFiles): string
    {
        try {
            $qrCode = QrCode::create($url)->setSize(150)->setMargin(4);
            $result = (new PngWriter())->write($qrCode);

            $path = $this->tempImagePath('tpv_qr_', 'png');
            if ($path === '') {
                return '';
            }
            if (@file_put_contents($path, $result->getString()) === false) {
                return '';
            }
            $tmpFiles[] = $path;

            // Return path relative to chroot (project dir) for Dompdf
            return 'var/ticket-assets/' . basename($path);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param string[] $tmpFiles */
    private function base64ToTempFile(string $dataUri, array &$tmpFiles): string
    {
        // Strip "data:image/png;base64," prefix
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $dataUri, $m)) {
            $ext  = $m[1];
            $data = base64_decode($m[2], true);
            if ($data !== false) {
                $path = $this->tempImagePath('tpv_logo_', $ext);
                if ($path === '') {
                    return '';
                }
                if (@file_put_contents($path, $data) === false) {
                    return '';
                }
                $tmpFiles[] = $path;
                // Return path relative to chroot (project dir) for Dompdf
                return 'var/ticket-assets/' . basename($path);
            }
        }
        return '';
    }

    private function tempImagePath(string $prefix, string $extension): string
    {
        $dir = $this->projectDir() . '/var/ticket-assets';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }

        if (!is_writable($dir)) {
            return '';
        }

        $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?: 'png';

        return $dir . '/' . $prefix . bin2hex(random_bytes(8)) . '.' . $safeExtension;
    }

    private function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
