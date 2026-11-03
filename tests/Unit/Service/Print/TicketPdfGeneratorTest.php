<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;

final class TicketPdfGeneratorTest extends TestCase
{
    private function createGenerator(): TicketPdfGenerator
    {
        $loader = new FilesystemLoader(__DIR__ . '/../../../../templates');
        $twig = new Environment($loader);

        return new TicketPdfGenerator($twig);
    }

    private function createTicketData(): TicketData
    {
        return new TicketData(
            orderId: 42,
            reference: 'ORD-042',
            storeName: 'Mi Tienda',
            storeAddress: 'Calle Falsa 123',
            ticketHeader: 'Gracias por visitarnos',
            ticketFooter: 'Vuelva pronto',
            ticketLogo: null,
            ticketWidthPx: 302,
            date: new \DateTimeImmutable('2024-01-15 14:30:00'),
            cashierName: 'Juan',
            lines: [
                new TicketLine('Café', 2, 1.5, 3.0),
            ],
            subtotal: 3.0,
            tax: 0.21,
            total: 3.21,
            paymentMethod: 'cash',
            amountTendered: 5.0,
            changeDue: 1.79,
            verificationUrl: 'https://example.com/verify?token=abc',
        );
    }

    public function testGenerateReturnsPdfMagicBytes(): void
    {
        $generator = $this->createGenerator();
        $pdf = $generator->generate($this->createTicketData());

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testGenerateReturnsNonEmptyString(): void
    {
        $generator = $this->createGenerator();
        $pdf = $generator->generate($this->createTicketData());

        self::assertGreaterThan(1000, \strlen($pdf));
    }

    public function testGenerateGiftTicketEmbedsLogoAndQrAndCleansTemporaryAssets(): void
    {
        $testDir = $this->projectDir() . '/var/php85-ticket-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($testDir, 0775, true));
        $pdfPath = $testDir . '/ticket.pdf';
        $assetsBefore = $this->temporaryAssetPaths();

        try {
            $ticket = new TicketData(
                orderId: 85,
                reference: 'PHP85-GIFT',
                storeName: 'Mi Tienda',
                storeAddress: 'Calle Falsa 123',
                ticketHeader: 'Gracias por visitarnos',
                ticketFooter: 'Vuelva pronto',
                ticketLogo: $this->createLogoDataUri(),
                ticketWidthPx: 302,
                date: new \DateTimeImmutable('2024-01-15 14:30:00'),
                cashierName: 'Juan',
                lines: [new TicketLine('Café', 2, 1.5, 3.0)],
                subtotal: 3.0,
                tax: 0.21,
                total: 3.21,
                paymentMethod: 'cash',
                amountTendered: 5.0,
                changeDue: 1.79,
                verificationUrl: 'https://example.com/tickets/PHP85-GIFT?token=qr-smoke',
            );

            $pdf = $this->createGenerator()->generate($ticket, true);
            self::assertStringStartsWith('%PDF-', $pdf);
            self::assertNotFalse(file_put_contents($pdfPath, $pdf));

            exec('pdfimages -list ' . escapeshellarg($pdfPath), $pdfImages, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $pdfImages));
            self::assertGreaterThanOrEqual(3, $this->embeddedImageCount($pdfImages), 'logo, QR and Code128 are embedded');
            self::assertSame($assetsBefore, $this->temporaryAssetPaths());
        } finally {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
            rmdir($testDir);
        }
    }

    public function testImagesAreEmbeddedEvenWhenTheAssetDirectoryIsNotWritable(): void
    {
        // The web server user may not be allowed to write var/ticket-assets.
        $assets = $this->projectDir() . '/var/ticket-assets';
        if (!is_dir($assets)) {
            mkdir($assets, 0775, true);
        }
        $mode = fileperms($assets) & 0777;
        $pdfPath = tempnam(sys_get_temp_dir(), 'tpv_pdf_');
        chmod($assets, 0555);

        try {
            $ticket = new TicketData(
                orderId: 86, reference: 'RO-ASSETS', storeName: 'Mi Tienda', storeAddress: '', ticketHeader: '', ticketFooter: '',
                ticketLogo: $this->createLogoDataUri(), ticketWidthPx: 302, date: new \DateTimeImmutable('2024-01-15 14:30:00'),
                cashierName: 'Juan', lines: [new TicketLine('Café', 1, 1.5, 1.5)], subtotal: 1.24, tax: 0.26, total: 1.5,
                paymentMethod: 'cash', amountTendered: 2.0, changeDue: 0.5, verificationUrl: 'https://example.com/v?token=t',
            );
            file_put_contents($pdfPath, $this->createGenerator()->generate($ticket));

            exec('pdfimages -list ' . escapeshellarg($pdfPath), $pdfImages, $exitCode);
            self::assertSame(0, $exitCode);
            self::assertGreaterThanOrEqual(3, $this->embeddedImageCount($pdfImages), 'logo, QR and Code128 without writing files');
        } finally {
            chmod($assets, $mode);
            @unlink($pdfPath);
        }
    }

