<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Iban;

/** The IBAN of Ajustes → Tienda, printed on tickets paid by transfer. */
final class IbanTest extends TestCase
{
    public function testAValidSpanishIbanIsAcceptedWithOrWithoutSpaces(): void
    {
        $this->assertTrue(Iban::isValid('ES91 2100 0418 4502 0005 1332'));
        $this->assertTrue(Iban::isValid('es9121000418450200051332'));
        $this->assertSame('ES9121000418450200051332', Iban::normalize(' es91-2100.0418 4502 0005 1332 '));
    }

    public function testAWrongControlDigitOrLengthIsRejected(): void
    {
        $this->assertFalse(Iban::isValid('ES92 2100 0418 4502 0005 1332'), 'control digits');
        $this->assertFalse(Iban::isValid('ES91 2100 0418 4502 0005 133'), 'a Spanish IBAN has 24 characters');
        $this->assertFalse(Iban::isValid('2100 0418 4502 0005 1332'), 'no country code');
        $this->assertFalse(Iban::isValid(''));
    }

    public function testAnotherCountryIsCheckedTheSameWay(): void
    {
        $this->assertTrue(Iban::isValid('DE89 3704 0044 0532 0130 00'));
    }

    public function testItIsShownInGroupsOfFour(): void
    {
        $this->assertSame('ES91 2100 0418 4502 0005 1332', Iban::format('ES9121000418450200051332'));
    }
}
