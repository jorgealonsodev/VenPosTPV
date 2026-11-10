<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use VenPosTPV\Service\Fiscal\Iban;
use VenPosTPV\Service\Transfer\TransferPayments;
use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Sale\VatRate;
use Doctrine\DBAL\Exception\TableNotFoundException;
use VenPosTPV\Service\Fiscal\FiscalQr;
use VenPosTPV\Service\Fiscal\VerifactuSettings;

class TicketDataResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TicketVerificationService $verificationService,
        private readonly string $storeName,
        private readonly string $storeAddress,
        private readonly ?VerifactuSettings $verifactu = null,
    ) {
    }

    /**
     * Print key of a sale by its local id (order_meta.id): the negative id, so it can
     * never be mistaken for a PrestaShop order id and still travels through the
     * existing integer print path (PrintTicketMessage, PrinterService) before the
     * sale has a PrestaShop order.
     */
    public static function saleKey(int $orderMetaId): int
    {
        if ($orderMetaId < 1) {
            throw new \InvalidArgumentException('A local sale id is positive');
        }

        return -$orderMetaId;
    }

    /**
     * @param int $orderId a PrestaShop order id (positive) or a saleKey() (negative)
     */
    public function resolve(int $orderId): ?TicketData
    {
        $row = $orderId < 0
            ? $this->connection->fetchAssociative(
                'SELECT id, ps_order_id, terminal_id, user_id, payment_breakdown, created_at FROM order_meta WHERE id = ?',
                [-$orderId]
            )
            : $this->connection->fetchAssociative(
                'SELECT id, ps_order_id, terminal_id, user_id, payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?',
                [$orderId]
            );

        if ($row === false) {
            return null;
        }
        // The PrestaShop order id once uploaded; the token and exchange lookups use it.
        $psOrderId = isset($row['ps_order_id']) ? (int) $row['ps_order_id'] : ($orderId > 0 ? $orderId : null);

        $breakdown = json_decode((string) $row['payment_breakdown'], true);
        if (!\is_array($breakdown)) {
            $breakdown = [];
        }

        // Each line's own discount comes from its sale_line row (Version00000046).
        $discountPcts = $this->lineDiscounts((int) $row['id']);
        $lines = [];
        $netSum = 0.0;
        foreach (array_values($breakdown['lines'] ?? []) as $index => $line) {
            $lineTotal = isset($line['line_total']) ? (float) $line['line_total'] : 0.0;
            $pct = $discountPcts[$index] ?? 0.0;
            $lineDiscount = $pct > 0 ? round($lineTotal * $pct / 100, 2) : 0.0;
            $netSum += $lineTotal - $lineDiscount;
            $lines[] = new TicketLine(
                name: isset($line['name']) && \is_string($line['name']) ? $line['name'] : 'Producto',
                quantity: isset($line['qty']) && \is_int($line['qty']) ? $line['qty'] : (isset($line['quantity']) ? (int) $line['quantity'] : 1),
                unitPrice: isset($line['price']) ? (float) $line['price'] : 0.0,
                lineTotal: $lineTotal,
                discountPct: $pct,
                discountAmount: $lineDiscount,
            );
        }

        $reference = isset($breakdown['reference']) && \is_string($breakdown['reference']) ? $breakdown['reference'] : '';
        $total = isset($breakdown['total']) ? (float) $breakdown['total'] : 0.0;
        $amountTendered = isset($breakdown['amount_tendered']) ? (float) $breakdown['amount_tendered'] : 0.0;
        $changeDue = isset($breakdown['change_due']) ? (float) $breakdown['change_due'] : 0.0;
        $subtotal = isset($breakdown['subtotal']) ? (float) $breakdown['subtotal'] : $total;
        $tax = isset($breakdown['tax']) ? (float) $breakdown['tax'] : 0.0;
        // The ticket-wide discount (manual or coupon): what the lines, after their own
        // discounts, add up to above the total. The exchange credit is a tender, not a discount.
        $ticketDiscount = $lines !== [] ? round($netSum - $total, 2) : 0.0;
        $ticketDiscount = $ticketDiscount >= 0.01 ? $ticketDiscount : 0.0;
        // The VAT box only when it tells exactly the VAT the sale stored (and chained in
        // its fiscal record): a reprint never changes the amounts of the original ticket.
        $vatBreakdown = $this->vatBreakdown($orderId);
        if ($vatBreakdown !== [] && abs(round(array_sum(array_column($vatBreakdown, 'vat')), 2) - $tax) >= 0.005) {
            $vatBreakdown = [];
        }
        // Part of the total paid with the goods returned in an exchange.
        $exchangeCredit = \is_array($breakdown['tender'] ?? null) && is_numeric($breakdown['tender']['exchange_credit'] ?? null)
            && (float) $breakdown['tender']['exchange_credit'] > 0
            ? round((float) $breakdown['tender']['exchange_credit'], 2)
            : null;

        $token = $this->verificationService->generateToken([
            'order_id' => $psOrderId,
            'reference' => $reference,
            'total' => $total,
        ]);

        $cashierName = $this->cashierName((int) $row['user_id']);
        [$storeAddress, $ticketHeader, $ticketFooter, $ticketLogo, $ticketWidthPx, $legalName, $taxId, $storeName] = $this->terminalPresentation((int) $row['terminal_id']);
        [$marginLeftMm, $marginRightMm, $logoWidthPct] = $this->ticketLayout((int) $row['terminal_id']);

        return new TicketData(
            orderId: $psOrderId ?? $orderId,
            reference: $reference,
            storeName: $storeName,
            storeAddress: $storeAddress,
            ticketHeader: $ticketHeader,
            ticketFooter: $ticketFooter,
            ticketLogo: $ticketLogo,
            ticketWidthPx: $ticketWidthPx,
            date: new \DateTimeImmutable((string) $row['created_at']),
            cashierName: $cashierName,
            lines: $lines,
            subtotal: $subtotal,
            tax: $tax,
            total: $total,
            paymentMethod: isset($breakdown['payment_method']) && \is_string($breakdown['payment_method']) ? $breakdown['payment_method'] : '',
            amountTendered: $amountTendered,
            changeDue: $changeDue,
            verificationUrl: $this->verificationService->buildVerificationUrl($token),
            ticketCode: $this->ticketCode((int) $row['terminal_id']),
            exchangeCredit: $exchangeCredit,
            exchangeCreditNote: $exchangeCredit !== null ? $this->exchangeCreditNote($psOrderId, $orderId < 0 ? -$orderId : null) : null,
            legalName: $legalName,
            taxId: $taxId,
            webOrderReference: $this->webOrderReference($orderId, $reference),
            fiscalQrUrl: $this->fiscalQrUrl('order_id', $orderId < 0 ? -$orderId : null, $orderId > 0 ? $orderId : null),
            ticketMarginLeftMm: $marginLeftMm,
            ticketMarginRightMm: $marginRightMm,
            ticketLogoWidthPct: $logoWidthPct,
            vatBreakdown: $vatBreakdown,
            replacedByInvoice: $this->replacingInvoice($orderId),
            leftoverVoucher: self::leftoverVoucher($breakdown),
            transfer: ($breakdown['payment_method'] ?? null) === 'transfer' ? $this->transferInfo((int) $row['id'], (int) $row['terminal_id']) : null,
            ticketDiscount: $ticketDiscount,
            ticketDiscountLabel: self::ticketDiscountLabel($breakdown),
        );
    }

    /**
     * «Cupón», «Dto. ticket (5 %)» for a percentage, «Dto. ticket» for an amount (or a
     * sale stored before the discount's type was kept).
     *
     * @param array<string, mixed> $breakdown
     */
    private static function ticketDiscountLabel(array $breakdown): string
    {
        if (\is_array($breakdown['coupon'] ?? null)) {
            return 'Cupón';
        }
        $discount = $breakdown['ticket_discount'] ?? null;
        if (\is_array($discount) && ($discount['type'] ?? null) === 'pct' && is_numeric($discount['value'] ?? null)) {
            return 'Dto. ticket (' . TicketData::formatRate((float) $discount['value']) . ')';
        }

        return 'Dto. ticket';
    }

    /**
     * The discount % of each line of a sale, by line index; empty before
     * Version00000046 or for a sale without stored lines.
     *
     * @return array<int, float>
     */
    private function lineDiscounts(int $localOrderId): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative('SELECT line_index, line_discount_pct FROM sale_line WHERE order_id = ?', [$localOrderId]);
        } catch (\Throwable) {
            return [];
        }
        $pcts = [];
        foreach ($rows as $r) {
            if (is_numeric($r['line_index'] ?? null) && is_numeric($r['line_discount_pct'] ?? null) && (float) $r['line_discount_pct'] > 0) {
                $pcts[(int) $r['line_index']] = (float) $r['line_discount_pct'];
            }
        }

        return $pcts;
    }

    /**
     * A sale paid by bank transfer: the shop's IBAN to pay into (Ajustes → Tienda) and
     * whether the money is still «Pendiente de pago» (docs/pago-transferencia.md).
     *
     * @return array{account: ?string, pending: bool}
     */
    private function transferInfo(int $localOrderId, int $terminalId): array
    {
        try {
            $iban = $this->connection->fetchOne('SELECT bank_account FROM terminal WHERE id = ?', [$terminalId]);
        } catch (\Throwable) {
            $iban = null; // before Version00000052
        }

        return [
            'account' => \is_string($iban) && $iban !== '' ? Iban::format($iban) : null,
            'pending' => (new TransferPayments($this->connection))->isPending($localOrderId),
        ];
    }

    /**
     * The voucher rest a coupon sale left (payment_breakdown.leftover_voucher), as
     * the ticket prints it; null when the sale left none.
     *
     * @param array<string, mixed> $breakdown
     *
     * @return array{code: string, amount: float}|null
     */
    private static function leftoverVoucher(array $breakdown): ?array
    {
        $leftover = $breakdown['leftover_voucher'] ?? null;
        if (!\is_array($leftover) || !\is_string($leftover['code'] ?? null) || trim($leftover['code']) === ''
            || !is_numeric($leftover['amount'] ?? null) || (float) $leftover['amount'] <= 0) {
            return null;
        }

        return ['code' => trim($leftover['code']), 'amount' => round((float) $leftover['amount'], 2)];
    }

    /**
     * Number of the full invoice (F3) that replaced the sale's ticket, if any.
     * $orderId is the resolve() key: -order_meta.id, or the PrestaShop order id.
     */
    private function replacingInvoice(int $orderId): ?string
    {
        try {
            $number = $orderId < 0
                ? $this->connection->fetchOne(
                    "SELECT number FROM invoice WHERE sale_order_id = ? AND invoice_type = 'F3' ORDER BY id LIMIT 1",
                    [-$orderId]
                )
                : $this->connection->fetchOne(
                    "SELECT i.number FROM invoice i JOIN order_meta o ON o.id = i.sale_order_id
                     WHERE o.ps_order_id = ? AND i.invoice_type = 'F3' ORDER BY i.id LIMIT 1",
                    [$orderId]
                );
        } catch (TableNotFoundException) {
            return null;
        }

        return \is_string($number) && $number !== '' ? $number : null;
    }

    /**
     * The VAT box of a sale from its sale_line rows: what was paid at each rate,
     * rounded once per rate. Empty for a sale without stored lines or with a line
     * backfilled from the current catalog (approximate): its rates are a guess.
     * $orderId is the resolve() key: -order_meta.id, or the PrestaShop order id.
     *
     * @return list<array{rate: float, base: float, vat: float}>
     */
    private function vatBreakdown(int $orderId): array
    {
        try {
            $rows = $orderId < 0
                ? $this->connection->fetchAllAssociative(
                    'SELECT vat_rate, SUM(paid_amount) AS paid, MAX(approximate) AS approximate FROM sale_line WHERE order_id = ? GROUP BY vat_rate',
                    [-$orderId]
                )
                : $this->connection->fetchAllAssociative(
                    'SELECT s.vat_rate, SUM(s.paid_amount) AS paid, MAX(s.approximate) AS approximate FROM sale_line s
                     JOIN order_meta o ON o.id = s.order_id WHERE o.ps_order_id = ? GROUP BY s.vat_rate',
                    [$orderId]
                );
        } catch (TableNotFoundException) {
            // Code deployed before Version00000046: the ticket keeps its «IVA:» line.
            return [];
        }
        $paidByRate = [];
        foreach ($rows as $row) {
            if ((int) ($row['approximate'] ?? 0) !== 0 || !is_numeric($row['vat_rate'] ?? null) || !is_numeric($row['paid'] ?? null)) {
                return [];
            }
            $paidByRate[number_format((float) $row['vat_rate'], 2, '.', '')] = (float) $row['paid'];
        }

        return VatRate::summary($paidByRate);
    }

    /**
     * PRD S5: the reference PrestaShop gave the order when it is not the ticket code
     * (OutboxSync keeps it in ps_reference only then); null otherwise.
     */
    private function webOrderReference(int $orderId, string $reference): ?string
    {
        $stored = $orderId < 0
            ? $this->connection->fetchOne('SELECT ps_reference FROM order_meta WHERE id = ?', [-$orderId])
            : $this->connection->fetchOne('SELECT ps_reference FROM order_meta WHERE ps_order_id = ?', [$orderId]);
        if (!\is_string($stored) || trim($stored) === '' || trim($stored) === $reference) {
            return null;
        }

        return trim($stored);
    }

    /**
     * Number of the credit note whose credit paid this exchange sale, null when
     * unknown: by the new sale's local id (V32) or, for older exchanges, its
     * PrestaShop id.
     */
    private function exchangeCreditNote(?int $psOrderId, ?int $saleId): ?string
    {
        $number = false;
        if ($saleId === null && $psOrderId !== null) {
            $id = $this->connection->fetchOne('SELECT id FROM order_meta WHERE ps_order_id = ?', [$psOrderId]);
            $saleId = is_numeric($id) ? (int) $id : null;
        }
        if ($saleId !== null) {
            $number = $this->connection->fetchOne(
                'SELECT r.credit_note_number FROM exchange e JOIN refund r ON r.id = e.refund_id WHERE e.new_order_id = ? ORDER BY e.id DESC LIMIT 1',
                [$saleId]
            );
        }
        if (($number === false || $number === null) && $psOrderId !== null) {
            $number = $this->connection->fetchOne(
                'SELECT r.credit_note_number FROM exchange e JOIN refund r ON r.id = e.refund_id WHERE e.new_ps_order_id = ? ORDER BY e.id DESC LIMIT 1',
                [$psOrderId]
            );
        }

        return \is_string($number) && $number !== '' ? $number : null;
    }

    private const REASON_LABELS = [
        'size' => 'Talla o color',
        'defective' => 'Defectuoso',
        'dislike' => 'No le gusta',
        'billing_error' => 'Error de cobro',
        'not_as_described' => 'No coincide con la web',
        'gift_duplicate' => 'Regalo / duplicado',
        'late' => 'Llegó tarde',
        'other' => 'Otro',
    ];

    /**
     * Credit note (abono) of a completed refund: the refunded lines and amounts,
     * the ticket it rectifies and how the money went back. VAT is the sale's VAT
     * share applied to the refunded total. A refund without a ticket rectifies
     * "Sin ticket", names its lines from the catalog and takes the VAT from the
     * current catalog prices.
     */
    public function resolveRefund(int $refundId): ?TicketData
    {
        // SELECT *: the online-order columns (V27) may not exist on an older database.
        $refund = $this->connection->fetchAssociative("SELECT * FROM refund WHERE id = ? AND status = 'done'", [$refundId]);
        if ($refund === false) {
            return null;
        }

        // The local sale (V32) also when it is not uploaded yet: no PrestaShop id then.
        $saleId = isset($refund['order_id']) ? (int) $refund['order_id'] : null;
        $withoutTicket = $refund['ps_order_id'] === null && $saleId === null;
        // An online order has no order_meta: its reference, date and line names were
        // stored with the refund, and the VAT comes from the catalog like without ticket.
        $online = !empty($refund['online']);
        $order = $withoutTicket || $online ? false : ($saleId !== null
            ? $this->connection->fetchAssociative('SELECT payment_breakdown, created_at FROM order_meta WHERE id = ?', [$saleId])
            : $this->connection->fetchAssociative('SELECT payment_breakdown, created_at FROM order_meta WHERE ps_order_id = ?', [(int) $refund['ps_order_id']]));
        $breakdown = $order !== false ? json_decode((string) $order['payment_breakdown'], true) : null;
        $breakdown = \is_array($breakdown) ? $breakdown : [];
        $saleLines = \is_array($breakdown['lines'] ?? null) ? array_values($breakdown['lines']) : [];

        $lines = [];
        $catalogTax = 0.0;
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM refund_line WHERE refund_id = ? ORDER BY id', [$refundId]) as $line) {
            $quantity = max(1, (int) $line['quantity']);
            $amount = round((float) $line['amount'], 2);
            if ($withoutTicket || $online) {
                [$name, $taxShare] = $this->catalogLine((int) $line['product_id'], (int) $line['id_product_attribute']);
                $catalogTax += $amount * $taxShare;
                if ($online && isset($line['name']) && \is_string($line['name']) && $line['name'] !== '') {
                    $name = $line['name'];
                }
            } else {
                $saleLine = $line['line_index'] !== null ? ($saleLines[(int) $line['line_index']] ?? []) : [];
                $name = isset($saleLine['name']) && \is_string($saleLine['name']) ? $saleLine['name'] : 'Producto';
            }
            $lines[] = new TicketLine(
                name: $name,
                quantity: $quantity,
                unitPrice: round($amount / $quantity, 2),
                lineTotal: $amount,
            );
        }

        $total = round((float) $refund['total'], 2);
        $saleTotal = (float) ($breakdown['total'] ?? 0);
        $saleTax = (float) ($breakdown['tax'] ?? 0);
        $tax = $withoutTicket || $online
            ? round($catalogTax, 2)
            : ($saleTotal > 0 ? round($total * $saleTax / $saleTotal, 2) : 0.0);
        $reference = isset($breakdown['reference']) && \is_string($breakdown['reference']) ? $breakdown['reference'] : '';
        if ($online) {
            $reference = (string) ($refund['source_reference'] ?? '');
        }
        $number = (string) $refund['credit_note_number'];

        $reasonCode = (string) $refund['reason_code'];
        $reason = self::REASON_LABELS[$reasonCode] ?? $reasonCode;
        $reasonText = trim((string) ($refund['reason_text'] ?? ''));
        if ($reasonCode === 'other' && $reasonText !== '') {
            $reason .= ': ' . $reasonText;
        }

        $token = $this->verificationService->generateToken([
            'order_id' => (int) $refund['ps_order_id'],
            'reference' => $withoutTicket ? $number : $reference,
            'total' => $saleTotal,
        ]);
        [$storeAddress, $ticketHeader, $ticketFooter, $ticketLogo, $ticketWidthPx, $legalName, $taxId, $storeName] = $this->terminalPresentation((int) $refund['terminal_id']);
        [$marginLeftMm, $marginRightMm, $logoWidthPct] = $this->ticketLayout((int) $refund['terminal_id']);

        return new TicketData(
            orderId: (int) $refund['ps_order_id'],
            reference: $number,
            storeName: $storeName,
            storeAddress: $storeAddress,
            ticketHeader: $ticketHeader,
            ticketFooter: $ticketFooter,
            ticketLogo: $ticketLogo,
            ticketWidthPx: $ticketWidthPx,
            date: new \DateTimeImmutable((string) ($refund['completed_at'] ?? $refund['created_at'])),
            cashierName: $this->cashierName((int) $refund['user_id']),
            lines: $lines,
            subtotal: round($total - $tax, 2),
            tax: $tax,
            total: $total,
            paymentMethod: $withoutTicket ? $this->noTicketMethod($refund) : ($online ? 'online' : (string) ($breakdown['payment_method'] ?? '')),
            amountTendered: 0.0,
            changeDue: 0.0,
            verificationUrl: $this->verificationService->buildVerificationUrl($token),
            ticketCode: $this->ticketCode((int) $refund['terminal_id']),
            creditNote: new CreditNoteInfo(
                number: $number,
                rectifiesReference: $withoutTicket ? 'Sin ticket' : ($online ? trim('Pedido online ' . $reference) : $reference),
                rectifiesDate: $order !== false
                    ? new \DateTimeImmutable((string) $order['created_at'])
                    : ($online && !empty($refund['source_date']) ? new \DateTimeImmutable((string) $refund['source_date']) : null),
                reason: $reason,
                cashAmount: round((float) $refund['cash_amount'], 2),
                cardAmount: round((float) $refund['card_amount'], 2),
                voucherCode: isset($refund['voucher_code']) && $refund['voucher_code'] !== '' ? (string) $refund['voucher_code'] : null,
                voucherAmount: $refund['voucher_amount'] !== null ? round((float) $refund['voucher_amount'], 2) : null,
                exchangeCredit: $refund['exchange_credit'] !== null ? round((float) $refund['exchange_credit'], 2) : null,
                webRefundAmount: $online && isset($refund['web_refund_amount']) ? round((float) $refund['web_refund_amount'], 2) : null,
                transferAmount: round((float) ($refund['transfer_amount'] ?? 0), 2),
            ),
            legalName: $legalName,
            taxId: $taxId,
            fiscalQrUrl: $this->fiscalQrUrl('refund_id', $refundId),
            ticketMarginLeftMm: $marginLeftMm,
            ticketMarginRightMm: $marginRightMm,
            ticketLogoWidthPct: $logoWidthPct,
        );
    }

    /** @param array<string, mixed> $refund */
    private function noTicketMethod(array $refund): string
    {
        if ($refund['voucher_amount'] !== null) {
            return 'voucher';
        }

        return (float) $refund['card_amount'] > 0 ? 'card' : 'cash';
    }

    /**
     * Name of a refunded product as the catalog shows it ("Camisa (Talla: M)") and
     * the VAT share of its current tax-included price (0 when the catalog lacks it).
     *
     * @return array{0: string, 1: float}
     */
    private function catalogLine(int $productId, int $attributeId): array
    {
        $product = $this->connection->fetchAssociative('SELECT name, price_ttc, price_net FROM catalog_product WHERE id = ?', [$productId]);
        $name = $product !== false && \is_string($product['name']) && $product['name'] !== '' ? $product['name'] : 'Producto';
        $ttc = $product !== false ? $product['price_ttc'] : null;
        $net = $product !== false ? $product['price_net'] : null;

        if ($attributeId > 0) {
            $attributes = $this->connection->fetchAllAssociative(
                'SELECT group_name, attribute_name FROM catalog_combination_attribute WHERE combination_id = ? ORDER BY group_name, attribute_name',
                [$attributeId]
            );
            if ($attributes !== []) {
                $name .= ' (' . implode(' / ', array_map(static fn (array $a): string => $a['group_name'] . ': ' . $a['attribute_name'], $attributes)) . ')';
            }
            $combination = $this->connection->fetchAssociative('SELECT price_ttc, price_net FROM catalog_combination WHERE id = ? AND id_product = ?', [$attributeId, $productId]);
            if ($combination !== false && is_numeric($combination['price_ttc']) && is_numeric($combination['price_net'])) {
                [$ttc, $net] = [$combination['price_ttc'], $combination['price_net']];
            }
        }

        $taxShare = is_numeric($ttc) && is_numeric($net) && (float) $ttc > 0 ? max(0.0, 1 - (float) $net / (float) $ttc) : 0.0;

        return [$name, $taxShare];
    }

    /**
     * VERI*FACTU on: the cotejo URL of the ticket's fiscal record. Null when the
     * switch is off, or when the record does not exist (a ticket issued before the
     * records, or on a database without them).
     */
    private function fiscalQrUrl(string $column, ?int $id, ?int $psOrderId = null): ?string
    {
        if ($this->verifactu === null || !$this->verifactu->isEnabled()) {
            return null;
        }
        try {
            if ($id === null && $psOrderId !== null) {
                $found = $this->connection->fetchOne('SELECT id FROM order_meta WHERE ps_order_id = ?', [$psOrderId]);
                $id = $found !== false ? (int) $found : null;
            }
            if ($id === null) {
                return null;
            }
            $record = $this->connection->fetchAssociative(
                'SELECT issuer_nif, number, issue_date, total FROM fiscal_record WHERE ' . ($column === 'refund_id' ? 'refund_id' : 'order_id') . ' = ?',
                [$id]
            );
        } catch (TableNotFoundException) {
            return null;
        }

        // Without the issuer's NIF the AEAT cannot match the QR: print none.
        return $record !== false && trim((string) $record['issuer_nif']) !== '' ? FiscalQr::forRecord($record) : null;
    }

    /** The terminal's choice of scan code; Code128 by default (1D scanners cannot read QR). */
    private function ticketCode(int $terminalId): string
    {
        $mode = $this->connection->fetchOne('SELECT ticket_code FROM terminal WHERE id = ?', [$terminalId]);

        return \in_array($mode, ['barcode', 'qr', 'both'], true) ? $mode : 'barcode';
    }

    private function cashierName(int $userId): string
    {
        return CashierName::of($this->connection, $userId);
    }

    /**
     * Margins and logo width of the till's ticket (Ajustes → Cajas, Version00000045).
     * Read apart, and unset when the query fails, so a ticket still prints before
     * that migration runs.
     *
     * @return array{0: ?float, 1: ?float, 2: ?int} left margin, right margin, logo width
     */
    private function ticketLayout(int $terminalId): array
    {
        try {
            $layout = $this->connection->fetchAssociative(
                'SELECT ticket_margin_left_mm, ticket_margin_right_mm, ticket_logo_width_pct FROM terminal WHERE id = ?',
                [$terminalId]
            );
        } catch (\Throwable) {
            $layout = false;
        }
        $layout = \is_array($layout) ? $layout : [];
        $number = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;

        return [
            $number($layout['ticket_margin_left_mm'] ?? null),
            $number($layout['ticket_margin_right_mm'] ?? null),
            is_numeric($layout['ticket_logo_width_pct'] ?? null) ? (int) $layout['ticket_logo_width_pct'] : null,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: ?string, 4: ?int, 5: ?string, 6: ?string, 7: string} address, header, footer, logo, width, legal name, NIF, store name
     */
    private function terminalPresentation(int $terminalId): array
    {
        $terminalRow = $this->connection->fetchAssociative(
            'SELECT store_name, store_address, legal_name, tax_id, ticket_header, ticket_footer, ticket_logo, ticket_width_px FROM terminal WHERE id = ?',
            [$terminalId]
        );

        $storeAddress = $this->storeAddress;
        if ($terminalRow !== false && isset($terminalRow['store_address']) && \is_string($terminalRow['store_address'])) {
            $candidate = trim($terminalRow['store_address']);
            if ($candidate !== '') {
                $storeAddress = $candidate;
            }
        }

        $ticketHeader = $terminalRow !== false && isset($terminalRow['ticket_header']) && \is_string($terminalRow['ticket_header'])
            ? trim($terminalRow['ticket_header'])
            : '';
        $ticketFooter = $terminalRow !== false && isset($terminalRow['ticket_footer']) && \is_string($terminalRow['ticket_footer'])
            ? trim($terminalRow['ticket_footer'])
            : '';
        $ticketLogo = $terminalRow !== false && isset($terminalRow['ticket_logo']) && \is_string($terminalRow['ticket_logo'])
            ? trim($terminalRow['ticket_logo'])
            : null;
        $ticketWidthPx = $terminalRow !== false && isset($terminalRow['ticket_width_px'])
            ? (int) $terminalRow['ticket_width_px']
            : null;

        $text = static fn (string $key): ?string => $terminalRow !== false && \is_string($terminalRow[$key] ?? null) && trim($terminalRow[$key]) !== ''
            ? trim($terminalRow[$key])
            : null;

        // Settings name first, then the legal name; the .env default is the last resort.
        $storeName = $text('store_name') ?? $text('legal_name') ?? $this->storeName;

        return [$storeAddress, $ticketHeader, $ticketFooter, $ticketLogo, $ticketWidthPx, $text('legal_name'), $text('tax_id'), $storeName];
    }
}
