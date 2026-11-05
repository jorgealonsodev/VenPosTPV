<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Report;

/**
 * The filters of a sales report (docs/prd/informes-ventas.md I2, I4): a date range
 * `from` ≤ date < `to` + 1 day in the shop's local time, at most 366 days, and the
 * till, the cashier or (only «Por forma de pago») a cash session.
 */
final class ReportFilter
{
    public const MAX_DAYS = 366;
    public const FORMATS = ['json', 'csv'];
    public const SORTS = ['amount', 'units'];
    public const GROUPS = ['combination', 'product'];
    public const VIEWS = ['sold', 'unsold'];

    /** The options only «Por producto» takes. */
    private const PRODUCT_OPTIONS = ['sort', 'group', 'view', 'cursor'];

    private function __construct(
        private readonly ?\DateTimeImmutable $from,
        private readonly ?\DateTimeImmutable $to,
        public readonly ?int $terminalId,
        public readonly ?int $userId,
        public readonly ?int $sessionId,
        public readonly string $format,
        public readonly string $sort = 'amount',
        public readonly string $group = 'combination',
        public readonly string $view = 'sold',
        public readonly int $cursor = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $query the request query string
     *
     * @throws InvalidReportFilter
     */
    public static function fromQuery(string $type, array $query): self
    {
        foreach (['from', 'to', 'terminal_id', 'user_id', 'session_id', 'format', ...self::PRODUCT_OPTIONS] as $key) {
            if (isset($query[$key]) && !\is_scalar($query[$key])) {
                throw new InvalidReportFilter('Filtro no válido: ' . $key);
            }
        }
        foreach (self::PRODUCT_OPTIONS as $key) {
            if ($type !== 'products' && self::given($query, $key)) {
                throw new InvalidReportFilter('Solo el informe por producto admite ' . $key);
            }
        }
        $sort = self::option($query, 'sort', self::SORTS, 'Orden no válido: usa amount o units');
        $group = self::option($query, 'group', self::GROUPS, 'Agrupación no válida: usa combination o product');
        $view = self::option($query, 'view', self::VIEWS, 'Vista no válida: usa sold o unsold');
        $cursor = 0;
        if (self::given($query, 'cursor')) {
            $value = (string) $query['cursor'];
            if (!ctype_digit($value) || \strlen($value) > 9) {
                throw new InvalidReportFilter('Cursor no válido');
            }
            $cursor = (int) $value;
        }
        $format = (string) ($query['format'] ?? 'json');
        if (!\in_array($format, self::FORMATS, true)) {
            throw new InvalidReportFilter('Formato no válido: usa json o csv');
        }
        $terminalId = self::id($query, 'terminal_id');
        $userId = self::id($query, 'user_id');
        $sessionId = self::id($query, 'session_id');
        $hasDates = self::given($query, 'from') || self::given($query, 'to');

        if ($sessionId !== null) {
            if ($type !== 'payments') {
                throw new InvalidReportFilter('Solo el informe por forma de pago se filtra por sesión de caja');
            }
            if ($hasDates || $terminalId !== null) {
                throw new InvalidReportFilter('Filtra por sesión de caja o por fechas y caja, no por las dos cosas');
            }
        }
        if ($userId !== null && $type === 'payments') {
            throw new InvalidReportFilter('El informe por forma de pago no se filtra por cajero');
        }
        if ($sessionId !== null) {
            return new self(null, null, null, null, $sessionId, $format);
        }

        if (!self::given($query, 'from') || !self::given($query, 'to')) {
            throw new InvalidReportFilter('Indica las fechas desde y hasta (AAAA-MM-DD)');
        }
        $from = self::date((string) $query['from']);
        $to = self::date((string) $query['to']);
        if ($from > $to) {
            throw new InvalidReportFilter('La fecha desde no puede ser posterior a la fecha hasta');
        }
        $filter = new self($from, $to, $terminalId, $userId, null, $format, $sort, $group, $view, $cursor);
        if ($filter->days() > self::MAX_DAYS) {
            throw new InvalidReportFilter('El rango máximo es de 366 días');
        }

        return $filter;
    }

    /** The first day at 00:00:00; null when filtered by cash session. */
    public function from(): ?\DateTimeImmutable
    {
        return $this->from;
    }

    /** The last day (inclusive) at 00:00:00; null when filtered by cash session. */
    public function to(): ?\DateTimeImmutable
    {
        return $this->to;
    }

    /** The first moment after the range: the day after `to` at 00:00:00. */
    public function toExclusive(): ?\DateTimeImmutable
    {
        return $this->to?->modify('+1 day');
    }

    public function days(): int
    {
        if ($this->from === null || $this->to === null) {
            return 0;
        }

        return (int) $this->from->diff($this->to)->days + 1;
    }

    /** The period of the same length that ends the day before this one starts. */
    public function previous(): self
    {
        if ($this->from === null) {
            throw new \LogicException('A cash session has no previous period');
        }
        $days = $this->days();

        return new self($this->from->modify(sprintf('-%d days', $days)), $this->from->modify('-1 day'), $this->terminalId, $this->userId, null, $this->format,
            $this->sort, $this->group, $this->view, $this->cursor);
    }

    /** @return array{type: string, from: ?string, to: ?string, terminal_id: ?int, user_id: ?int, session_id: ?int} */
    public function describe(string $type): array
    {
        return [
            'type' => $type,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'terminal_id' => $this->terminalId,
            'user_id' => $this->userId,
            'session_id' => $this->sessionId,
        ];
    }

    /** @param array<string, mixed> $query */
    private static function given(array $query, string $key): bool
    {
        return isset($query[$key]) && (string) $query[$key] !== '';
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $allowed the first one is the default
     */
    private static function option(array $query, string $key, array $allowed, string $message): string
    {
        if (!self::given($query, $key)) {
            return $allowed[0];
        }
        $value = (string) $query[$key];
        if (!\in_array($value, $allowed, true)) {
            throw new InvalidReportFilter($message);
        }

        return $value;
    }

    /** @param array<string, mixed> $query */
    private static function id(array $query, string $key): ?int
    {
        if (!self::given($query, $key)) {
            return null;
        }
        $value = (string) $query[$key];
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidReportFilter('Filtro no válido: ' . $key);
        }

        return (int) $value;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidReportFilter('Fecha no válida: usa el formato AAAA-MM-DD');
        }

        return $date;
    }
}
