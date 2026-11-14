<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Invoice\InvoiceNumberer;
use VenPosTPV\Service\Refund\CreditNoteNumberer;
use VenPosTPV\Service\Sale\TicketNumberer;

/**
 * What the fiscal journal stores of one issued document besides its fiscal record
 * (fiscal-journal D2): its series, the counter that numbered it and the full rows
 * needed to rebuild it, with their original ids. Only committed rows are ever read
 * here.
 *
 * The rows must be those of the document as issued (T1b). The flush runs after the
 * commit, so a row that later statements change is captured in the intent inside
 * the issuing transaction (issueRows(), refundedAmountOf()) and used here instead
 * of the row read at flush time. The rows that cannot be captured that way are read
 * at flush time and named in the line's `as_of_flush`: the replay must not trust
 * them as the state at issue. By kind (CHANGED_AFTER_ISSUE):
 *
 * - captured at issue: F2 `order_meta` (printed*, ps_order_id, ps_reference and
 *   synced are set by the print and the upload, refunded_amount by later refunds);
 *   R5 `refund` (ps_order_slip_id, ps_order_id set by the upload) and
 *   `order_meta_refunded_amount` (the sale's total refunded right after this credit
 *   note, later ones add to it); the R5 movement's identity (`movement_id`);
 *   F3 `invoice` (email_consent_at).
 * - as of flush: F2 `movement` (ps_order_id set by the upload), `transfer_payment`
 *   (received later), `outbox` (upload state), `session` (closed later), `exchange`
 *   (linked to the sale by ExchangeService after the sale's commit, so a flush in
 *   between finds none); R5 `refund_line` (restocked set after the record in the
 *   same transaction and by the upload), `movement` columns (ps_order_slip_id,
 *   ps_order_id set by the upload), `outbox`, `session`, `exchange` (status and
 *   voucher fields change until the exchange ends).
 * - never changed after the commit: `sale_line`, `issued_coupon`.
 * Without its intent (a journal that could not be written while issuing, or a
 * backfill) the captured rows are read at flush time too, and listed as such.
 */
class FiscalDocumentSnapshot
{
    /** The series of a coupon sale: numbered by the shop (its order reference), no local counter. */
    public const SHOP_SERIES = 'PS';

    /** @var array<string, list<string>> kind => rows that statements after the issue may change */
    public const CHANGED_AFTER_ISSUE = [
        FiscalRecorder::SALE => ['order_meta', 'movement', 'transfer_payment', 'outbox', 'session', 'exchange'],
        FiscalRecorder::CREDIT_NOTE => ['refund', 'refund_line', 'movement', 'outbox', 'session', 'exchange', 'order_meta_refunded_amount'],
        FiscalRecorder::INVOICE_REPLACING_TICKET => ['invoice'],
    ];

    private const TICKET = '/\A([A-Z])(\d{2})(\d{6})\z/D';
    private const CREDIT_NOTE = '/\AR(\d{4})-(\d{6})\z/D';
    private const INVOICE = '/\AF(\d{2})(\d{6})\z/D';
    /** Seconds between a refund's completed_at and its cash movement's created_at (two clock reads). */
    private const MOVEMENT_WINDOW = 2;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The document of a journal line: build() with the values captured at issue in
     * place of those read now, and the list of rows that stay as of the flush.
     *
     * @param array<string, mixed> $record the committed fiscal_record row
     * @param array{rows?: array<string, mixed>, movement_id?: ?int, order_meta_refunded_amount?: ?array<string, mixed>} $captured from the intent (FiscalJournalIntents::capturedFor)
     *
     * @return array{kind: string, series: string, counter: ?array<string, mixed>, rows: array<string, mixed>, as_of_flush: list<string>}
     */
    public function document(array $record, array $captured = []): array
    {
        $document = $this->build($record, \is_int($captured['movement_id'] ?? null) ? $captured['movement_id'] : null);
        $rows = $document['rows'];
        $issued = [];
        $values = \is_array($captured['rows'] ?? null) ? $captured['rows'] : [];
        if (\array_key_exists('order_meta_refunded_amount', $captured)) {
            $values['order_meta_refunded_amount'] = $captured['order_meta_refunded_amount'];
        }
        foreach ($values as $key => $value) {
            if (\array_key_exists($key, $rows) && ($value === null || \is_array($value))) {
                $rows[$key] = $value;
                $issued[] = $key;
            }
        }
        $changing = self::CHANGED_AFTER_ISSUE[(string) $record['invoice_type']] ?? [];
        $document['rows'] = $rows;
        $document['as_of_flush'] = array_values(array_filter(
            array_keys($rows),
            static fn (string $key): bool => \in_array($key, $changing, true) && !\in_array($key, $issued, true),
        ));

        return $document;
    }

