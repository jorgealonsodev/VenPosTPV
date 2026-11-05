<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Controller\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\EmailSettingsController;
use VenPosTPV\Service\Mail\MailSettingsStore;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Tests\Support\TestDatabase;

/** Ajustes → Email: the backup alert address round-trips through app_setting. */
final class EmailSettingsBackupAlertMySqlIntegrationTest extends TestCase
{
    private const CLEANUP = "DELETE FROM app_setting WHERE name LIKE 'mail.%' OR name = 'backup.alert_email'";

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->connection->executeStatement(self::CLEANUP);
    }

    protected function tearDown(): void
    {
        $this->connection?->executeStatement(self::CLEANUP);
        $this->connection?->close();
    }

    private function controller(): EmailSettingsController
    {
        \assert($this->connection !== null);

        return new EmailSettingsController(new MailSettingsStore($this->connection, 'an-app-secret-of-sufficient-length-1234'), $this->createMock(TicketMailer::class));
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $role, array $body = []): Request
    {
        $request = Request::create('/api/settings/email', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', 1);
        $session->set('role', $role);
        $request->setSession($session);

        return $request;
    }

    /** @return array<string, mixed> */
    private static function data(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true)['data'];
    }

    /** @return array<string, mixed> */
    private static function account(): array
    {
        return ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u@example.com', 'from_email' => 'tienda@example.com', 'from_name' => 'Nada Más'];
    }

    public function testTheAlertAddressIsSavedReturnedKeptAndCleared(): void
    {
        \assert($this->connection !== null);
        $this->assertSame('', self::data($this->controller()->show($this->request('GET', 'admin')))['backup_alert_email']);

        $saved = $this->controller()->update($this->request('PUT', 'admin', self::account() + ['backup_alert_email' => 'admin@example.com']));
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertSame('admin@example.com', self::data($saved)['backup_alert_email']);
        $this->assertSame('admin@example.com', $this->connection->fetchOne("SELECT value FROM app_setting WHERE name = 'backup.alert_email'"));

        // An older client that does not send the field keeps it.
        $this->controller()->update($this->request('PUT', 'admin', self::account()));
        $this->assertSame('admin@example.com', self::data($this->controller()->show($this->request('GET', 'admin')))['backup_alert_email']);

        $this->controller()->update($this->request('PUT', 'admin', self::account() + ['backup_alert_email' => '']));
        $this->assertSame('', self::data($this->controller()->show($this->request('GET', 'admin')))['backup_alert_email']);
    }

    public function testOnlyAnAdminChangesItAndAnInvalidAddressIsA422(): void
    {
        \assert($this->connection !== null);
        $this->assertSame(403, $this->controller()->update($this->request('PUT', 'cashier', self::account() + ['backup_alert_email' => 'a@b.es']))->getStatusCode());
        $this->assertSame(422, $this->controller()->update($this->request('PUT', 'admin', self::account() + ['backup_alert_email' => "a@b.es\nBcc: x@y.es"]))->getStatusCode());
        $this->assertFalse($this->connection->fetchOne("SELECT value FROM app_setting WHERE name = 'backup.alert_email'"));
    }
}
