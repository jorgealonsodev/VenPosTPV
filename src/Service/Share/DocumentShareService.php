<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Share;

use VenPosTPV\Service\Coupon\CouponSlipService;
use VenPosTPV\Service\Coupon\IssuedCoupon;
use VenPosTPV\Service\Invoice\InvoicePdfGenerator;
use VenPosTPV\Service\Invoice\InvoiceRepository;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;

/**
 * «WhatsApp» next to «Enviar por email»: a signed, expiring link to a document's
 * PDF plus the short message the cashier sends from WhatsApp, and the PDF behind
 * that link. The PDFs come from the same generators as the downloads and emails.
 */
class DocumentShareService
{
    public function __construct(
        private readonly ShareLinkSigner $signer,
        private readonly TicketDataResolver $tickets,
        private readonly TicketPdfGenerator $ticketPdf,
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePdfGenerator $invoicePdf,
        private readonly CouponSlipService $slips,
    ) {
    }

    /**
     * A sale's ticket by its local id (order_meta.id); null when there is no such sale.
     *
     * @return array{url: string, message: string}|null
     */
    public function ticket(int $saleId): ?array
    {
        $ticket = $saleId > 0 ? $this->tickets->resolve(TicketDataResolver::saleKey($saleId)) : null;
        if ($ticket === null) {
            return null;
        }

        return $this->share('ticket', $saleId, 'tu ticket ' . $ticket->reference, $ticket->storeName);
    }

    /**
     * A full invoice: the caller checks the customer's consent first (RD 1619/2012 art. 9.2).
     *
     * @param array<string, mixed> $invoice InvoiceRepository::view()
     *
     * @return array{url: string, message: string}
     */
    public function invoice(array $invoice): array
    {
        $issuer = \is_array($invoice['issuer'] ?? null) ? $invoice['issuer'] : [];

        return $this->share('invoice', (int) $invoice['id'], 'tu factura ' . $invoice['number'], (string) ($issuer['name'] ?? ''));
    }

    /** @return array{url: string, message: string} */
    public function coupon(IssuedCoupon $coupon): array
    {
        $slip = $this->slips->slip($coupon, $coupon->terminalId ?? 1);

        return $this->share('coupon', $coupon->id, 'tu cupón de descuento ' . $coupon->code, $slip->storeName);
    }

    /**
     * The PDF a link opens, or null for a bad or expired token or a document that
     * no longer exists.
     *
     * @return array{pdf: string, fileName: string}|null
     */
    public function download(string $token): ?array
    {
        $document = $this->signer->verify($token);
        if ($document === null) {
            return null;
        }
        $id = $document['id'];

        switch ($document['type']) {
            case 'ticket':
                $ticket = $this->tickets->resolve(TicketDataResolver::saleKey($id));

                return $ticket === null ? null : ['pdf' => $this->ticketPdf->generate($ticket), 'fileName' => 'ticket-' . self::safe($ticket->reference) . '.pdf'];
            case 'invoice':
                $invoice = $this->invoices->find($id);

                return $invoice === null ? null : ['pdf' => $this->invoicePdf->generate($invoice), 'fileName' => InvoicePdfGenerator::fileName($invoice)];
            case 'coupon':
                $coupon = $this->slips->find($id);

                return $coupon === null ? null : ['pdf' => $this->slips->pdf($coupon, null), 'fileName' => 'cupon-' . self::safe($coupon->code) . '.pdf'];
        }

        return null;
    }

    /** @return array{url: string, message: string} */
    private function share(string $type, int $id, string $what, string $shop): array
    {
        $url = $this->signer->url($type, $id);
        $shop = trim($shop);

        return ['url' => $url, 'message' => sprintf('Hola, aquí tienes %s%s: %s', $what, $shop !== '' ? ' de ' . $shop : '', $url)];
    }

    private static function safe(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9-]/', '', $name);
    }
}
