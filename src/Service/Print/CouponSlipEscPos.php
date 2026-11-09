<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\GdEscposImage;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\Printer;

/**
 * ESC/POS bytes of a coupon slip, laid out like the tickets (EscPosTicketBuilder):
 * code page 1252, the till's margins on its paper width, the same store header,
 * then the coupon with its code as a Code128 the till scanner can type.
 */
class CouponSlipEscPos
{
    /** 203 dpi printers: 8 dots per mm. */
    private const DOTS_PER_MM = 8;
    /** Width of one Font A character, in dots. */
    private const FONT_A_DOTS = 12;
    /** Narrowest print area kept whatever the margins: 8 characters. */
    private const MIN_PRINT_WIDTH_DOTS = 96;

    public function build(CouponSlip $slip, int $paperWidthMm = 80): string
    {
        $connector = new class () implements PrintConnector {
            private string $buffer = '';

            /** @param string $data */
            public function write($data): void
            {
                $this->buffer .= (string) $data;
            }

            public function finalize(): void
            {
            }

            /** @param string $len */
            public function read($len): string
            {
                return '';
            }

            public function __destruct()
            {
                $this->finalize();
            }

            public function getData(): string
            {
                return $this->buffer;
            }
        };

        $printer = new Printer($connector, CapabilityProfile::load('default'));
        $printer->selectCharacterTable(16);

        $paperDots = $paperWidthMm >= 80 ? 576 : 384;
        $dots = static fn (?float $mm): int => (int) round(($mm ?? TicketData::DEFAULT_MARGIN_MM) * self::DOTS_PER_MM);
        $left = min($dots($slip->marginLeftMm), $paperDots - self::MIN_PRINT_WIDTH_DOTS);
        $printable = max(self::MIN_PRINT_WIDTH_DOTS, $paperDots - $dots($slip->marginLeftMm) - $dots($slip->marginRightMm));
        $printer->setPrintLeftMargin($left);
        $printer->setPrintWidth($printable);
        $columns = intdiv($printable, self::FONT_A_DOTS);

        // Store header, as on the till's tickets.
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $logo = $this->logoImage($slip->logo, $printable, $slip->logoWidthPct);
        if ($logo !== null) {
            $printer->bitImage($logo);
        }
        $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
        $printer->text($this->wrap($slip->storeName, intdiv($columns, 2)) . "\n");
        $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
        if ($slip->legalNameLine() !== null) {
            $printer->text($this->wrap($slip->legalNameLine(), $columns) . "\n");
        }
        if ($slip->taxId !== null) {
            $printer->text('NIF: ' . $this->clean($slip->taxId) . "\n");
        }
        if ($slip->storeAddress !== '') {
            $printer->text($this->wrap($slip->storeAddress, $columns) . "\n");
        }
        $printer->selectPrintMode();
        $printer->text(str_repeat('-', $columns) . "\n");

        // The coupon.
        $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
        $printer->text($this->wrap(CouponSlip::TITLE, $columns) . "\n\n");
        $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
        $printer->text($this->wrap($slip->valueLabel(), intdiv($columns, 2)) . "\n");
        $printer->text($this->wrap($slip->code, intdiv($columns, 2)) . "\n");
        $printer->selectPrintMode();
        $printer->text("\n");
        if (Code128Encoder::canEncode($slip->code)) {
            $printer->setBarcodeHeight(60);
            $printer->setBarcodeWidth(2);
            $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_NONE);
            // "{B" selects code set B; a literal "{" must be sent as "{{".
            $printer->barcode('{B' . str_replace('{', '{{', $slip->code), Printer::BARCODE_CODE128);
            $printer->text("\n");
        }
        $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
        $printer->text($slip->expiryLabel() . "\n");
        $printer->selectPrintMode();
        $printer->text($slip->usesLabel() . ' - ' . $slip->perUserLabel() . "\n");
        $printer->text($slip->createdLabel() . "\n\n");
        $printer->text($this->wrap(CouponSlip::REDEEM_LINE, $columns) . "\n");

        $printer->cut();
        $printer->close();

        return $connector->getData();
    }

    private function clean(string $text): string
    {
        return trim(str_replace(["\n", "\r"], ' ', $text));
    }

    /** Lines of at most $columns characters, broken at spaces when possible. */
    private function wrap(string $text, int $columns): string
    {
        $columns = max(1, $columns);
        $lines = [];
        $current = '';
        foreach (preg_split('/ +/u', $this->clean($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $joined = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($joined) <= $columns) {
                $current = $joined;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $chunks = mb_str_split($word, $columns);
            $current = (string) array_pop($chunks);
            array_push($lines, ...$chunks);
        }
        $lines[] = $current;

        return implode("\n", $lines);
    }

    /** The till's logo flattened on white and scaled to the print area; skipped when unreadable. */
    private function logoImage(?string $dataUri, int $printableDots, ?int $widthPct): ?GdEscposImage
    {
        if ($dataUri === null || !\function_exists('imagecreatefromstring')
            || !preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,(.+)$#s', $dataUri, $m)) {
            return null;
        }

        try {
            $bytes = base64_decode($m[1], true);
            $source = $bytes !== false ? @imagecreatefromstring($bytes) : false;
            if ($source === false) {
                return null;
            }
            $width = imagesx($source);
            $targetWidth = $widthPct !== null ? max(1, (int) round($printableDots * $widthPct / 100)) : min($printableDots, $width);
            $targetHeight = max(1, (int) round(imagesy($source) * $targetWidth / $width));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, imagesy($source));
            $image = new GdEscposImage();
            /** @phpstan-ignore argument.type */
            $image->readImageFromGdResource($canvas);

            return $image;
        } catch (\Throwable) {
            return null;
        }
    }
}
