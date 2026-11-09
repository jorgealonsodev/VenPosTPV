<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Print;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Service\Print\CouponSlipPdf;

final class CouponSlipPdfTest extends TestCase
{
    private function pdf(): CouponSlipPdf
    {
        return new CouponSlipPdf(new Environment(new FilesystemLoader(__DIR__ . '/../../../../templates')));
    }

    public function testTheHtmlShowsTheSameLinesAsTheTicketSlip(): void
    {
        $html = $this->pdf()->html(CouponSlipEscPosTest::slip(quantity: 1, perUser: 1));

        self::assertStringContainsString('Nada Más', $html);
        self::assertStringContainsString('NIF: B12345678', $html);
        self::assertStringContainsString('CUPÓN DE DESCUENTO', $html);
        self::assertStringContainsString('ABCD2345', $html);
        self::assertStringContainsString('100,00 €', $html);
        self::assertStringContainsString('Válido hasta 30/06/2027', $html);
        self::assertStringContainsString('1 uso', $html);
        self::assertStringContainsString('Usos por cliente: 1', $html);
        self::assertStringContainsString('Canjeable en tienda y en la web', $html);
        if (\function_exists('imagecreatetruecolor')) {
            self::assertStringContainsString('src="data:image/png;base64,', $html, 'the Code128 of the code');
        }
    }

    public function testItRendersANonEmptyPdf(): void
    {
        $pdf = $this->pdf()->generate(CouponSlipEscPosTest::slip('percentage', 15.0));

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1000, \strlen($pdf));
    }
}
