<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\EscPosTicketBuilder;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketLine;

final class EscPosTicketBuilderTest extends TestCase
{
    private function createBuilder(): EscPosTicketBuilder
    {
        return new EscPosTicketBuilder([
            'type' => 'network',
            'host' => '127.0.0.1',
            'port' => 9100,
            'paperWidth' => 80,
            'enabled' => true,
        ]);
    }

    private function createTicketData(): TicketData
    {
        return new TicketData(
            orderId: 42,
            reference: 'ORD-042',
            storeName: 'Mi Tienda',
            storeAddress: 'Calle Falsa 123, 28001 Madrid',
            date: new \DateTimeImmutable('2024-01-15 14:30:00'),
            cashierName: 'Juan',
            lines: [
                new TicketLine('Café Ñoño €1.50', 2, 1.5, 3.0),
                new TicketLine('Pan', 1, 0.8, 0.8),
            ],
            subtotal: 3.8,
            tax: 0.2,
            total: 4.0,
            paymentMethod: 'cash',
            amountTendered: 5.0,
            changeDue: 1.0,
            verificationUrl: 'https://example.com/verify?token=abc',
        );
    }

    public function testBuildReturnsNonEmptyByteString(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertNotEmpty($bytes);
        self::assertGreaterThan(100, \strlen($bytes));
    }

    public function testBuildContainsHeaderText(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('Mi Tienda', $bytes);
        self::assertStringContainsString('ORD-042', $bytes);
        self::assertStringContainsString('Juan', $bytes);
    }

    public function testBuildContainsSpanishCharacters(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        // In CodePage 858, é=0x82, Ñ=0xA5, ñ=0xA4, €=0xD5
        // The ASCII prefix should be present
        self::assertStringContainsString('Caf', $bytes);
        // Verify codepage selection command is present: ESC t 16
        self::assertStringContainsString("\x1b\x74\x10", $bytes);
    }

    public function testBuildContainsLineItems(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('Pan', $bytes);
        self::assertStringContainsString('TOTAL', $bytes);
    }

    public function testBuildContainsTotalsAndPayment(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('4.00', $bytes);
        self::assertStringContainsString('Efectivo', $bytes);
        self::assertStringContainsString('Cambio', $bytes);
    }

    public function testBuildContainsFooterAndCut(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData());

        self::assertStringContainsString('Gracias por su compra', $bytes);
        // ESC/POS cut command: GS V
        self::assertStringContainsString("\x1d\x56", $bytes);
    }

    public function testBuildWithGiftModeContainsItemNamesButNoPrices(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData(), true);

        self::assertStringContainsString('Pan', $bytes);
        self::assertStringContainsString('Caf', $bytes);
        // No monetary totals should appear
        self::assertStringNotContainsString('TOTAL:', $bytes);
        self::assertStringNotContainsString('Subtotal:', $bytes);
        self::assertStringNotContainsString('IVA:', $bytes);
        self::assertStringNotContainsString('Efectivo', $bytes);
        self::assertStringNotContainsString('Entrega:', $bytes);
        self::assertStringNotContainsString('Cambio:', $bytes);
    }

    public function testBuildWithGiftModeOmitsUnitPricesAndLineTotals(): void
    {
        $builder = $this->createBuilder();
        $bytes = $builder->build($this->createTicketData(), true);

        // No price symbols or formatted prices in item lines (product name may contain digits)
        self::assertStringNotContainsString(' x ', $bytes);
        self::assertStringNotContainsString(' = ', $bytes);
        self::assertStringNotContainsString('€', $bytes);
    }

    public function testBuildWithGiftModeFalseIsIdenticalToDefault(): void
    {
        $builder = $this->createBuilder();
        $bytesDefault = $builder->build($this->createTicketData());
        $bytesExplicit = $builder->build($this->createTicketData(), false);

        self::assertSame($bytesDefault, $bytesExplicit);
    }
}
