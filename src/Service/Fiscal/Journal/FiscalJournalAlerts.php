<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Psr\Log\LoggerInterface;
use VenPosTPV\Service\Mail\BackupAlertMailer;

/**
 * The fiscal journal's alert e-mail (fiscal-journal S3), sent through the backup
 * alert path (Ajustes → Email, «Avisar de copias de seguridad fallidas a:»): once
 * per incident, again after 24 hours while it lasts, and forgotten when it ends,
 * so the next incident is announced again. What was sent is remembered in the
 * database (app_setting «fiscal_journal.alert»), not in the journal folders, which
 * may be exactly what cannot be written. The same rule decides when the job reports
 * an incident as a failure (app_setting «fiscal_journal.incident»).
 */
class FiscalJournalAlerts
{
    public const SETTING = 'fiscal_journal.alert';
    /** The incident last reported as a failure of the job (exit code, STDERR). */
    public const INCIDENT_SETTING = 'fiscal_journal.incident';
    public const REPEAT_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?BackupAlertMailer $mailer = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Called on every run with the current incident (null when there is none):
     * sends the e-mail when the incident is new or was last announced 24 hours ago.
     *
     * @param string|null $incident what is wrong, as a stable key (the same key = the same incident)
     *
     * @return array{incident: string, sent_at: string}|null what is remembered as sent
     */
    public function notify(?string $incident, string $subject, string $text, \DateTimeImmutable $now): ?array
    {
        return $this->track(self::SETTING, $incident, $now, function () use ($subject, $text): bool {
            if ($this->mailer === null) {
                return false;
            }
            try {
                // false: no alert address set, nothing sent and nothing remembered.
                return $this->mailer->send($subject, $text);
            } catch (\Throwable $e) {
                $this->logger?->warning('Fiscal journal alert e-mail not sent: {message}', ['message' => $e->getMessage()]);

                return false;
            }
        });
    }

    /**
     * Whether this run reports the incident as a failure of the job (non-zero exit,
     * one STDERR line): on the run that first detects it and once a day while it
     * lasts, so the hosting panel, which mails STDERR, does not mail every minute.
     */
    public function report(?string $incident, \DateTimeImmutable $now): bool
    {
        $before = $this->read(self::INCIDENT_SETTING);
        $after = $this->track(self::INCIDENT_SETTING, $incident, $now, static fn (): bool => true);

        return $after !== null && $after !== $before;
    }

    /** @return array{incident: string, sent_at: string}|null */
    public function read(string $setting = self::SETTING): ?array
    {
        try {
            $json = $this->connection->fetchOne('SELECT value FROM app_setting WHERE name = ?', [$setting]);
        } catch (\Throwable) {
            return null;
        }
        $data = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($data) || !\is_string($data['incident'] ?? null) || !\is_string($data['sent_at'] ?? null)) {
            return null;
        }

        return ['incident' => $data['incident'], 'sent_at' => $data['sent_at']];
    }

    /**
     * @param callable(): bool $send announces the incident; false when nothing went out
     *
     * @return array{incident: string, sent_at: string}|null
     */
    private function track(string $setting, ?string $incident, \DateTimeImmutable $now, callable $send): ?array
    {
        $sent = $this->read($setting);
        if ($incident === null) {
            if ($sent !== null) {
                $this->write($setting, null);
            }

            return null;
        }
        if ($sent !== null && $sent['incident'] === $incident) {
            $at = self::date($sent['sent_at']);
            if ($at !== null && $at <= $now && $now->getTimestamp() - $at->getTimestamp() < self::REPEAT_AFTER_SECONDS) {
                return $sent;
            }
        }
        if (!$send()) {
            return $sent;
        }
        $sent = ['incident' => $incident, 'sent_at' => $now->format(\DATE_ATOM)];
        $this->write($setting, $sent);

        return $sent;
    }

    /** @param array{incident: string, sent_at: string}|null $sent */
    private function write(string $setting, ?array $sent): void
    {
        try {
            // Portable upsert (the unit tests run on SQLite), as CronStateStore.
            $this->connection->transactional(function (Connection $conn) use ($setting, $sent): void {
                $conn->executeStatement('DELETE FROM app_setting WHERE name = ?', [$setting]);
                if ($sent !== null) {
                    $conn->executeStatement('INSERT INTO app_setting (name, value) VALUES (?, ?)', [$setting, json_encode($sent, \JSON_THROW_ON_ERROR)]);
                }
            });
        } catch (TableNotFoundException) {
        } catch (\Throwable $e) {
            $this->logger?->warning('Fiscal journal alert state not saved: {message}', ['message' => $e->getMessage()]);
        }
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
