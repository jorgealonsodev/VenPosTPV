<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Invoice;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Customer\CustomerTaxData;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\SpanishTaxId;
use VenPosTPV\Service\Sale\VatRate;

/**
 * Issues a full invoice in exchange for a ticket already issued (canje, F3:
 * docs/prd/factura-completa.md F3-F7; RD 1619/2012 art. 15.6). The ticket and its F2
 * record stay as they are; the invoice takes the shop's next F number, keeps a copy
 * of the issuer, the customer's tax data, the lines and the VAT box, and gets its
 * own chained fiscal record, all in one transaction.
 *
 * Lock order, shared with refunds: the sale (order_meta), the invoice counter, the
 * fiscal chain. Two tills redeeming the same ticket wait on the sale; the second one
 * finds the invoice and takes no number.
 *
 * The VAT box comes from the sale's sale_line rows with VatRate::summary, the rule
 * the ticket printed, so ticket and invoice agree to the cent. Tickets without exact
 * lines (sold before the lines were stored, or with an unknown rate) and sales with
 * 0 % lines (no exemption cause is configured) are refused, as the adviser's open
 * questions P4 and P8 recommend.
 */
class InvoiceIssuer
{
    public const REDEEMED_MESSAGE = 'Este ticket ya se canjeó por la factura %s';
    public const REFUNDED_MESSAGE = 'Este ticket tiene devoluciones; no se puede canjear';
    public const NOTHING_TO_INVOICE_MESSAGE = 'No hay importe que facturar';
    public const NO_LINES_MESSAGE = 'Este ticket no tiene registrado el IVA de cada línea (es anterior a la factura completa o tiene artículos con IVA desconocido); no se puede canjear por factura';
    public const ZERO_RATE_MESSAGE = 'La venta tiene artículos sin IVA (0 %) y no hay una causa de exención configurada; no se puede facturar';
    public const VAT_MISMATCH_MESSAGE = 'No se puede deducir el IVA de esta venta';
    public const OLD_TICKET_WARNING = 'El ticket tiene más de 4 años: confirma con la gestoría que se puede canjear';

    /** F5: recomputed VAT may differ from the stored one by this much at most. */
    private const VAT_TOLERANCE = 0.02;

