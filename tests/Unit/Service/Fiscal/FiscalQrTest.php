<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\FiscalQr;

/**
 * AEAT "Detalle de las especificaciones técnicas del código QR de la factura y de
 * la URL del servicio de cotejo" v0.5.0, section 4 (URL encoding example).
 */
final class FiscalQrTest extends TestCase
{
    public function testTheOfficialUrlEncodingExample(): void
    {
        self::assertSame(
            'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=89890001K&numserie=12345678%26G33&fecha=01-01-2024&importe=241.4',
            FiscalQr::url('89890001K', '12345678&G33', '01-01-2024', '241.4', FiscalQr::TEST_BASE)
        );
    }

    public function testProductionIsTheDefault(): void
    {
        self::assertSame(
            'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=G12345678&numserie=A26000123&fecha=05-11-2026&importe=12.10',
            FiscalQr::url('G12345678', 'A26000123', '05-11-2026', '12.10')
        );
    }

    public function testSpacesAndSlashesAreEncoded(): void
    {
        self::assertStringContainsString('numserie=R2026%2F01%20A', FiscalQr::url('G12345678', 'R2026/01 A', '05-11-2026', '-1.00'));
    }
}
