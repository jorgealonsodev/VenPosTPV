<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Numbering;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Refund\CreditNoteNumberer;
use VenPosTPV\Service\Sale\TicketNumberer;

/**
 * Where each ticket series (one per till letter) and the credit-note series of the
 * year continue, so numbering can go on after losing the database or installing
 * for another shop (Ajustes → Cajas).
 *
 * RD 1619/2012 art. 7.1.a asks for correlative numbers within each series, so a
 * series only moves FORWARD: the new next number must be above the last one
 * issued. The change locks the same counter row the sale (or refund) increments,
 * so a concurrent sale is either done before (and the check sees its number) or
 * waits until the change commits. Every change is written to numbering_change in
 * the same transaction, with its reason; nothing in the app updates or deletes it.
 */
class NumberingSettings
{
    public const RANGE_MESSAGE = 'El próximo número debe estar entre 1 y 999999';
    public const REASON_MESSAGE = 'Indica el motivo del cambio';
    public const REASON_TOO_LONG_MESSAGE = 'El motivo no puede tener más de 255 caracteres';
    private const REASON_MIN = 5;
    private const REASON_MAX = 255;
    private const CHANGES_SHOWN = 20;

    private readonly TicketNumberer $tickets;
    private readonly CreditNoteNumberer $creditNotes;

    public function __construct(
        private readonly Connection $connection,
        ?TicketNumberer $tickets = null,
        ?CreditNoteNumberer $creditNotes = null,
    ) {
        $this->tickets = $tickets ?? new TicketNumberer($connection);
        $this->creditNotes = $creditNotes ?? new CreditNoteNumberer($connection);
    }

    /**
     * @return array{year: int, tills: list<array<string, mixed>>, credit_notes: array<string, mixed>, changes: list<array<string, mixed>>}
     */
    public function overview(int $shopId, \DateTimeImmutable $now): array
    {
        $year = (int) $now->format('Y');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.name, t.ticket_letter, s.last_number
             FROM terminal t LEFT JOIN ticket_sequence s ON s.letter = t.ticket_letter AND s.year = ?
             WHERE t.ps_shop_id = ? AND t.active = 1
             ORDER BY t.id',
            [$year, $shopId]
        );

        $tills = [];
        foreach ($rows as $row) {
            $letter = \is_string($row['ticket_letter']) && preg_match('/^[A-Z]$/', $row['ticket_letter']) ? $row['ticket_letter'] : null;
            $last = (int) ($row['last_number'] ?? 0);
            $tills[] = [
                'terminal_id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'letter' => $letter,
                'series' => $letter !== null ? TicketNumberer::series($letter, $year) : null,
                'last_number' => $last,
                'next_number' => $last + 1,
                'next_code' => $letter !== null && $last < TicketNumberer::MAX_NUMBER ? TicketNumberer::format($letter, $year, $last + 1) : null,
            ];
        }

        $creditLast = (int) ($this->connection->fetchOne('SELECT last_number FROM credit_note_sequence WHERE year = ?', [$year]) ?: 0);

