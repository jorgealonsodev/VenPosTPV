<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\PrintConnectors\PrintConnector;
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

        // Header
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH | Printer::MODE_EMPHASIZED);
        $printer->text($this->sanitize($ticketData->storeName) . "\n");
        $printer->selectPrintMode(Printer::MODE_EMPHASIZED);
        if ($ticketData->storeAddress !== '') {
            $printer->text($this->sanitize($ticketData->storeAddress) . "\n");
        }
        $this->printMultilineCentered($printer, $ticketData->ticketHeader);
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
            $printer->text('Motivo: ' . $this->sanitize($creditNote->reason) . "\n");
        } else {
            $printer->text('Ref: ' . $ticketData->reference . "\n");
        }
        $printer->text('Cajero: ' . $this->sanitize($ticketData->cashierName) . "\n");
        $printer->text(str_repeat('-', 32) . "\n");

        // Items
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        foreach ($ticketData->lines as $line) {
            $name = $this->sanitize($line->name);
            $qty = $line->quantity;

            $printer->text("{$name}\n");
            if (!$giftMode) {
                $unit = number_format($line->unitPrice, 2);
                $total = number_format($line->lineTotal, 2);
                $printer->text("  {$qty} x {$unit} € = {$total} €\n");
            } else {
                $printer->text("  Cant: {$qty}\n");
            }
        }

        $printer->text(str_repeat('-', 32) . "\n");

        if ($creditNote !== null) {
            $printer->setJustification(Printer::JUSTIFY_RIGHT);
            $printer->text('Base: ' . number_format($ticketData->subtotal, 2) . " €\n");
            $printer->text('IVA: ' . number_format($ticketData->tax, 2) . " €\n");
            $printer->selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_EMPHASIZED);
            $printer->text('TOTAL DEVUELTO: ' . number_format($ticketData->total, 2) . " €\n");
            $printer->selectPrintMode();
            $printer->text("\n");
            if ($creditNote->cashAmount > 0) {
                $printer->text('Efectivo: ' . number_format($creditNote->cashAmount, 2) . " €\n");
            }
            if ($creditNote->cardAmount > 0) {
                $printer->text('Tarjeta: ' . number_format($creditNote->cardAmount, 2) . " €\n");
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
            $printer->text('Metodo: ' . $this->paymentMethodLabel($ticketData->paymentMethod) . "\n");
            $printer->text('Entrega: ' . number_format($ticketData->amountTendered, 2) . " €\n");
            if ($ticketData->changeDue > 0) {
                $printer->text('Cambio: ' . number_format($ticketData->changeDue, 2) . " €\n");
            }

            $printer->text("\n");
        }

        $printer->setJustification(Printer::JUSTIFY_CENTER);

        // Code128 of the sale reference, so the refund screen can scan it (credit notes have none)
        if ($creditNote === null && Code128Encoder::canEncode($ticketData->reference)) {
            $printer->setBarcodeHeight(60);
            $printer->setBarcodeWidth(2);
            $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_BELOW);
            // "{B" selects code set B; a literal "{" must be sent as "{{"
            $printer->barcode('{B' . str_replace('{', '{{', $ticketData->reference), Printer::BARCODE_CODE128);
            $printer->text("\n");
        }

        // QR code
        $printer->qrCode($ticketData->verificationUrl, Printer::QR_ECLEVEL_M, 6);
        $printer->text("\n");
        $this->printMultilineCentered($printer, $ticketData->ticketFooter !== '' ? $ticketData->ticketFooter : 'Gracias por su compra');

        // Cut
        $printer->cut();
        $printer->close();

        return $connector->getData();
    }

    private function sanitize(string $text): string
    {
        // CodePage 858 supports most Western European chars including €, ñ, á, é, í, ó, ú
        return str_replace(["\n", "\r"], ' ', $text);
    }

    private function printMultilineCentered(Printer $printer, string $text): void
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

            $printer->text($this->sanitize($line) . "\n");
        }
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'mixed' => 'Mixto',
            'voucher' => 'Vale',
            default => $method,
        };
    }
}
