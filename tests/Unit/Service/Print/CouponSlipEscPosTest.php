<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Print\CouponSlip;
use VenPosTPV\Service\Print\CouponSlipEscPos;

final class CouponSlipEscPosTest extends TestCase
{
    public static function slip(string $type = 'amount', float $value = 100.0, int $quantity = 1, int $perUser = 1, ?float $marginLeftMm = null): CouponSlip
    {
        return new CouponSlip(
            code: 'ABCD2345',
            discountType: $type,
            value: $value,
            expiresAt: new \DateTimeImmutable('2027-06-30'),
            quantity: $quantity,
            quantityPerUser: $perUser,
            createdAt: new \DateTimeImmutable('2026-11-09 10:15:00'),
            storeName: 'Nada Más',
            legalName: 'Nada Más SL',
            taxId: 'B12345678',
            marginLeftMm: $marginLeftMm,
        );
    }

    public function testTheSlipCarriesTheStoreTheCodeTheValueTheExpiryAndTheUses(): void
    {
        $bytes = (new CouponSlipEscPos())->build(self::slip(quantity: 3, perUser: 1));

        self::assertStringContainsString('Nada M', $bytes);
        self::assertStringContainsString('NIF: B12345678', $bytes);
        self::assertStringContainsString('DE DESCUENTO', $bytes);
        self::assertStringContainsString('ABCD2345', $bytes);
        self::assertStringContainsString("100,00 \x80", $bytes, 'the euro sign in code page 1252');
        self::assertStringContainsString('lido hasta 30/06/2027', $bytes);
        self::assertStringContainsString('3 usos', $bytes);
        self::assertStringContainsString('Usos por cliente: 1', $bytes);
        self::assertStringContainsString('09/11/2026 10:15', $bytes);
        self::assertStringContainsString('Canjeable en tienda y en la web', $bytes);
        self::assertStringEndsWith("\x1DVA\x03", $bytes, 'the slip is cut');
    }

    public function testAPercentageCouponPrintsItsPercentAndASingleUse(): void
    {
        $bytes = (new CouponSlipEscPos())->build(self::slip('percentage', 10.0));

        self::assertStringContainsString('10 %', $bytes);
        self::assertStringContainsString('1 uso', $bytes);
        self::assertStringNotContainsString('1 usos', $bytes);
        self::assertStringNotContainsString("\x80", $bytes);
    }

    public function testTheCodeIsPrintedAsACode128BarcodeTheScannerCanType(): void
    {
        $bytes = (new CouponSlipEscPos())->build(self::slip());

        // GS k 73 (CODE128), length, then code set B and the code.
        self::assertStringContainsString("\x1Dk\x49\x0A{BABCD2345", $bytes);
    }

    public function testTheSlipUsesTheTillPaperWidthAndMargins(): void
    {
        // GS L (left margin) and GS W (print area) in dots, little endian.
        $wide = (new CouponSlipEscPos())->build(self::slip());
        self::assertStringContainsString("\x1DL\x10\x00", $wide);
        self::assertStringContainsString("\x1DW" . pack('v', 576 - 32), $wide);

        $narrow = (new CouponSlipEscPos())->build(self::slip(marginLeftMm: 5.0), 58);
        self::assertStringContainsString("\x1DL" . pack('v', 40), $narrow);
        self::assertStringContainsString("\x1DW" . pack('v', 384 - 40 - 16), $narrow);
    }

    public function testValueLabelsKeepDecimalsOnlyWhenNeeded(): void
    {
        self::assertSame('100,00 €', self::slip('amount', 100.0)->valueLabel());
        self::assertSame('1.250,50 €', self::slip('amount', 1250.5)->valueLabel());
        self::assertSame('10 %', self::slip('percentage', 10.0)->valueLabel());
        self::assertSame('12,5 %', self::slip('percentage', 12.5)->valueLabel());
    }
}