    /** @return string[] */
    private function temporaryAssetPaths(): array
    {
        $assets = array_merge(
            glob($this->projectDir() . '/var/ticket-assets/tpv_logo_*') ?: [],
            glob($this->projectDir() . '/var/ticket-assets/tpv_qr_*') ?: [],
            glob($this->projectDir() . '/var/ticket-assets/tpv_barcode_*') ?: [],
        );
        sort($assets);

        return $assets;
    }

    private function createLogoDataUri(): string
    {
        $logo = imagecreatetruecolor(24, 12);
        self::assertNotFalse($logo);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 30, 80, 160));
        ob_start();
        imagepng($logo);
        $png = ob_get_clean();

        self::assertIsString($png);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** @param string[] $pdfImages */
    private function embeddedImageCount(array $pdfImages): int
    {
        return count(array_filter(
            $pdfImages,
            static fn (string $line): bool => preg_match('/^\s*\d+\s+\d+\s+image\s+/', $line) === 1,
        ));
    }

    private function projectDir(): string
    {
        return dirname(__DIR__, 4);
    }

    public function testGenerateWithGiftModePassesGiftModeToTwig(): void
    {
        $twig = $this->createMock(\Twig\Environment::class);
        $capturedContext = null;
        $twig->expects($this->once())
            ->method('render')
            ->with('ticket/print.html.twig', $this->anything())
            ->willReturnCallback(function (string $template, array $context) use (&$capturedContext): string {
                $capturedContext = $context;

                return '<html></html>';
            });

        $generator = new TicketPdfGenerator($twig);
        $generator->generate($this->createTicketData(), true);

        self::assertTrue($capturedContext['giftMode'] ?? false);
    }

    public function testGenerateWithoutGiftModePassesFalseByDefault(): void
    {
        $twig = $this->createMock(\Twig\Environment::class);
        $capturedContext = null;
        $twig->expects($this->once())
            ->method('render')
            ->with('ticket/print.html.twig', $this->anything())
            ->willReturnCallback(function (string $template, array $context) use (&$capturedContext): string {
                $capturedContext = $context;

                return '<html></html>';
            });

        $generator = new TicketPdfGenerator($twig);
        $generator->generate($this->createTicketData());

        self::assertFalse($capturedContext['giftMode'] ?? true);
    }

    public function testSaleTicketGetsACode128ImageOfTheReference(): void
    {
        $twig = $this->createMock(\Twig\Environment::class);
        $barcode = null;
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(function (string $template, array $context) use (&$barcode): string {
                $path = (string) ($context['barcodePath'] ?? '');
                $png = base64_decode((string) preg_replace('#^data:image/png;base64,#', '', $path), true);
                $barcode = ['path' => $path, 'size' => \is_string($png) && $png !== '' ? getimagesizefromstring($png) : false];

                return '<html></html>';
            });

        (new TicketPdfGenerator($twig))->generate($this->createTicketData(), true);

        self::assertMatchesRegularExpression('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $barcode['path'] ?? '');
        self::assertIsArray($barcode['size'], 'the barcode is an embedded PNG');
        self::assertSame(IMAGETYPE_PNG, $barcode['size'][2]);
    }

    public function testCreditNoteGetsNoCode128Image(): void
    {
        $twig = $this->createMock(\Twig\Environment::class);
        $capturedContext = null;
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(function (string $template, array $context) use (&$capturedContext): string {
                $capturedContext = $context;

                return '<html></html>';
            });
        $sale = $this->createTicketData();
        $note = new TicketData(
            orderId: 42, reference: 'R2026-000007', storeName: 'Mi Tienda', storeAddress: '', ticketHeader: '', ticketFooter: '',
            ticketLogo: null, ticketWidthPx: null, date: $sale->date, cashierName: 'Ana', lines: $sale->lines,
            subtotal: 3.0, tax: 0.21, total: 3.21, paymentMethod: 'cash', amountTendered: 0.0, changeDue: 0.0,
            verificationUrl: 'http://tpv/v',
            creditNote: new \VenPosTPV\Service\Print\CreditNoteInfo('R2026-000007', 'ORD-042', null, 'Defectuoso', 3.21, 0.0),
        );

        (new TicketPdfGenerator($twig))->generate($note);

        self::assertSame('', $capturedContext['barcodePath'] ?? null);
    }
}
