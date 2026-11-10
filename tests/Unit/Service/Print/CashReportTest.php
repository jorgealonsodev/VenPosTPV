<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Print\CashReport;
use VenPosTPV\Service\Print\CashReportPdf;

/**
 * The X report (open till) and the Z report (closed till): the same lines on the
 * ticket printer (32 or 48 characters) and in the PDF.
 */
final class CashReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private function totals(): array
    {
        return [
            'opening_cash' => 100.0, 'sales_cash' => 1234.56, 'sales_card' => 80.0, 'sales_total' => 1314.56,
            'refunds_cash' => 12.0, 'refunds_card' => 8.0, 'refunds_voucher' => 5.0, 'refunds_total' => 25.0,
            'refunds_web_pending' => 0.0, 'net_total' => 1289.56, 'exchange_credit' => 0.0,
            'cash_in_total' => 20.0, 'cash_out_total' => 30.0, 'expected_cash' => 1312.56,
            'counts' => ['sale' => 7, 'refund' => 1, 'cash_in' => 1, 'cash_out' => 1, 'refunds' => 3, 'exchanges' => 0],
        ];
    }

    private function z(): CashReport
    {
        return new CashReport(
            kind: CashReport::Z, sessionId: 41, terminalId: 3, terminalName: 'Caja 1', terminalLetter: 'A',
            storeName: 'Mi Tienda', legalName: 'Mi Tienda SL', taxId: 'B12345678', cashierName: 'Ana',
            openedAt: new \DateTimeImmutable('2026-11-05 09:00:00'), closedAt: new \DateTimeImmutable('2026-11-05 21:15:00'),
            issuedAt: new \DateTimeImmutable('2026-11-05 21:15:00'), totals: $this->totals(),
            countedCash: 1310.0, difference: -2.56, signatureHash: 'abcdef0123456789ffffeeee',
        );
    }

    private function x(): CashReport
    {
        return new CashReport(
            kind: CashReport::X, sessionId: 41, terminalId: 3, terminalName: 'Caja 1', terminalLetter: null,
            storeName: 'Mi Tienda', legalName: null, taxId: null, cashierName: 'Ana',
            openedAt: new \DateTimeImmutable('2026-11-05 09:00:00'), closedAt: null,
            issuedAt: new \DateTimeImmutable('2026-11-05 13:30:00'), totals: $this->totals(),
        );
    }

    public function testTheZReportHasEveryLineOfTheCloseWithItsStoredNumbers(): void
    {
        $text = implode("\n", $this->z()->lines(32));

        foreach ([
            'INFORME Z', 'Mi Tienda', 'Mi Tienda SL', 'NIF: B12345678', 'Caja: Caja 1 (A)', 'Cajero: Ana',
            'Apertura: 05/11/2026 09:00', 'Cierre: 05/11/2026 21:15',
            'Huella: abcdef0123456789',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        foreach ([
            'Fondo inicial' => '100,00 €', 'Ventas efectivo' => '1.234,56 €', 'Ventas tarjeta' => '80,00 €',
            'Total ventas' => '1.314,56 €', 'Devoluciones efectivo' => '12,00 €', 'Devoluciones tarjeta' => '8,00 €',
            'Devoluciones vale' => '5,00 €', 'Entradas de efectivo' => '20,00 €', 'Salidas de efectivo' => '30,00 €',
            'Neto' => '1.289,56 €', 'Efectivo esperado' => '1.312,56 €', 'Efectivo contado' => '1.310,00 €',
            'Diferencia' => '-2,56 €', 'Ventas en efectivo (n.º)' => '7', 'Devoluciones (n.º)' => '3',
        ] as $label => $amount) {
            $this->assertMatchesRegularExpression('/^' . preg_quote($label, '/') . ' +' . preg_quote($amount, '/') . '$/m', $text, $label);
        }
        $this->assertStringNotContainsString('abcdef0123456789f', $text, 'only the first 16 characters of the hash');
        foreach ($this->z()->lines(32) as $line) {
            $this->assertLessThanOrEqual(32, mb_strlen($line), $line);
        }
        foreach ($this->z()->lines(48) as $line) {
            $this->assertLessThanOrEqual(48, mb_strlen($line), $line);
        }
        $net = array_values(array_filter($this->z()->lines(48), static fn (string $l): bool => str_starts_with($l, 'Neto ')));
        $this->assertSame(48, mb_strlen($net[0]), 'amounts are right-aligned to the paper width');
    }

    public function testTheXReportIsTheOpenTillWithoutCountNorHash(): void
    {
        $text = implode("\n", $this->x()->lines(32));

        $this->assertStringContainsString('INFORME X', $text);
        $this->assertStringContainsString('Caja: Caja 1', $text);
        $this->assertStringContainsString('Apertura: 05/11/2026 09:00', $text);
        $this->assertStringContainsString('Fecha: 05/11/2026 13:30', $text);
        $this->assertMatchesRegularExpression('/^Efectivo esperado +1\.312,56 €$/m', $text);
        foreach (['INFORME Z', 'Cierre:', 'Efectivo contado', 'Diferencia', 'Huella', 'NIF:'] as $absent) {
            $this->assertStringNotContainsString($absent, $text);
        }
    }

    public function testTransferLinesShowOnlyOnATillThatHadTransfers(): void
    {
        $without = implode("\n", $this->x()->lines(32));
        $this->assertStringNotContainsString('transferencia', $without, 'a till without transfers prints as before');

        $report = new CashReport(
            kind: CashReport::X, sessionId: 41, terminalId: 3, terminalName: 'Caja 1', terminalLetter: null,
            storeName: 'Mi Tienda', legalName: null, taxId: null, cashierName: 'Ana',
            openedAt: new \DateTimeImmutable('2026-11-05 09:00:00'), closedAt: null,
            issuedAt: new \DateTimeImmutable('2026-11-05 13:30:00'), totals: ['sales_transfer' => 45.5, 'refunds_transfer' => 10.0] + $this->totals(),
        );
        $lines = $report->lines(32);

        $this->assertContains('Ventas transferencia     45,50 €', $lines);
        $this->assertContains('Devol. transferencia     10,00 €', $lines);
        $this->assertLessThan(array_search('Total ventas', array_map(static fn ($l) => trim(preg_replace('/\s+[\d.,]+ €$/', '', $l)), $lines), true), array_search('Ventas transferencia     45,50 €', $lines, true));
    }

    public function testThePositiveDifferenceKeepsItsSign(): void
    {
        $report = new CashReport(
            kind: CashReport::Z, sessionId: 1, terminalId: 1, terminalName: 'Caja', terminalLetter: null, storeName: 'T',
            legalName: null, taxId: null, cashierName: 'Ana', openedAt: new \DateTimeImmutable('2026-11-05 09:00'),
            closedAt: new \DateTimeImmutable('2026-11-05 10:00'), issuedAt: new \DateTimeImmutable('2026-11-05 10:00'),
            totals: $this->totals(), countedCash: 1313.56, difference: 1.0, signatureHash: str_repeat('a', 64),
        );

        $this->assertMatchesRegularExpression('/^Diferencia +\+1,00 €$/m', implode("\n", $report->lines(32)));
    }

    public function testTheEscPosBytesCarryTheLinesInWindows1252AndCut(): void
    {
        $bytes = $this->z()->escPos(32);

        $this->assertStringStartsWith("\x1B@\x1Bt\x10", $bytes);
        $this->assertStringContainsString('INFORME Z', $bytes);
        $this->assertStringContainsString("1.234,56 \x80", $bytes, 'the euro sign in WPC1252');
        $this->assertStringContainsString('Huella: abcdef0123456789', $bytes);
        $this->assertStringEndsWith("\x1DV\x41\x03", $bytes);
    }

    public function testThePdfHasTheSameContent(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext not installed');
        }
        $pdfGenerator = new CashReportPdf(new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates')));
        $pdf = $pdfGenerator->generate($this->z());
        $this->assertStringStartsWith('%PDF', $pdf);

        $file = tempnam(sys_get_temp_dir(), 'zpdf');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' -');
        unlink($file);

        foreach (['INFORME Z', 'Mi Tienda SL', 'NIF: B12345678', 'Cajero: Ana', 'Cierre: 05/11/2026 21:15', 'Efectivo contado', '1.310,00 €', 'Diferencia', '-2,56 €', 'Huella: abcdef0123456789'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertSame('informe-z-41.pdf', $this->z()->fileName());
        $this->assertSame('informe-x-41.pdf', $this->x()->fileName());
    }
}
