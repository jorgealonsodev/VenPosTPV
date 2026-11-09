<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Share;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Coupon\CouponSlipService;
use VenPosTPV\Service\Coupon\IssuedCoupon;
use VenPosTPV\Service\Coupon\IssuedCouponStore;
use VenPosTPV\Service\Invoice\InvoicePdfGenerator;
use VenPosTPV\Service\Invoice\InvoiceRepository;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Print\CouponSlipEscPos;
use VenPosTPV\Service\Print\CouponSlipPdf;
use VenPosTPV\Service\Print\PrinterService;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;
use VenPosTPV\Service\Share\DocumentShareService;
use VenPosTPV\Service\Share\ShareLinkSigner;

/**
 * The share service over fakes: one sale (local id 42), one invoice (id 8) and one
 * coupon (id 5), each with its own fake PDF, built by the real PDF code paths.
 */
final class ShareFixture
{
    public const SECRET = 'test-secret-of-the-installation-0123456789abcdef';
    public const SALE = 42;
    public const INVOICE = 8;
    public const COUPON = 5;

    /** @var list<array{0: string, 1: int}> */
    public array $resolved = [];

    public readonly ShareLinkSigner $signer;
    public readonly InvoiceRepository $invoices;
    public readonly CouponSlipService $slips;
    public readonly DocumentShareService $service;
    /** @var array<string, mixed>|null */
    public ?array $invoice;

    public function __construct(TestCase $test, ?int $now = null, bool $consent = false)
    {
        $make = (new \ReflectionMethod($test, 'createMock'))->getClosure($test);
        $this->signer = new ShareLinkSigner(self::SECRET, 'https://tpv.example.com', $now === null ? null : static fn (): int => $now);

        $tickets = $make(TicketDataResolver::class);
        $tickets->method('resolve')->willReturnCallback(fn (int $key): ?TicketData => $key === TicketDataResolver::saleKey(self::SALE) ? self::ticket() : null);
        $ticketPdf = $make(TicketPdfGenerator::class);
        $ticketPdf->method('generate')->willReturnCallback(static fn (TicketData $t, bool $gift = false): string => '%PDF-ticket-' . $t->reference);

        $this->invoice = [
            'id' => self::INVOICE, 'number' => 'F26000004', 'issuer' => ['name' => 'Nada Más'],
            'email_consent_at' => $consent ? '2026-11-09 10:00:00' : null,
        ];
        $this->invoices = $make(InvoiceRepository::class);
        $this->invoices->method('find')->willReturnCallback(fn (int $id): ?array => $id === self::INVOICE ? $this->invoice : null);
        $invoicePdf = $make(InvoicePdfGenerator::class);
        $invoicePdf->method('generate')->willReturnCallback(static fn (array $i): string => '%PDF-invoice-' . $i['number']);

        $connection = $make(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['store_name' => 'Nada Más', 'legal_name' => null, 'tax_id' => null, 'store_address' => '', 'ticket_logo' => null]);
        $store = $make(IssuedCouponStore::class);
        $store->method('find')->willReturnCallback(static fn (int $id): ?IssuedCoupon => $id === self::COUPON ? self::coupon() : null);
        $slipPdf = $make(CouponSlipPdf::class);
        $slipPdf->method('generate')->willReturnCallback(static fn ($slip): string => '%PDF-coupon-' . $slip->code);
        $this->slips = new CouponSlipService($connection, $store, $make(PrinterService::class), new CouponSlipEscPos(), $slipPdf, $make(TicketMailer::class));

        $this->service = new DocumentShareService($this->signer, $tickets, $ticketPdf, $this->invoices, $invoicePdf, $this->slips);
    }

    public static function ticket(): TicketData
    {
        return new TicketData(
            orderId: 42, reference: 'R26000012', storeName: 'Nada Más', storeAddress: '', ticketHeader: '', ticketFooter: '',
            ticketLogo: null, ticketWidthPx: 302, date: new \DateTimeImmutable('2026-11-09 10:00:00'), cashierName: 'Ana',
            lines: [new TicketLine('Gorro', 1, 8.0, 8.0)], subtotal: 6.61, tax: 1.39, total: 8.0,
            paymentMethod: 'card', amountTendered: 8.0, changeDue: 0.0, verificationUrl: 'https://tpv.example.com/v',
        );
    }

    public static function coupon(): IssuedCoupon
    {
        return new IssuedCoupon(self::COUPON, 42, 'ABCD2345', 'amount', 10.0, new \DateTimeImmutable('2027-06-30'), 1, 1, 3, 9, new \DateTimeImmutable('2026-11-09 10:15:00'));
    }
}
