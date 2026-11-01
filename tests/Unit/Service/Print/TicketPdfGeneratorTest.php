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
            self::assertGreaterThanOrEqual(2, $this->embeddedImageCount($pdfImages));
            self::assertSame($assetsBefore, $this->temporaryAssetPaths());
        } finally {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
            rmdir($testDir);
        }
    }

    /** @return string[] */
    private function temporaryAssetPaths(): array
    {
        $assets = array_merge(
            glob($this->projectDir() . '/var/ticket-assets/tpv_logo_*') ?: [],
            glob($this->projectDir() . '/var/ticket-assets/tpv_qr_*') ?: [],
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
}