    /**
     * The rows of a document that exist when its fiscal record is appended and that
     * later statements change (the order of a sale, the refund of a credit note, the
     * invoice), read inside the issuing transaction for its intent. Never throws: a
     * row that cannot be read is simply not captured (the line says it).
     *
     * @param array<string, mixed> $record the fiscal_record row about to be inserted
     *
     * @return array<string, array<string, mixed>>
     */
    public static function issueRows(Connection $connection, array $record): array
    {
        $queries = [
            'order_meta' => ['SELECT * FROM order_meta WHERE id = ?', $record['order_id'] ?? null],
            'refund' => ['SELECT * FROM refund WHERE id = ?', $record['refund_id'] ?? null],
            'invoice' => ['SELECT * FROM invoice WHERE id = ?', $record['invoice_id'] ?? null],
        ];
        $rows = [];
        foreach ($queries as $key => [$sql, $id]) {
            if ($id === null) {
                continue;
            }
            try {
                $row = $connection->fetchAssociative($sql, [(int) $id]);
            } catch (\Throwable) {
                continue;
            }
            if ($row !== false) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * The `order_meta_refunded_amount` of a credit note (D2): the refunded amount of
     * the sale it refunds, found by the refund's order_id or else its ps_order_id.
     * Called inside the issuing transaction right after the amount is updated, and
     * at flush time when that was not captured.
     *
     * @return array{order_meta_id: mixed, value: mixed}|null null when the refund has no local sale
     */
    public static function refundedAmountOf(Connection $connection, int $refundId): ?array
    {
        $refund = $connection->fetchAssociative('SELECT order_id, ps_order_id FROM refund WHERE id = ?', [$refundId]);

        return $refund === false ? null : self::refundedAmount($connection, $refund);
    }

    /**
     * @param array<string, mixed> $refund at least order_id and ps_order_id
     *
     * @return array{order_meta_id: mixed, value: mixed}|null
     */
    private static function refundedAmount(Connection $connection, array $refund): ?array
    {
        $sale = $refund['order_id'] !== null
            ? $connection->fetchAssociative('SELECT id, refunded_amount FROM order_meta WHERE id = ?', [(int) $refund['order_id']])
            : ($refund['ps_order_id'] !== null ? $connection->fetchAssociative('SELECT id, refunded_amount FROM order_meta WHERE ps_order_id = ? ORDER BY id LIMIT 1', [(int) $refund['ps_order_id']]) : false);

        return $sale !== false ? ['order_meta_id' => $sale['id'], 'value' => $sale['refunded_amount']] : null;
    }

    /**
     * @param array<string, mixed> $record the committed fiscal_record row
     * @param int|null $refundMovementId the cash movement booked for an R5, when known
     *
     * @return array{kind: string, series: string, counter: ?array<string, mixed>, rows: array<string, mixed>}
     */
    public function build(array $record, ?int $refundMovementId = null): array
    {
        $kind = (string) $record['invoice_type'];
        $number = (string) $record['number'];

        return match ($kind) {
            FiscalRecorder::SALE => $this->sale($record, $number),
            FiscalRecorder::CREDIT_NOTE => $this->creditNote($record, $number, $refundMovementId),
            FiscalRecorder::INVOICE_REPLACING_TICKET => $this->invoice($record, $number),
            default => throw new \UnexpectedValueException(sprintf('Unknown fiscal document type %s (%s)', $kind, $number)),
        };
    }

    /** @param array<string, mixed> $record */
    private function sale(array $record, string $number): array
    {
        $orderId = $record['order_id'] !== null ? (int) $record['order_id'] : null;
        $order = $orderId !== null ? $this->row('SELECT * FROM order_meta WHERE id = ?', [$orderId]) : null;
        $coupon = preg_match(self::TICKET, $number, $parts) !== 1;

        if ($coupon) {
            $series = self::SHOP_SERIES;
            $counter = null;
        } else {
            $year = $this->year('ticket_sequence', 'letter', $parts[1], (int) $parts[2]);
            $series = TicketNumberer::series($parts[1], $year);
            $counter = ['table' => 'ticket_sequence', 'letter' => $parts[1], 'year' => $year, 'number' => (int) $parts[3]];
        }

        $rows = [
            'order_meta' => $order,
            'sale_line' => $orderId !== null ? $this->connection->fetchAllAssociative('SELECT * FROM sale_line WHERE order_id = ? ORDER BY id', [$orderId]) : [],
        ];
        if ($coupon) {
            $psOrderId = $order !== null && $order['ps_order_id'] !== null ? (int) $order['ps_order_id'] : null;
            $rows['movement'] = $psOrderId !== null
                ? $this->connection->fetchAllAssociative("SELECT * FROM movement WHERE ps_order_id = ? AND order_id IS NULL AND type <> 'refund' ORDER BY id", [$psOrderId])
                : [];
        } else {
            $rows['movement'] = $orderId !== null
                ? $this->connection->fetchAllAssociative("SELECT * FROM movement WHERE order_id = ? AND type <> 'refund' ORDER BY id", [$orderId])
                : [];
        }
        $rows['transfer_payment'] = $orderId !== null ? $this->row('SELECT * FROM transfer_payment WHERE order_id = ?', [$orderId]) : null;
        if (!$coupon) {
            $rows['outbox'] = $orderId !== null ? $this->connection->fetchAllAssociative("SELECT * FROM outbox WHERE type = 'sale' AND order_id = ? ORDER BY id", [$orderId]) : [];
        }
        $rows['session'] = $order !== null ? $this->row('SELECT * FROM session WHERE id = ?', [(int) $order['session_id']]) : null;
        $rows['exchange'] = $orderId !== null ? $this->row('SELECT * FROM exchange WHERE new_order_id = ? ORDER BY id LIMIT 1', [$orderId]) : null;
        if ($coupon) {
            $rows['issued_coupon'] = $this->leftoverCoupon($order);
        }

        return ['kind' => FiscalRecorder::SALE, 'series' => $series, 'counter' => $counter, 'rows' => $rows];
    }

    /** @param array<string, mixed> $record */
    private function creditNote(array $record, string $number, ?int $movementId): array
    {
        if (preg_match(self::CREDIT_NOTE, $number, $parts) !== 1) {
            throw new \UnexpectedValueException(sprintf('Unexpected credit note number %s', $number));
        }
        $year = (int) $parts[1];
        $refundId = $record['refund_id'] !== null ? (int) $record['refund_id'] : null;
        $refund = $refundId !== null ? $this->row('SELECT * FROM refund WHERE id = ?', [$refundId]) : null;

        $refundedAmount = $refund !== null ? self::refundedAmount($this->connection, $refund) : null;

        return [
            'kind' => FiscalRecorder::CREDIT_NOTE,
            'series' => CreditNoteNumberer::series($year),
            'counter' => ['table' => 'credit_note_sequence', 'year' => $year, 'number' => (int) $parts[2]],
            'rows' => [
                'refund' => $refund,
                'refund_line' => $refundId !== null ? $this->connection->fetchAllAssociative('SELECT * FROM refund_line WHERE refund_id = ? ORDER BY id', [$refundId]) : [],
                'movement' => $refund !== null ? $this->refundMovement($refund, $movementId) : [],
                'outbox' => $refund !== null ? $this->connection->fetchAllAssociative('SELECT * FROM outbox WHERE request_id = ? ORDER BY id', [(string) $refund['request_id']]) : [],
                'session' => $refund !== null ? $this->row('SELECT * FROM session WHERE id = ?', [(int) $refund['session_id']]) : null,
                'exchange' => $refundId !== null ? $this->row('SELECT * FROM exchange WHERE refund_id = ?', [$refundId]) : null,
                'order_meta_refunded_amount' => $refundedAmount,
            ],
        ];
    }

    /** @param array<string, mixed> $record */
    private function invoice(array $record, string $number): array
    {
        if (preg_match(self::INVOICE, $number, $parts) !== 1) {
            throw new \UnexpectedValueException(sprintf('Unexpected invoice number %s', $number));
        }
        $year = $this->year('invoice_sequence', 'series', InvoiceNumberer::SERIES, (int) $parts[1]);
        $invoiceId = isset($record['invoice_id']) ? (int) $record['invoice_id'] : null;

        return [
            'kind' => FiscalRecorder::INVOICE_REPLACING_TICKET,
            'series' => InvoiceNumberer::series($year),
            'counter' => ['table' => 'invoice_sequence', 'series' => InvoiceNumberer::SERIES, 'year' => $year, 'number' => (int) $parts[2]],
            'rows' => [
                'invoice' => $invoiceId !== null ? $this->row('SELECT * FROM invoice WHERE id = ?', [$invoiceId]) : null,
            ],
        ];
    }

    /**
     * The counter year of a number that only carries two digits of it (S11): the
     * year of the counter row of that series whose last two digits match, which is
     * the year the numberer used whatever the dates of the record say; 2000 + yy
     * when there is no single such row.
     */
    private function year(string $table, string $column, string $key, int $twoDigits): int
    {
        $years = array_map('intval', $this->connection->fetchFirstColumn(
            sprintf('SELECT year FROM %s WHERE %s = ? AND MOD(year, 100) = ?', $table, $column),
            [$key, $twoDigits]
        ));

        return \count($years) === 1 ? $years[0] : 2000 + $twoDigits;
    }

    /**
     * The refund's cash movement, verbatim. Its id is known when the refund was
     * issued with the journal running (the intent notes it); otherwise the only
     * refund movement of that session, amount and time is taken, and none when
     * several could match (never a guess).
     *
     * @param array<string, mixed> $refund
     *
     * @return list<array<string, mixed>>
     */
    private function refundMovement(array $refund, ?int $movementId): array
    {
        if ($movementId !== null) {
            $row = $this->row('SELECT * FROM movement WHERE id = ?', [$movementId]);

            return $row !== null ? [$row] : [];
        }
        if ((float) $refund['cash_amount'] <= 0 || $refund['completed_at'] === null) {
            return [];
        }
        $completedAt = new \DateTimeImmutable((string) $refund['completed_at']);
        $candidates = $this->connection->fetchAllAssociative(
            "SELECT * FROM movement WHERE session_id = ? AND type = 'refund' AND amount = ? AND created_at BETWEEN ? AND ? ORDER BY id",
            [
                (int) $refund['session_id'],
                $refund['cash_amount'],
                $completedAt->format('Y-m-d H:i:s'),
                $completedAt->modify(sprintf('+%d seconds', self::MOVEMENT_WINDOW))->format('Y-m-d H:i:s'),
            ]
        );
        if (\count($candidates) === 1) {
            return $candidates;
        }
        $this->logger?->warning('Fiscal journal: the cash movement of refund {refund} cannot be told apart ({count} candidates); its line has no movement', ['refund' => $refund['id'], 'count' => \count($candidates)]);

        return [];
    }

    /**
     * @param array<string, mixed>|null $order
     *
     * @return array<string, mixed>|null
     */
    private function leftoverCoupon(?array $order): ?array
    {
        if ($order === null) {
            return null;
        }
        $breakdown = json_decode((string) $order['payment_breakdown'], true);
        $id = \is_array($breakdown) ? ($breakdown['leftover_voucher']['issued_coupon_id'] ?? null) : null;

        return is_numeric($id) ? $this->row('SELECT * FROM issued_coupon WHERE id = ?', [(int) $id]) : null;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $parameters): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return $row === false ? null : $row;
    }
}
