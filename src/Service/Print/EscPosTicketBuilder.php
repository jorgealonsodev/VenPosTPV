<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use VenPosTPV\Service\Fiscal\FiscalQr;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\GdEscposImage;
use Mike42\Escpos\Printer;

class EscPosTicketBuilder
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function build(TicketData $ticketData, bool $giftMode = false): string
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

        $profile = CapabilityProfile::load('default');
        $printer = new Printer($connector, $profile);

        // Spanish charset via CP1252 (table 16) — supports ñ, á, é, í, ó, ú, €
        $printer->selectCharacterTable(16);

        // Ajustes → Cajas: the side margins as GS L (left margin) and GS W (print
        // area width); every line is then laid out in the columns left between them.
        [$leftDots, $printableDots] = $this->printArea($ticketData);
        $printer->setPrintLeftMargin($leftDots);
        $printer->setPrintWidth($printableDots);
        $columns = intdiv($printableDots, self::FONT_A_DOTS);
        $separator = str_repeat('-', $columns) . "\n";

        // Header
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if ($ticketData->fiscalQrUrl !== null) {
            // VERI*FACTU: the tax QR comes first on the ticket, 30-40 mm, error
            // correction M (6 dots per module: about 34 mm for this URL at 203 dpi).
            $printer->text(FiscalQr::TITLE . "\n");
            $printer->qrCode($ticketData->fiscalQrUrl, Printer::QR_ECLEVEL_M, 6);
            $printer->text(FiscalQr::LEGEND . "\n\n");
        }
        $logo = $this->logoImage($ticketData->ticketLogo, $printableDots, $ticketData->ticketLogoWidthPct);
        if ($logo !== null) {
            $printer->bitImage($logo);
        }
        $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
        $printer->text($this->wrap($this->sanitize($ticketData->storeName), intdiv($columns, 2)) . "\n");
        $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
        if ($ticketData->legalName !== null && $ticketData->legalName !== $ticketData->storeName) {
            $printer->text($this->wrap($this->sanitize($ticketData->legalName), $columns) . "\n");
        }
        if ($ticketData->taxId !== null) {
            $printer->text('NIF: ' . $this->sanitize($ticketData->taxId) . "\n");
        }
        if ($ticketData->storeAddress !== '') {
            $printer->text($this->wrap($this->sanitize($ticketData->storeAddress), $columns) . "\n");
        }
        $this->printMultilineCentered($printer, $ticketData->ticketHeader, $columns);
        $printer->selectPrintMode();
        $printer->text("\n");
        $creditNote = $ticketData->creditNote;
        if ($creditNote !== null) {
            $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $printer->text('ABONO ' . $creditNote->number . "\n");
            $printer->selectPrintMode();
        }
        $printer->text($ticketData->date->format('d/m/Y H:i') . "\n");
        if ($creditNote !== null) {
            $rectifies = 'Rectifica: ' . $creditNote->rectifiesReference;
            if ($creditNote->rectifiesDate !== null) {
                $rectifies .= ' (' . $creditNote->rectifiesDate->format('d/m/Y') . ')';
            }
            $printer->text($rectifies . "\n");
            $printer->text($this->wrap('Motivo: ' . $this->sanitize($creditNote->reason), $columns) . "\n");
        } else {
            $printer->text('Ref: ' . $ticketData->reference . "\n");
            if ($ticketData->webOrderReference !== null && $ticketData->webOrderReference !== '') {
                $printer->text('Pedido web: ' . $this->sanitize($ticketData->webOrderReference) . "\n");
            }
        }
        $printer->text('Cajero: ' . $this->sanitize($ticketData->cashierName) . "\n");
        $printer->text($separator);

        // Items
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        foreach ($ticketData->lines as $line) {
            $name = $this->sanitize($line->name);
            $qty = $line->quantity;

            $printer->text($this->wrap($name, $columns) . "\n");
            if (!$giftMode) {
                $unit = number_format($line->unitPrice, 2);
                $total = number_format($line->lineTotal, 2);
                $printer->text($this->wrap("  {$qty} x {$unit} € = {$total} €", $columns) . "\n");
            } else {
                $printer->text("  Cant: {$qty}\n");
            }
        }

        $printer->text($separator);

        if ($creditNote !== null) {
            $printer->setJustification(Printer::JUSTIFY_RIGHT);
            $printer->text('Base: ' . number_format($ticketData->subtotal, 2) . " €\n");
            $printer->text('IVA: ' . number_format($ticketData->tax, 2) . " €\n");
            $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $printer->text('TOTAL DEVUELTO: ' . number_format($ticketData->total, 2) . " €\n");
            $printer->selectPrintMode();
            $printer->text("\n");
            if ($creditNote->exchangeCredit !== null) {
                $printer->text('Aplicado a cambio: ' . number_format($creditNote->exchangeCredit, 2) . " €\n");
            }
            if ($creditNote->cashAmount > 0) {
                $printer->text('Efectivo: ' . number_format($creditNote->cashAmount, 2) . " €\n");
            }
            if ($creditNote->cardAmount > 0) {
                $printer->text('Tarjeta: ' . number_format($creditNote->cardAmount, 2) . " €\n");
            }
            if ($creditNote->webRefundAmount !== null && $creditNote->webRefundAmount > 0) {
                $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
                $printer->text('Reembolso pendiente en la web: ' . number_format($creditNote->webRefundAmount, 2) . " €\n");
                $printer->selectPrintMode();
            }
            if ($creditNote->voucherCode !== null) {
                $printer->text('Vale: ' . $this->sanitize($creditNote->voucherCode) . '  ' . number_format((float) $creditNote->voucherAmount, 2) . " €\n");
            }
            $printer->text("\n");
        } elseif (!$giftMode) {
            // Totals
            $printer->setJustification(Printer::JUSTIFY_RIGHT);
            $printer->text('Subtotal: ' . number_format($ticketData->subtotal, 2) . " €\n");
            $printer->text('IVA: ' . number_format($ticketData->tax, 2) . " €\n");
            $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
            $printer->text('TOTAL: ' . number_format($ticketData->total, 2) . " €\n");
            $printer->selectPrintMode();

            $printer->text("\n");
            if ($ticketData->exchangeCredit !== null) {
                $printer->text('Abono aplicado: ' . number_format($ticketData->exchangeCredit, 2) . " €\n");
                if ($ticketData->exchangeCreditNote !== null) {
                    $printer->text('Abono: ' . $this->sanitize($ticketData->exchangeCreditNote) . "\n");
                }
            }
            $printer->text('Metodo: ' . $ticketData->paymentMethodLabel() . "\n");
            // Nothing is tendered when the exchange credit pays the whole sale.
            if ($ticketData->paymentMethod !== 'exchange') {
                $printer->text('Entrega: ' . number_format($ticketData->amountTendered, 2) . " €\n");
            }
            if ($ticketData->changeDue > 0) {
                $printer->text('Cambio: ' . number_format($ticketData->changeDue, 2) . " €\n");
            }

            $printer->text("\n");
        }

        $printer->setJustification(Printer::JUSTIFY_CENTER);

        // Code128 of the sale reference, so the refund screen can scan it (credit notes have none)
        if ($creditNote === null && $ticketData->showsBarcode() && Code128Encoder::canEncode($ticketData->reference)) {
            $printer->setBarcodeHeight(60);
            $printer->setBarcodeWidth(2);
            $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_BELOW);
            // "{B" selects code set B; a literal "{" must be sent as "{{"
            $printer->barcode('{B' . str_replace('{', '{{', $ticketData->reference), Printer::BARCODE_CODE128);
            $printer->text("\n");
        }

        if ($ticketData->showsQr()) {
            $printer->qrCode($ticketData->verificationUrl, Printer::QR_ECLEVEL_M, 6);
            $printer->text("\n");
        }
        $this->printMultilineCentered($printer, $ticketData->ticketFooter !== '' ? $ticketData->ticketFooter : 'Gracias por su compra', $columns);

        // Cut
        $printer->cut();
        $printer->close();

        return $connector->getData();
    }

    /** 203 dpi printers: 8 dots per mm. */
    private const DOTS_PER_MM = 8;

    /** Width of one Font A character, in dots. */
    private const FONT_A_DOTS = 12;

    /** Narrowest print area kept whatever the margins: 8 characters. */
    private const MIN_PRINT_WIDTH_DOTS = 96;

    /**
     * Left margin and print area width, in dots, from the till's margins (2 mm each
     * side when unset) on the printable width of the paper: 576 dots on 80 mm paper,
     * 384 on 58 mm.
     *
     * @return array{int, int}
     */
    private function printArea(TicketData $ticketData): array
    {
        $paperDots = (int) ($this->config['paperWidth'] ?? 80) >= 80 ? 576 : 384;
        $dots = static fn (?float $mm): int => (int) round(($mm ?? TicketData::DEFAULT_MARGIN_MM) * self::DOTS_PER_MM);
        $left = $dots($ticketData->ticketMarginLeftMm);
        $width = max(self::MIN_PRINT_WIDTH_DOTS, $paperDots - $left - $dots($ticketData->ticketMarginRightMm));

        return [min($left, $paperDots - self::MIN_PRINT_WIDTH_DOTS), $width];
    }

    /**
     * Turns the terminal's logo (a data: URI uploaded in Settings) into a printable
     * raster image: transparency flattened on white and scaled to the print area,
     * to the till's percentage of it when set, otherwise only shrunk to fit. A logo
     * that cannot be read is skipped so the ticket always prints.
     */
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
            $height = imagesy($source);
            $targetWidth = $widthPct !== null
                ? max(1, (int) round($printableDots * $widthPct / 100))
                : min($printableDots, $width);
            $targetHeight = max(1, (int) round($height * $targetWidth / $width));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            $image = new GdEscposImage();
            // mike42 documents a GD resource; since PHP 8 GD returns GdImage objects, which it accepts.
            /** @phpstan-ignore argument.type */
            $image->readImageFromGdResource($canvas);

            return $image;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitize(string $text): string
    {
        // CodePage 858 supports most Western European chars including €, ñ, á, é, í, ó, ú
        return str_replace(["\n", "\r"], ' ', $text);
    }

    /**
     * Breaks text into lines of at most $columns characters, at spaces when it can,
     * so the printer never cuts a word at the edge of the print area.
     */
    private function wrap(string $text, int $columns): string
    {
        $columns = max(1, $columns);
        // A leading indent (the "  1 x 2.00 €" line) stays on the first line.
        $indent = (string) preg_replace('/^( *).*$/su', '$1', $text);
        $lines = [];
        $current = $indent;
        $started = false;
        foreach (preg_split('/ +/u', ltrim($text, ' '), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = (string) $word;
            $joined = $started ? $current . ' ' . $word : $current . $word;
            if (mb_strlen($joined) <= $columns) {
                $current = $joined;
                $started = true;
                continue;
            }
            if ($started) {
                $lines[] = $current;
                $current = '';
            }
            // A word longer than a line is split across lines.
            $chunks = mb_str_split($current . $word, $columns);
            $current = (string) array_pop($chunks);
            array_push($lines, ...$chunks);
            $started = true;
        }
        $lines[] = $current;

        return implode("\n", $lines);
    }

    private function printMultilineCentered(Printer $printer, string $text, int $columns): void
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return;
        }

        foreach (preg_split('/\r\n|\r|\n/', $normalized) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                $printer->text("\n");
                continue;
            }

            $printer->text($this->wrap($this->sanitize($line), $columns) . "\n");
        }
    }
}