    private const PAYMENT_LABELS = ['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'exchange_credit' => 'Crédito de cambio'];

    private readonly CustomerDirectory $customers;
    private readonly InvoiceNumberer $numberer;
    private readonly FiscalRecorder $fiscal;
    private readonly InvoiceRepository $invoices;

    public function __construct(
        private readonly Connection $connection,
        ?CustomerDirectory $customers = null,
        ?InvoiceNumberer $numberer = null,
        ?FiscalRecorder $fiscal = null,
        ?InvoiceRepository $invoices = null,
        private readonly string $storeName = '',
        private readonly string $storeAddress = '',
        private readonly string $timeZone = FiscalRecorder::TIME_ZONE,
    ) {
        $this->customers = $customers ?? new CustomerDirectory($connection);
        $this->numberer = $numberer ?? new InvoiceNumberer($connection);
        $this->fiscal = $fiscal ?? new FiscalRecorder($connection);
        $this->invoices = $invoices ?? new InvoiceRepository($connection);
    }

    /**
     * What the invoice would be, without issuing it: the summary the cashier
     * confirms (ticket, date, amount, customer, VAT box) and its warnings.
     *
     * @return array<string, mixed>
     *
     * @throws InvoiceException when it could not be issued
     */
    public function preview(int $orderId, int $customerId, int $terminalId, \DateTimeImmutable $now): array
    {
        $draft = $this->draft($orderId, $customerId, $terminalId, $now, false);

        return [
            'sale_order_id' => $orderId,
            'replaced_reference' => $draft['replaced_reference'],
            'replaced_date' => $draft['replaced_date'],
            'issue_date' => $draft['issue_date'],
            'operation_date' => $draft['operation_date'],
            'issuer' => $draft['issuer'],
            'recipient' => $draft['recipient'],
            'lines' => $draft['lines'],
            'tax_breakdown' => $draft['tax_breakdown'],
            'payments' => $draft['payments'],
            'tax_total' => $draft['tax_total'],
            'total' => $draft['total'],
            'next_number' => $this->nextNumber($now),
            'warnings' => $draft['warnings'],
        ];
    }

    /**
     * Issues the F3 invoice of a ticket. A repeated request_id answers the invoice it
     * already created (created: false).
     *
     * @return array{invoice: array<string, mixed>, created: bool, warnings: list<string>}
     *
     * @throws InvoiceException 404, 409 (already redeemed) or 422 (cannot be invoiced); nothing stored
     */
    public function redeem(int $orderId, int $customerId, int $terminalId, ?int $userId, bool $emailConsent, ?string $requestId, \DateTimeImmutable $now): array
    {
        $requestId = $requestId !== null && trim($requestId) !== '' ? mb_substr(trim($requestId), 0, 64) : null;
        if ($requestId !== null && ($replayed = $this->replayed($requestId, $orderId)) !== null) {
            return $replayed;
        }

        // Fails early, with the same messages, before taking any lock.
        $this->draft($orderId, $customerId, $terminalId, $now, false);
        $issuedAt = $now->setTimezone(new \DateTimeZone($this->timeZone));
        $this->numberer->prepareYear($issuedAt);

        try {
            /** @var array{0: int, 1: list<string>} $issued */
            $issued = $this->connection->transactional(function () use ($orderId, $customerId, $terminalId, $userId, $emailConsent, $requestId, $now, $issuedAt): array {
                // Checked again under the sale's lock: a refund or another redeem may have won.
                $draft = $this->draft($orderId, $customerId, $terminalId, $now, true);
                $number = $this->numberer->next($issuedAt);
                $this->connection->insert('invoice', [
                    'number' => $number['number'],
                    'series' => InvoiceNumberer::SERIES,
                    'year' => $number['year'],
                    'seq' => $number['seq'],
                    'invoice_type' => FiscalRecorder::INVOICE_REPLACING_TICKET,
                    'sale_order_id' => $orderId,
                    'replaced_reference' => $draft['replaced_reference'],
                    'replaced_date' => $draft['replaced_date'],
                    'customer_id' => $draft['recipient']['customer_id'],
                    'issuer' => json_encode($draft['issuer'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'recipient' => json_encode($draft['recipient'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'line_items' => json_encode($draft['lines'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'tax_breakdown' => json_encode($draft['tax_breakdown'], JSON_THROW_ON_ERROR),
                    'payments' => json_encode($draft['payments'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'tax_total' => $draft['tax_total'],
                    'total' => $draft['total'],
                    'issue_date' => $draft['issue_date'],
                    'operation_date' => $draft['operation_date'],
                    'email_consent_at' => $emailConsent ? $now->format('Y-m-d H:i:s') : null,
                    'request_id' => $requestId,
                    'terminal_id' => $draft['issuer_terminal_id'],
                    'user_id' => $userId,
                    'created_at' => $now->format('Y-m-d H:i:s'),
                ]);
                $invoiceId = (int) $this->connection->lastInsertId();
                $this->fiscal->recordInvoice($invoiceId, FiscalRecorder::INVOICE_REPLACING_TICKET, $number['number'], $draft['issuer_terminal_id'], $draft['total'], $draft['tax_total'], $issuedAt);

                return [$invoiceId, $draft['warnings']];
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same request_id, or the same sale, won.
            if ($requestId !== null && ($replayed = $this->replayed($requestId, $orderId)) !== null) {
                return $replayed;
            }
            $existing = $this->invoices->findBySale($orderId);
            if ($existing !== null) {
                throw InvoiceException::conflict(sprintf(self::REDEEMED_MESSAGE, $existing['number']), ['invoice_number' => $existing['number'], 'invoice_id' => $existing['id']]);
            }
            throw $e;
        } catch (\OverflowException $e) {
            throw InvoiceException::invalid($e->getMessage());
        }

        $invoice = $this->invoices->find($issued[0]);
        \assert($invoice !== null);

        return ['invoice' => $invoice, 'created' => true, 'warnings' => $issued[1]];
    }

    /** The number the next invoice of the year takes (informative: another till may take it first). */
    private function nextNumber(\DateTimeImmutable $now): string
    {
        $year = (int) $now->setTimezone(new \DateTimeZone($this->timeZone))->format('Y');
        $last = (int) ($this->connection->fetchOne('SELECT last_number FROM invoice_sequence WHERE series = ? AND year = ?', [InvoiceNumberer::SERIES, $year]) ?: 0);

        return $last < InvoiceNumberer::MAX_NUMBER ? InvoiceNumberer::format($year, $last + 1) : '';
    }

    /** @return array{invoice: array<string, mixed>, created: bool, warnings: list<string>}|null */
    private function replayed(string $requestId, int $orderId): ?array
    {
        $invoice = $this->invoices->findByRequest($requestId);
        if ($invoice === null) {
            return null;
        }
        if ($invoice['sale_order_id'] !== $orderId) {
            throw InvoiceException::conflict('Esta petición ya emitió otra factura; vuelve a intentarlo');
        }

        return ['invoice' => $invoice, 'created' => false, 'warnings' => []];
    }

    /**
     * Every check and every figure of the invoice. With $lock it runs inside the
     * transaction and locks the sale first.
     *
     * @return array<string, mixed>
     */
    private function draft(int $orderId, int $customerId, int $terminalId, \DateTimeImmutable $now, bool $lock): array
    {
        $sale = $this->connection->fetchAssociative(
            'SELECT id, reference, ps_order_id, terminal_id, payment_breakdown, created_at FROM order_meta WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$orderId]
        );
        if ($sale === false) {
            throw InvoiceException::notFound('No se encuentra la venta');
        }

        $existing = $this->invoices->findBySale($orderId);
        if ($existing !== null) {
            throw InvoiceException::conflict(sprintf(self::REDEEMED_MESSAGE, $existing['number']), ['invoice_number' => $existing['number'], 'invoice_id' => $existing['id']]);
        }

        // P3: a ticket with refunds (pending or done) is not redeemed in phase 1.
        $refunds = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM refund WHERE status <> 'failed' AND (order_id = ? OR (? IS NOT NULL AND ps_order_id = ?))",
            [$orderId, $sale['ps_order_id'], $sale['ps_order_id']]
        );
        if ($refunds > 0) {
            throw InvoiceException::invalid(self::REFUNDED_MESSAGE);
        }

        $reference = (string) ($sale['reference'] ?? '');
        if (preg_match('/^[A-Z]\d{8}$/', $reference) !== 1) {
            throw InvoiceException::invalid('Esta venta no tiene número de ticket; no se puede canjear');
        }

        $breakdown = json_decode((string) $sale['payment_breakdown'], true);
        $breakdown = \is_array($breakdown) ? $breakdown : [];
        $saleTotal = round((float) ($breakdown['total'] ?? 0), 2);
        if ($saleTotal <= 0.0) {
            throw InvoiceException::invalid(self::NOTHING_TO_INVOICE_MESSAGE);
        }

        [$lines, $taxBreakdown] = $this->lines($orderId, $breakdown);
        $taxTotal = round(array_sum(array_column($taxBreakdown, 'vat')), 2);
        $total = round(array_sum(array_map(static fn (array $row): float => $row['base'] + $row['vat'], $taxBreakdown)), 2);
        if (abs($total - $saleTotal) > 0.005
            || (isset($breakdown['tax']) && abs($taxTotal - round((float) $breakdown['tax'], 2)) > self::VAT_TOLERANCE)) {
            throw InvoiceException::invalid(self::VAT_MISMATCH_MESSAGE);
        }

        $customer = $this->customers->find($customerId);
        if ($customer === null) {
            throw InvoiceException::invalid('Elige el cliente de la factura', ['missing' => ['customer']]);
        }
        $check = CustomerTaxData::invoiceCheck($customer);
        if ($check['message'] !== null) {
            throw InvoiceException::invalid($check['message'], ['missing' => $check['missing'], 'customer_id' => (int) $customer['id']]);
        }

        $issuerTerminalId = $terminalId > 0 ? $terminalId : (int) $sale['terminal_id'];
        $issuer = $this->issuer($issuerTerminalId);

        $zone = new \DateTimeZone($this->timeZone);
        $soldAt = (new \DateTimeImmutable((string) $sale['created_at']))->setTimezone($zone);
        $issuedAt = $now->setTimezone($zone);
        $warnings = [];
        if ($soldAt < $issuedAt->modify('-4 years')) {
            $warnings[] = self::OLD_TICKET_WARNING;
        }

        return [
            'replaced_reference' => $reference,
            'replaced_date' => $soldAt->format('Y-m-d'),
            'issue_date' => $issuedAt->format('Y-m-d'),
            'operation_date' => $soldAt->format('Y-m-d'),
            'issuer' => $issuer,
            'issuer_terminal_id' => $issuerTerminalId,
            'recipient' => CustomerTaxData::recipient($customer),
            'lines' => $lines,
            'tax_breakdown' => $taxBreakdown,
            'payments' => $this->payments($breakdown, $saleTotal),
            'tax_total' => $taxTotal,
            'total' => $total,
            'warnings' => $warnings,
        ];
    }

    /**
     * The invoice lines (art. 6.1.f: unit price without VAT, discount, net amount)
     * and the VAT box per rate (F5), from the sale_line rows.
     *
     * @param array<string, mixed> $breakdown
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{rate: float, base: float, vat: float}>}
     */
    private function lines(int $orderId, array $breakdown): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT line_index, reference, name, quantity, unit_price, line_discount_pct, gross_amount, paid_amount, vat_rate, approximate
             FROM sale_line WHERE order_id = ? ORDER BY line_index',
            [$orderId]
        );
        $saleLines = \is_array($breakdown['lines'] ?? null) ? \count($breakdown['lines']) : null;
        if ($rows === [] || ($saleLines !== null && \count($rows) !== $saleLines)) {
            throw InvoiceException::invalid(self::NO_LINES_MESSAGE);
        }

        $lines = [];
        $paidByRate = [];
        foreach ($rows as $row) {
            if ((int) $row['approximate'] === 1 || $row['vat_rate'] === null) {
                throw InvoiceException::invalid(self::NO_LINES_MESSAGE);
            }
            $rate = round((float) $row['vat_rate'], 2);
            if ($rate <= 0.0) {
                throw InvoiceException::invalid(self::ZERO_RATE_MESSAGE);
            }
            $divisor = 1 + $rate / 100;
            $gross = round((float) $row['gross_amount'], 2);
            $paid = round((float) $row['paid_amount'], 2);
            $lines[] = [
                'name' => (string) $row['name'],
                'reference' => $row['reference'] !== null ? (string) $row['reference'] : '',
                'quantity' => (int) $row['quantity'],
                'unit_price' => round((float) $row['unit_price'], 2),
                'unit_price_net' => round((float) $row['unit_price'] / $divisor, 2),
                'discount_pct' => round((float) ($row['line_discount_pct'] ?? 0), 2),
                'discount_net' => round(($gross - $paid) / $divisor, 2) + 0.0,
                'net_amount' => round($paid / $divisor, 2),
                'vat_rate' => $rate,
                'paid_amount' => $paid,
            ];
            $key = number_format($rate, 2, '.', '');
            $paidByRate[$key] = ($paidByRate[$key] ?? 0.0) + $paid;
        }

        return [$lines, VatRate::summary($paidByRate)];
    }

    /**
     * Payment forms (F5: an exchange credit is a payment, it does not lower the base).
     *
     * @param array<string, mixed> $breakdown
     *
     * @return list<array{method: string, label: string, amount: float}>
     */
    private function payments(array $breakdown, float $total): array
    {
        $payments = [];
        $tender = \is_array($breakdown['tender'] ?? null) ? $breakdown['tender'] : [];
        foreach (self::PAYMENT_LABELS as $method => $label) {
            $amount = round((float) ($tender[$method] ?? 0), 2);
            if ($amount > 0) {
                $payments[] = ['method' => $method, 'label' => $label, 'amount' => $amount];
            }
        }
        if ($payments === []) {
            $method = (string) ($breakdown['payment_method'] ?? 'cash');
            $payments[] = ['method' => $method, 'label' => self::PAYMENT_LABELS[$method] ?? ucfirst($method), 'amount' => $total];
        }

        return $payments;
    }

    /**
     * The shop as issuer (art. 6.1.c-e): the till's legal name, NIF and address, as
     * the ticket shows them (Ajustes → Tienda), with the same .env fallbacks.
     *
     * @return array{name: string, tax_id: string, address: string}
     */
    private function issuer(int $terminalId): array
    {
        $row = $this->connection->fetchAssociative('SELECT store_name, store_address, legal_name, tax_id FROM terminal WHERE id = ?', [$terminalId]);
        $text = static fn (string $key): ?string => \is_array($row) && \is_string($row[$key] ?? null) && trim($row[$key]) !== '' ? trim($row[$key]) : null;
        $name = $text('legal_name') ?? $text('store_name') ?? (trim($this->storeName) !== '' ? trim($this->storeName) : null);
        $address = $text('store_address') ?? (trim($this->storeAddress) !== '' ? trim($this->storeAddress) : null);
        $taxId = SpanishTaxId::normalize($text('tax_id') ?? '');

        $missing = [];
        if ($name === null) {
            $missing[] = 'razón social';
        }
        if ($taxId === '' || !SpanishTaxId::isValid($taxId)) {
            $missing[] = 'NIF';
        }
        if ($address === null) {
            $missing[] = 'domicilio';
        }
        if ($missing !== []) {
            throw InvoiceException::invalid('Faltan datos de la tienda para facturar (Ajustes → Tienda): ' . implode(', ', $missing));
        }

        return ['name' => (string) $name, 'tax_id' => $taxId, 'address' => (string) $address];
    }
}
