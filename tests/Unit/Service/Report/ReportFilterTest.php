<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Report\InvalidReportFilter;
use VenPosTPV\Service\Report\ReportFilter;

/** docs/prd/informes-ventas.md I2 and I4: the filters of a report and their 422 errors. */
final class ReportFilterTest extends TestCase
{
    public function testTheRangeIsHalfOpenInLocalTime(): void
    {
        $filter = ReportFilter::fromQuery('summary', ['from' => '2026-03-10', 'to' => '2026-03-12', 'terminal_id' => '4', 'user_id' => '7']);

        $this->assertSame('2026-03-10 00:00:00', $filter->from()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-13 00:00:00', $filter->toExclusive()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-12', $filter->to()->format('Y-m-d'));
        $this->assertSame(3, $filter->days());
        $this->assertSame(4, $filter->terminalId);
        $this->assertSame(7, $filter->userId);
        $this->assertNull($filter->sessionId);
        $this->assertSame('json', $filter->format);
    }

    public function testThePreviousPeriodHasTheSameLengthAndEndsTheDayBefore(): void
    {
        $previous = ReportFilter::fromQuery('summary', ['from' => '2026-03-01', 'to' => '2026-03-31', 'terminal_id' => '2'])->previous();

        $this->assertSame('2026-01-29', $previous->from()->format('Y-m-d'));
        $this->assertSame('2026-02-28', $previous->to()->format('Y-m-d'));
        $this->assertSame(31, $previous->days());
        $this->assertSame(2, $previous->terminalId);
    }

    public function testExactly366DaysAreAllowed(): void
    {
        $this->assertSame(366, ReportFilter::fromQuery('daily', ['from' => '2028-01-01', 'to' => '2028-12-31'])->days());
    }

    public function testPaymentsMayBeFilteredBySessionWithoutDates(): void
    {
        $filter = ReportFilter::fromQuery('payments', ['session_id' => '12', 'format' => 'csv']);

        $this->assertSame(12, $filter->sessionId);
        $this->assertNull($filter->from());
        $this->assertSame('csv', $filter->format);
    }

    public function testProductsTakeSortGroupViewAndCursorWithTheirDefaults(): void
    {
        $default = ReportFilter::fromQuery('products', ['from' => '2026-03-10', 'to' => '2026-03-11']);
        $this->assertSame(['amount', 'combination', 'sold', 0], [$default->sort, $default->group, $default->view, $default->cursor]);

        $given = ReportFilter::fromQuery('products', ['from' => '2026-03-10', 'to' => '2026-03-11', 'sort' => 'units', 'group' => 'product', 'view' => 'unsold', 'cursor' => '50']);
        $this->assertSame(['units', 'product', 'unsold', 50], [$given->sort, $given->group, $given->view, $given->cursor]);
        // The previous period keeps the till and cashier; the page options do not matter there.
        $this->assertSame('units', $given->previous()->sort);
    }

    /** @return iterable<string, array{0: string, 1: array<string, string>, 2: string}> */
    public static function invalid(): iterable
    {
        yield 'no dates' => ['summary', [], 'Indica las fechas desde y hasta (AAAA-MM-DD)'];
        yield 'only from' => ['summary', ['from' => '2026-03-10'], 'Indica las fechas desde y hasta (AAAA-MM-DD)'];
        yield 'not a date' => ['summary', ['from' => 'ayer', 'to' => '2026-03-10'], 'Fecha no válida: usa el formato AAAA-MM-DD'];
        yield 'impossible date' => ['daily', ['from' => '2026-02-30', 'to' => '2026-03-10'], 'Fecha no válida: usa el formato AAAA-MM-DD'];
        yield 'from after to' => ['summary', ['from' => '2026-03-11', 'to' => '2026-03-10'], 'La fecha desde no puede ser posterior a la fecha hasta'];
        yield '400 days' => ['summary', ['from' => '2026-01-01', 'to' => '2027-02-04'], 'El rango máximo es de 366 días'];
        yield '367 days' => ['daily', ['from' => '2026-01-01', 'to' => '2027-01-02'], 'El rango máximo es de 366 días'];
        yield 'bad till' => ['summary', ['from' => '2026-03-10', 'to' => '2026-03-10', 'terminal_id' => 'A'], 'Filtro no válido: terminal_id'];
        yield 'bad cashier' => ['daily', ['from' => '2026-03-10', 'to' => '2026-03-10', 'user_id' => '-1'], 'Filtro no válido: user_id'];
        yield 'bad session' => ['payments', ['session_id' => 'x'], 'Filtro no válido: session_id'];
        yield 'bad format' => ['summary', ['from' => '2026-03-10', 'to' => '2026-03-10', 'format' => 'pdf'], 'Formato no válido: usa json o csv'];
        yield 'session outside payments' => ['summary', ['from' => '2026-03-10', 'to' => '2026-03-10', 'session_id' => '3'], 'Solo el informe por forma de pago se filtra por sesión de caja'];
        yield 'session and dates' => ['payments', ['session_id' => '3', 'from' => '2026-03-10', 'to' => '2026-03-10'], 'Filtra por sesión de caja o por fechas y caja, no por las dos cosas'];
        yield 'session and till' => ['payments', ['session_id' => '3', 'terminal_id' => '1'], 'Filtra por sesión de caja o por fechas y caja, no por las dos cosas'];
        yield 'bad sort' => ['products', ['from' => '2026-03-10', 'to' => '2026-03-10', 'sort' => 'name'], 'Orden no válido: usa amount o units'];
        yield 'bad group' => ['products', ['from' => '2026-03-10', 'to' => '2026-03-10', 'group' => 'category'], 'Agrupación no válida: usa combination o product'];
        yield 'bad view' => ['products', ['from' => '2026-03-10', 'to' => '2026-03-10', 'view' => 'all'], 'Vista no válida: usa sold o unsold'];
        yield 'bad cursor' => ['products', ['from' => '2026-03-10', 'to' => '2026-03-10', 'cursor' => '-50'], 'Cursor no válido'];
        yield 'sort outside products' => ['summary', ['from' => '2026-03-10', 'to' => '2026-03-10', 'sort' => 'units'], 'Solo el informe por producto admite sort'];
        yield 'cursor outside products' => ['vat', ['from' => '2026-03-10', 'to' => '2026-03-10', 'cursor' => '50'], 'Solo el informe por producto admite cursor'];
        yield 'cashier on payments' => ['payments', ['from' => '2026-03-10', 'to' => '2026-03-10', 'user_id' => '2'], 'El informe por forma de pago no se filtra por cajero'];
    }

    /** @param array<string, string> $query */
    #[DataProvider('invalid')]
    public function testInvalidFiltersAreRejectedWithTheirMessage(string $type, array $query, string $message): void
    {
        try {
            ReportFilter::fromQuery($type, $query);
            $this->fail('expected an invalid filter');
        } catch (InvalidReportFilter $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
