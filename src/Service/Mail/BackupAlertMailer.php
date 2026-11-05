<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the failed-backup alert of tpv:backup:database from the shop's mail account
 * (Ajustes → Email) to the address set in «Avisar de copias de seguridad fallidas a:».
 */
class BackupAlertMailer
{
    /** @var \Closure(string): TransportInterface */
    private readonly \Closure $transportFactory;

    /** @param (\Closure(string): TransportInterface)|null $transportFactory */
    public function __construct(
        private readonly MailSettingsStore $settings,
        ?\Closure $transportFactory = null,
    ) {
        $this->transportFactory = $transportFactory ?? static fn (string $dsn): TransportInterface => Transport::fromDsn($dsn);
    }

    /**
     * @return bool false when no alert address is set (nothing is sent)
     *
     * @throws TicketMailException when the account is not configured or the server refuses
     */
    public function send(string $subject, string $text): bool
    {
        $to = $this->settings->backupAlertEmail();
        if ($to === '') {
            return false;
        }
        $settings = $this->settings->load();
        if (!$settings->isConfigured()) {
            throw new TicketMailException('El email no está configurado: Ajustes → Email.', 409);
        }

        $email = (new Email())
            ->from(new Address($settings->fromEmail, $settings->fromName))
            ->to($to)
            ->subject($subject)
            ->text($text);
        try {
            (new Mailer(($this->transportFactory)($settings->dsn())))->send($email);
        } catch (TransportExceptionInterface|\InvalidArgumentException $e) {
            throw new TicketMailException('El servidor de correo no aceptó el envío: ' . $e->getMessage(), 502, $e);
        }

        return true;
    }
}
