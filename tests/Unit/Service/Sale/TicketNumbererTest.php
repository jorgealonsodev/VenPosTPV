<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Sale;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Sale\TicketNumberer;

final class TicketNumbererTest extends TestCase
{
    public function testCodeIsLetterTwoYearDigitsAndSixPaddedDigits(): void
    {
        $this->assertSame('A26000123', TicketNumberer::format('A', 2026, 123));
        $this->assertSame('B26000001', TicketNumberer::format('B', 2026, 1));
        $this->assertSame('Z05999999', TicketNumberer::format('Z', 2105, 999999));
        $this->assertSame(9, \strlen(TicketNumberer::format('A', 2026, 1)));
    }

    public function testNumberAboveSixDigitsIsRefused(): void
    {
        $this->expectException(\OverflowException::class);
        TicketNumberer::format('A', 2026, 1000000);
    }

    public function testNumberBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TicketNumberer::format('A', 2026, 0);
    }
}
