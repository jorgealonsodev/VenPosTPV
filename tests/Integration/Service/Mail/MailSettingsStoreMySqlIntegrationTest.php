<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Mail;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Mail\MailSettings;
use VenPosTPV\Service\Mail\MailSettingsStore;
use VenPosTPV\Tests\Support\TestDatabase;

final class MailSettingsStoreMySqlIntegrationTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->connection->executeStatement("DELETE FROM app_setting WHERE name LIKE 'mail.%'");
    }

    protected function tearDown(): void
    {
        $this->connection?->executeStatement("DELETE FROM app_setting WHERE name LIKE 'mail.%'");
        $this->connection?->close();
    }

    private function store(string $secret = 'an-app-secret-of-sufficient-length-1234'): MailSettingsStore
    {
        \assert($this->connection !== null);

        return new MailSettingsStore($this->connection, $secret);
    }

    private function input(): array
    {
        return ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u@example.com', 'from_email' => 'tienda@example.com', 'from_name' => 'Nada Más'];
    }

    public function testNothingSavedIsNotConfigured(): void
    {
        $this->assertFalse($this->store()->load()->isConfigured());
    }

    public function testSavedSettingsComeBackAndThePasswordIsStoredEncrypted(): void
    {
        $this->store()->save(MailSettings::validated($this->input()), 'secreto-123');

        $loaded = $this->store()->load();
        $this->assertSame('smtp.example.com', $loaded->host);
        $this->assertSame('Nada Más', $loaded->fromName);
        $this->assertSame('secreto-123', $loaded->password());

        \assert($this->connection !== null);
        $raw = (string) $this->connection->fetchOne("SELECT value FROM app_setting WHERE name = 'mail.password'");
        $this->assertStringNotContainsString('secreto-123', $raw);
        $this->assertNotSame('', $raw);
    }

    public function testAnEmptyPasswordKeepsTheStoredOne(): void
    {
        $this->store()->save(MailSettings::validated($this->input()), 'secreto-123');
        $this->store()->save(MailSettings::validated(['host' => 'smtp2.example.com'] + $this->input()), null);

        $loaded = $this->store()->load();
        $this->assertSame('smtp2.example.com', $loaded->host);
        $this->assertSame('secreto-123', $loaded->password());
    }

    public function testAnotherAppSecretCannotReadThePassword(): void
    {
        $this->store()->save(MailSettings::validated($this->input()), 'secreto-123');

        $this->assertNull($this->store('a-different-secret-of-sufficient-length')->load()->password());
    }

    public function testSavingCreatesTheTableWhenTheMigrationHasNotRunYet(): void
    {
        \assert($this->connection !== null);
        $this->connection->executeStatement('DROP TABLE app_setting');

        $this->assertFalse($this->store()->load()->isConfigured());
        $this->store()->save(MailSettings::validated($this->input()), 'secreto-123');

        $this->assertSame('secreto-123', $this->store()->load()->password());
    }
}
