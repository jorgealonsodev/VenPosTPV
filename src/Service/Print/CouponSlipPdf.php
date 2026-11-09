<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/** The coupon slip as an 80 mm PDF, like TicketPdfGenerator renders the tickets. */
class CouponSlipPdf
{
    /** CSS pixels per millimetre (96 dpi). */
    private const PX_PER_MM = 3.7795;
    /** The ticket template's margins when the till sets none (8 px). */
    private const DEFAULT_MARGIN_PX = 8;

    public function __construct(private readonly Environment $twig)
    {
    }

    public function html(CouponSlip $slip): string
    {
        $margin = static fn (?float $mm): float => $mm !== null ? round($mm * self::PX_PER_MM, 2) : self::DEFAULT_MARGIN_PX;
        $logo = $slip->logo !== null && preg_match('/^data:image\/(png|jpe?g|gif);base64,[A-Za-z0-9+\/=\s]+$/', $slip->logo) ? $slip->logo : '';

        return $this->twig->render('coupon/slip.html.twig', [
            'slip' => $slip,
            'logoPath' => $logo,
            'barcodePath' => $this->barcodeDataUri($slip->code),
            'marginLeftPx' => $margin($slip->marginLeftMm),
            'marginRightPx' => $margin($slip->marginRightMm),
        ]);
    }

    public function generate(CouponSlip $slip): string
    {
        // Logo and barcode are data: URIs, so nothing else may be loaded: no remote
        // URLs and no local files (the project holds .env).
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($slip), 'UTF-8');
        $dompdf->setPaper([0, 0, 226.77, 425.2]); // 80 x 150 mm
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function barcodeDataUri(string $code): string
    {
        if (!Code128Encoder::canEncode($code) || !\function_exists('imagecreatetruecolor')) {
            return '';
        }

        try {
            return 'data:image/png;base64,' . base64_encode((new Code128Encoder())->png($code));
        } catch (\Throwable) {
            return '';
        }
    }
}
