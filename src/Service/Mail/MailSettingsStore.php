<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Mail;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Reads and writes the mail account in app_setting (migration 40). The password
 * is encrypted with a key derived from APP_SECRET (AES-256-GCM, OpenSSL): the
 * database alone does not reveal it, and a new APP_SECRET simply asks for the
 * password again. Before the migration runs, nothing is configured.
 */
class MailSettingsStore
{
    private const FIELDS = ['host', 'port', 'encryption', 'username', 'from_email', 'from_name'];

    /** Same table as migration Version00000040AppSetting (which is idempotent too). */
    private const CREATE_TABLE = 'CREATE TABLE IF NOT EXISTS app_setting (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $appSecret,
    ) {
    }

    public function load(): MailSettings
    {
        try {
            $rows = $this->connection->fetchAllKeyValue("SELECT name, value FROM app_setting WHERE name LIKE 'mail.%'");
        } catch (TableNotFoundException) {
            return MailSettings::fromArray([], null);
        }
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $rows['mail.' . $field] ?? null;
        }

        return MailSettings::fromArray($values, $this->decrypt((string) ($rows['mail.password'] ?? '')));
    }

    /** A null password keeps the stored one; an empty string clears it. */
    public function save(MailSettings $settings, ?string $password): void
    {
        $values = [
            'host' => $settings->host,
            'port' => (string) $settings->port,
            'encryption' => $settings->encryption,
            'username' => $settings->username,
            'from_email' => $settings->fromEmail,
            'from_name' => $settings->fromName,
        ];
        if ($password !== null) {
            $values['password'] = $password === '' ? '' : $this->encrypt($password);
        }

        // A host where migrations are run by hand may not have the table yet.
        $this->connection->executeStatement(self::CREATE_TABLE);

        $this->connection->transactional(function () use ($values): void {
            foreach ($values as $field => $value) {
                $this->connection->executeStatement(
                    'INSERT INTO app_setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    ['mail.' . $field, $value]
                );
            }
        });
    }

    private function key(): string
    {
        return hash('sha256', 'venpostpv.mail|' . $this->appSecret, true);
    }

    private function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the mail password');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $stored): ?string
    {
        $raw = $stored !== '' ? base64_decode($stored, true) : false;
        if ($raw === false || \strlen($raw) <= 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }
}
