<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

/**
 * The X report (the open till, nothing closed) and the Z report (the close of a
 * till, with the numbers stored when it closed). lines() is the content of both the
 * ticket and the PDF, so the two never differ.
 */
final class CashReport
{
    public const X = 'X';
    public const Z = 'Z';

    /**
     * @param array<string, mixed> $totals the CashSessionService totals (the stored z_report.totals for a Z)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $sessionId,
        public readonly int $terminalId,
        public readonly string $terminalName,
        public readonly ?string $terminalLetter,
        public readonly string $storeName,
        public readonly ?string $legalName,
        public readonly ?string $taxId,
        public readonly string $cashierName,
        public readonly \DateTimeImmutable $openedAt,
        public readonly ?\DateTimeImmutable $closedAt,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly array $totals,
        public readonly ?float $countedCash = null,
        public readonly ?float $difference = null,
        public readonly ?string $signatureHash = null,
    ) {
    }

    public function isZ(): bool
    {
        return $this->kind === self::Z;
    }

    public function fileName(): string
    {
        return sprintf('informe-%s-%d.pdf', strtolower($this->kind), $this->sessionId);
    }

    /** «1.234,56 €», like the PDF tickets. */
    public static function money(float $amount): string
    {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    /**
     * The report as lines of at most $width characters (32 on 58 mm paper, 48 on
     * 80 mm). The header lines are centred; amounts are right-aligned.
     *
     * @return list<string>
     */
    public function lines(int $width = 32): array
    {
        return array_map(static fn (array $row): string => $row['text'], $this->rows($width));
    }

    /**
     * @return list<array{text: string, center: bool, bold: bool}>
     */
    public function rows(int $width = 32): array
    {
        $rows = [];
        $center = function (string $text, bool $bold = false) use (&$rows, $width): void {
            $text = $this->fit($text, $width);
            $pad = intdiv($width - mb_strlen($text), 2);
            $rows[] = ['text' => str_repeat(' ', $pad) . $text, 'center' => true, 'bold' => $bold];
        };
        $left = function (string $text, bool $bold = false) use (&$rows, $width): void {
            $rows[] = ['text' => $this->fit($text, $width), 'center' => false, 'bold' => $bold];
        };
        $amount = function (string $label, string $value, bool $bold = false) use (&$rows, $width): void {
            $label = $this->fit($label, max(1, $width - mb_strlen($value) - 1));
            $rows[] = ['text' => $label . str_repeat(' ', max(1, $width - mb_strlen($label) - mb_strlen($value))) . $value, 'center' => false, 'bold' => $bold];
        };
        $rule = static function () use (&$rows, $width): void {
            $rows[] = ['text' => str_repeat('-', $width), 'center' => false, 'bold' => false];
        };
        $money = fn (string $key): string => self::money($this->number($key));

        $center($this->storeName, true);
        if ($this->legalName !== null && $this->legalName !== $this->storeName) {
            $center($this->legalName);
        }
        if ($this->taxId !== null) {
            $center('NIF: ' . $this->taxId);
        }
        $left('');
        $center('INFORME ' . $this->kind, true);
        $center($this->isZ() ? 'Cierre de caja' : 'Caja abierta, sin cerrar');
        $left('');
        $left('Caja: ' . $this->terminalName . ($this->terminalLetter !== null ? ' (' . $this->terminalLetter . ')' : ''));
        $left('Cajero: ' . $this->cashierName);
        $left('Apertura: ' . $this->openedAt->format('d/m/Y H:i'));
        if ($this->isZ() && $this->closedAt !== null) {
            $left('Cierre: ' . $this->closedAt->format('d/m/Y H:i'));
        } else {
            $left('Fecha: ' . $this->issuedAt->format('d/m/Y H:i'));
        }
        $rule();
        $amount('Fondo inicial', $money('opening_cash'));
        $amount('Ventas efectivo', $money('sales_cash'));
        $amount('Ventas tarjeta', $money('sales_card'));
        // Transfer lines only on a till that had transfers: older reports stay as they were.
        $transfers = $this->number('sales_transfer') != 0.0 || $this->number('refunds_transfer') != 0.0;
        if ($transfers) {
            $amount('Ventas transferencia', $money('sales_transfer'));
            if ($this->number('sales_transfer_pending') != 0.0) {
                // Part of the line above: not received in the bank yet.
                $amount('Pendiente de pago', $money('sales_transfer_pending'));
            }
        }
        $amount('Total ventas', $money('sales_total'), true);
        $amount('Devoluciones efectivo', $money('refunds_cash'));
        $amount('Devoluciones tarjeta', $money('refunds_card'));
        if ($transfers) {
            // Shorter: «Devoluciones transferencia» and its amount do not fit in 32 characters.
            $amount('Devol. transferencia', $money('refunds_transfer'));
        }
        $amount('Devoluciones vale', $money('refunds_voucher'));
        $amount('Entradas de efectivo', $money('cash_in_total'));
        $amount('Salidas de efectivo', $money('cash_out_total'));
        $amount('Neto', $money('net_total'), true);
        $rule();
        $amount('Efectivo esperado', $money('expected_cash'), true);
        if ($this->isZ()) {
            $amount('Efectivo contado', self::money((float) $this->countedCash), true);
            $difference = round((float) $this->difference, 2);
            $amount('Diferencia', ($difference > 0 ? '+' : '') . self::money($difference), true);
        }
        $rule();
        $counts = \is_array($this->totals['counts'] ?? null) ? $this->totals['counts'] : [];
        // Cash sales are counted by their drawer movement; refunds are all refunds done.
        $amount('Ventas en efectivo (n.º)', (string) (int) ($counts['sale'] ?? 0));
        $amount('Devoluciones (n.º)', (string) (int) ($counts['refunds'] ?? 0));
        if ($this->isZ() && $this->signatureHash !== null) {
            $left('');
            $left('Huella: ' . substr($this->signatureHash, 0, 16));
        }

        return $rows;
    }

    /** ESC/POS bytes for the ticket printer, in WPC1252 like the sale tickets. */
    public function escPos(int $width): string
    {
        $bytes = "\x1B@" . "\x1Bt\x10";
        foreach ($this->rows($width) as $row) {
            $bytes .= ($row['bold'] ? "\x1BE\x01" : '') . self::toWpc1252(rtrim($row['text'])) . "\n" . ($row['bold'] ? "\x1BE\x00" : '');
        }

        return $bytes . "\n\n\n" . "\x1DV\x41\x03";
    }

    private function number(string $key): float
    {
        return round((float) ($this->totals[$key] ?? 0), 2);
    }

    private function fit(string $text, int $width): string
    {
        $text = trim(str_replace(["\r", "\n"], ' ', $text));

        return mb_strlen($text) > $width ? mb_substr($text, 0, $width) : $text;
    }

    private static function toWpc1252(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $converted === false ? $text : $converted;
    }
}
