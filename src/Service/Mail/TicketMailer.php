<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketPdfGenerator;

/**
 * Sends a sale ticket as PDF from the shop's mail account (Ajustes → Email), for
 * tills without a printer, a full invoice (A4), and the account's test email.
 */
class TicketMailer
{
    /** @var \Closure(string): TransportInterface */
    private readonly \Closure $transportFactory;

    /** @param (\Closure(string): TransportInterface)|null $transportFactory */
    public function __construct(
        private readonly MailSettingsStore $settings,
        private readonly TicketDataResolver $tickets,
        private readonly TicketPdfGenerator $pdf,
        ?\Closure $transportFactory = null,
    ) {
        $this->transportFactory = $transportFactory ?? static fn (string $dsn): TransportInterface => Transport::fromDsn($dsn);
    }

    public function isEnabled(): bool
    {
        return $this->settings->load()->isConfigured();
    }

    /** @throws TicketMailException */
    public function sendSale(int $saleId, string $to): void
    {
        $settings = $this->configuredSettings();
        $to = $this->recipient($to);
        $ticket = $saleId > 0 ? $this->tickets->resolve(TicketDataResolver::saleKey($saleId)) : null;
        if ($ticket === null) {
            throw new TicketMailException('No se encuentra la venta.', 404);
        }

        $shop = $ticket->storeName !== '' ? $ticket->storeName : $settings->fromName;
        $email = $this->message($settings, $to)
            ->subject(sprintf('Tu ticket %s%s', $ticket->reference, $shop !== '' ? ' - ' . $shop : ''))
            ->text(sprintf("Gracias por tu compra%s.\n\nTe enviamos el ticket %s en PDF.\n", $shop !== '' ? ' en ' . $shop : '', $ticket->reference))
            ->attach($this->pdf->generate($ticket), sprintf('ticket-%s.pdf', $ticket->reference), 'application/pdf');

        $this->send($settings, $email);
    }

    /**
     * Sends a full invoice (A4 PDF) to a customer who accepted receiving it by email
     * (RD 1619/2012 art. 9.2): the caller checks the stored consent.
     *
     * @throws TicketMailException
     */
    public function sendInvoice(string $to, string $number, string $shop, string $pdf, string $fileName): void
    {
        $settings = $this->configuredSettings();
        $shop = trim($shop) !== '' ? trim($shop) : $settings->fromName;
        $email = $this->message($settings, $this->recipient($to))
            ->subject(sprintf('Tu factura %s%s', $number, $shop !== '' ? ' - ' . $shop : ''))
            ->text(sprintf("Gracias por tu compra%s.\n\nTe enviamos la factura %s en PDF.\n", $shop !== '' ? ' en ' . $shop : '', $number))
            ->attach($pdf, $fileName, 'application/pdf');

        $this->send($settings, $email);
    }

    /**
     * Sends a coupon created from the till as its PDF slip (CouponSlipPdf).
     *
     * @throws TicketMailException
     */
    public function sendCoupon(string $to, string $code, string $shop, string $pdf): void
    {
        $settings = $this->configuredSettings();
        $shop = trim($shop) !== '' ? trim($shop) : $settings->fromName;
        $email = $this->message($settings, $this->recipient($to))
            ->subject(sprintf('Tu cupón de descuento %s%s', $code, $shop !== '' ? ' - ' . $shop : ''))
            ->text(sprintf("Te enviamos tu cupón de descuento %s%s en PDF.\n\nCanjéalo en la tienda o en la web con ese código.\n", $code, $shop !== '' ? ' de ' . $shop : ''))
            ->attach($pdf, sprintf('cupon-%s.pdf', $code), 'application/pdf');

        $this->send($settings, $email);
    }

    /** @throws TicketMailException */
    public function sendTest(string $to): void
    {
        $settings = $this->configuredSettings();
        $email = $this->message($settings, $this->recipient($to))
            ->subject('Prueba de email del TPV')
            ->text("La cuenta de correo del TPV funciona: así llegarán los tickets en PDF.\n");

        $this->send($settings, $email);
    }

    private function configuredSettings(): MailSettings
    {
        $settings = $this->settings->load();
        if (!$settings->isConfigured()) {
            throw new TicketMailException('El email no está configurado: Ajustes → Email.', 409);
        }

        return $settings;
    }

    private function recipient(string $to): string
    {
        $to = trim($to);
        if (preg_match('/[\x00-\x1F\x7F]/', $to) === 1 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new TicketMailException('El email del cliente no es válido.', 422);
        }

        return $to;
    }

    private function message(MailSettings $settings, string $to): Email
    {
        return (new Email())
            ->from(new Address($settings->fromEmail, $settings->fromName))
            ->to($to);
    }

    private function send(MailSettings $settings, Email $email): void
    {
        try {
            (new Mailer(($this->transportFactory)($settings->dsn())))->send($email);
        } catch (TransportExceptionInterface|\InvalidArgumentException $e) {
            throw new TicketMailException('El servidor de correo no aceptó el envío: ' . $e->getMessage(), 502, $e);
        }
    }
}
