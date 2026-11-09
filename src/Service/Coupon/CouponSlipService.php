<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Coupon;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;
use VenPosTPV\Service\Print\CouponSlip;
use VenPosTPV\Service\Print\CouponSlipEscPos;
use VenPosTPV\Service\Print\CouponSlipPdf;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\PrintAgent\TerminalNotResolvedException;

/**
 * The slip of a coupon created from the till: printed on the till's ticket printer
 * (its print agent, a direct printer, or the PDF when it has none), as a PDF, or
 * sent by email. Always built from issued_coupon, never from browser values.
 */
class CouponSlipService
{
    public const PRINT_SOURCE = 'coupon';

    public function __construct(
        private readonly Connection $connection,
        private readonly IssuedCouponStore $coupons,
        private readonly PrinterService $printer,
        private readonly CouponSlipEscPos $escPos,
        private readonly CouponSlipPdf $pdf,
        private readonly TicketMailer $mailer,
        #[Autowire('%env(TPV_STORE_NAME)%')] private readonly string $storeName = 'VenPosTPV',
        #[Autowire('%env(TPV_STORE_ADDRESS)%')] private readonly string $storeAddress = '',
    ) {
    }

    public function find(int $id): ?IssuedCoupon
    {
        return $this->coupons->find($id);
    }

    /**
     * The till that prints, like a ticket reprint: the session's till; without one
     * the old fallback to till 1, never for a print agent (T9).
     *
     * @throws TerminalNotResolvedException
     */
    public function printTerminal(mixed $sessionTerminal): int
    {
        $terminalId = is_numeric($sessionTerminal) && (int) $sessionTerminal > 0 ? (int) $sessionTerminal : null;
        if ($terminalId === null && $this->printer->usesAgent(null)) {
            throw new TerminalNotResolvedException();
        }

        return $terminalId ?? 1;
    }

    /**
     * @return array{print_status: string, print_job_id?: int}|array{fallback: string}
     */
    public function print(IssuedCoupon $coupon, int $terminalId, ?int $userId): array
    {
        $bytes = $this->escPos->build($this->slip($coupon, $terminalId), $this->printer->paperWidth($terminalId));

        return $this->printer->printRaw($terminalId, $bytes, self::PRINT_SOURCE, $coupon->code, $userId);
    }

    public function pdf(IssuedCoupon $coupon, ?int $terminalId): string
    {
        return $this->pdf->generate($this->slip($coupon, $terminalId ?? $coupon->terminalId ?? 1));
    }

    /** @throws TicketMailException */
    public function email(IssuedCoupon $coupon, ?int $terminalId, string $to): void
    {
        $slip = $this->slip($coupon, $terminalId ?? $coupon->terminalId ?? 1);
        $this->mailer->sendCoupon($to, $coupon->code, $slip->storeName, $this->pdf->generate($slip));
    }

    /**
     * The coupon with the store header of the till, resolved like the tickets do
     * (TicketDataResolver: settings name, then legal name, then the .env default).
     */
    public function slip(IssuedCoupon $coupon, int $terminalId): CouponSlip
    {
        $row = $this->connection->fetchAssociative(
            'SELECT store_name, store_address, legal_name, tax_id, ticket_logo FROM terminal WHERE id = ?',
            [$terminalId]
        );
        $text = static fn (string $key): ?string => \is_array($row) && \is_string($row[$key] ?? null) && trim($row[$key]) !== ''
            ? trim($row[$key])
            : null;
        try {
            // Version00000045: read apart so a slip still prints before that migration.
            $layout = $this->connection->fetchAssociative(
                'SELECT ticket_margin_left_mm, ticket_margin_right_mm, ticket_logo_width_pct FROM terminal WHERE id = ?',
                [$terminalId]
            );
        } catch (\Throwable) {
            $layout = false;
        }
        $layout = \is_array($layout) ? $layout : [];
        $number = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;

        return new CouponSlip(
            code: $coupon->code,
            discountType: $coupon->discountType,
            value: $coupon->value,
            expiresAt: $coupon->expiresAt,
            quantity: $coupon->quantity,
            quantityPerUser: $coupon->quantityPerUser,
            createdAt: $coupon->createdAt,
            storeName: $text('store_name') ?? $text('legal_name') ?? $this->storeName,
            legalName: $text('legal_name'),
            taxId: $text('tax_id'),
            storeAddress: $text('store_address') ?? trim($this->storeAddress),
            logo: $text('ticket_logo'),
            marginLeftMm: $number($layout['ticket_margin_left_mm'] ?? null),
            marginRightMm: $number($layout['ticket_margin_right_mm'] ?? null),
            logoWidthPct: is_numeric($layout['ticket_logo_width_pct'] ?? null) ? (int) $layout['ticket_logo_width_pct'] : null,
        );
    }
}