        return [
            'year' => $year,
            'tills' => $tills,
            'credit_notes' => [
                'series' => CreditNoteNumberer::series($year),
                'last_number' => $creditLast,
                'next_number' => $creditLast + 1,
                'next_code' => CreditNoteNumberer::format($year, $creditLast + 1),
            ],
            'changes' => $this->recentChanges(),
        ];
    }

    /**
     * @return array{series: string, last_number: int, next_number: int, next_code: string}
     *
     * @throws \DomainException with the message to show when the change is refused
     */
    public function setTicketNext(int $terminalId, mixed $nextNumber, mixed $reason, ?int $userId, \DateTimeImmutable $now): array
    {
        [$next, $reason] = $this->validated($nextNumber, $reason);
        $letter = $this->tickets->letterOf($terminalId);
        $year = (int) $now->format('Y');
        // The same row creation the sale does, outside the transaction (see prepareYear).
        $this->tickets->prepareYear($terminalId, $now);

        $this->apply(
            TicketNumberer::series($letter, $year),
            'SELECT last_number FROM ticket_sequence WHERE letter = ? AND year = ? FOR UPDATE',
            'UPDATE ticket_sequence SET last_number = ? WHERE letter = ? AND year = ?',
            [$letter, $year],
            $next,
            $reason,
            $userId,
            $now
        );

        return [
            'series' => TicketNumberer::series($letter, $year),
            'last_number' => $next - 1,
            'next_number' => $next,
            'next_code' => TicketNumberer::format($letter, $year, $next),
        ];
    }

    /**
     * @return array{series: string, last_number: int, next_number: int, next_code: string}
     *
     * @throws \DomainException with the message to show when the change is refused
     */
    public function setCreditNoteNext(mixed $nextNumber, mixed $reason, ?int $userId, \DateTimeImmutable $now): array
    {
        [$next, $reason] = $this->validated($nextNumber, $reason);
        $year = (int) $now->format('Y');
        $this->creditNotes->prepareYear($now);

        $this->apply(
            CreditNoteNumberer::series($year),
            'SELECT last_number FROM credit_note_sequence WHERE year = ? FOR UPDATE',
            'UPDATE credit_note_sequence SET last_number = ? WHERE year = ?',
            [$year],
            $next,
            $reason,
            $userId,
            $now
        );

        return [
            'series' => CreditNoteNumberer::series($year),
            'last_number' => $next - 1,
            'next_number' => $next,
            'next_code' => CreditNoteNumberer::format($year, $next),
        ];
    }

    /**
     * @return list<array{id: int, series: string, previous_last: int, new_last: int, reason: string, user_id: ?int, user_name: ?string, created_at: string}>
     */
    public function recentChanges(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.series, c.previous_last, c.new_last, c.reason, c.user_id, u.name AS user_name, c.created_at
             FROM numbering_change c LEFT JOIN user u ON u.id = c.user_id
             ORDER BY c.id DESC
             LIMIT ' . self::CHANGES_SHOWN
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'series' => (string) $row['series'],
            'previous_last' => (int) $row['previous_last'],
            'new_last' => (int) $row['new_last'],
            'reason' => (string) $row['reason'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user_name' => $row['user_name'] !== null ? (string) $row['user_name'] : null,
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Locks the counter row, checks again under the lock that the series only moves
     * forward, sets last_number = next - 1 and audits the change, all in one transaction.
     *
     * @param list<int|string> $key
     */
    private function apply(string $series, string $lockSql, string $updateSql, array $key, int $next, string $reason, ?int $userId, \DateTimeImmutable $now): void
    {
        $this->connection->transactional(function () use ($series, $lockSql, $updateSql, $key, $next, $reason, $userId, $now): void {
            $last = (int) $this->connection->fetchOne($lockSql, $key);
            if ($next <= $last) {
                throw new \DomainException(sprintf('El próximo número debe ser mayor que el último emitido (%d)', $last));
            }
            $this->connection->executeStatement($updateSql, [$next - 1, ...$key]);
            $this->connection->insert('numbering_change', [
                'series' => $series,
                'previous_last' => $last,
                'new_last' => $next - 1,
                'reason' => $reason,
                'user_id' => $userId,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
        });
    }

    /**
     * @return array{int, string}
     */
    private function validated(mixed $nextNumber, mixed $reason): array
    {
        if (\is_string($nextNumber) && preg_match('/^\d{1,7}$/', trim($nextNumber))) {
            $nextNumber = (int) trim($nextNumber);
        }
        if (!\is_int($nextNumber) || $nextNumber < 1 || $nextNumber > TicketNumberer::MAX_NUMBER) {
            throw new \DomainException(self::RANGE_MESSAGE);
        }
        $reason = \is_string($reason) ? trim($reason) : '';
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw new \DomainException(self::REASON_MESSAGE);
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new \DomainException(self::REASON_TOO_LONG_MESSAGE);
        }

        return [$nextNumber, $reason];
    }
}
