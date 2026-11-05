<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Mail;

/**
 * The shop's outgoing mail account (Ajustes → Email), used to send tickets as
 * PDF when a till has no printer. The password is never part of the public view.
 */
final class MailSettings
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $username,
        public readonly string $fromEmail,
        public readonly string $fromName,
        private readonly ?string $password,
    ) {
    }

    /** @param array<string, mixed> $stored */
    public static function fromArray(array $stored, ?string $password): self
    {
        $encryption = (string) ($stored['encryption'] ?? 'tls');

        return new self(
            trim((string) ($stored['host'] ?? '')),
            (int) ($stored['port'] ?? 587),
            \in_array($encryption, self::ENCRYPTIONS, true) ? $encryption : 'tls',
            trim((string) ($stored['username'] ?? '')),
            trim((string) ($stored['from_email'] ?? '')),
            trim((string) ($stored['from_name'] ?? '')),
            $password !== '' ? $password : null,
        );
    }

    /**
     * @param array<string, mixed> $input what the admin typed
     *
     * @throws \InvalidArgumentException naming the wrong field, in Spanish
     */
    public static function validated(array $input, ?string $password = null): self
    {
        $settings = self::fromArray($input, $password);
        if ($settings->host === '' || preg_match('/^[A-Za-z0-9.-]+$/', $settings->host) !== 1) {
            throw new \InvalidArgumentException('El servidor SMTP no es válido (por ejemplo smtp.tudominio.com).');
        }
        if ($settings->port < 1 || $settings->port > 65535) {
            throw new \InvalidArgumentException('El puerto debe estar entre 1 y 65535.');
        }
        if (!\in_array((string) ($input['encryption'] ?? 'tls'), self::ENCRYPTIONS, true)) {
            throw new \InvalidArgumentException('El cifrado debe ser TLS, SSL o ninguno.');
        }
        if (filter_var($settings->fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('El email del remitente no es válido.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $settings->fromName . $settings->username) === 1) {
            throw new \InvalidArgumentException('El nombre del remitente y el usuario no pueden tener saltos de línea.');
        }

        return $settings;
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->port > 0 && filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    /** Symfony Mailer DSN; credentials are URL-encoded. */
    public function dsn(): string
    {
        $auth = '';
        if ($this->username !== '') {
            $auth = rawurlencode($this->username) . ($this->password !== null ? ':' . rawurlencode($this->password) : '') . '@';
        }
        $scheme = $this->encryption === 'ssl' ? 'smtps' : 'smtp';

        return $scheme . '://' . $auth . $this->host . ':' . $this->port . ($this->encryption === 'none' ? '?auto_tls=false' : '');
    }

    /** @return array{host: string, port: int, encryption: string, username: string, from_email: string, from_name: string, has_password: bool, configured: bool} */
    public function toPublicArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'has_password' => $this->hasPassword(),
            'configured' => $this->isConfigured(),
        ];
    }
}
