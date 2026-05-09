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
