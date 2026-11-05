<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Mail;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Mail\MailSettings;

final class MailSettingsTest extends TestCase
{
    private function settings(array $over = []): MailSettings
    {
        return MailSettings::fromArray($over + [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'tienda@example.com', 'from_email' => 'tienda@example.com', 'from_name' => 'Nada Más',
        ], 'p@ss:w/rd');
    }

    public function testDsnFollowsTheEncryptionAndEscapesTheCredentials(): void
    {
        $this->assertSame('smtp://tienda%40example.com:p%40ss%3Aw%2Frd@smtp.example.com:587', $this->settings()->dsn());
        $this->assertSame('smtps://tienda%40example.com:p%40ss%3Aw%2Frd@smtp.example.com:465', $this->settings(['encryption' => 'ssl', 'port' => 465])->dsn());
        $this->assertSame('smtp://tienda%40example.com:p%40ss%3Aw%2Frd@smtp.example.com:25?auto_tls=false', $this->settings(['encryption' => 'none', 'port' => 25])->dsn());
    }

    public function testConfiguredNeedsAHostAPortAndAValidSender(): void
    {
        $this->assertTrue($this->settings()->isConfigured());
        $this->assertFalse($this->settings(['host' => ''])->isConfigured());
        $this->assertFalse($this->settings(['from_email' => 'no-es-un-email'])->isConfigured());
        $this->assertFalse(MailSettings::fromArray([], null)->isConfigured());
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidInput(): iterable
    {
        yield 'no host' => [['host' => ''], 'servidor'];
        yield 'host with spaces' => [['host' => 'smtp example.com'], 'servidor'];
        yield 'port 0' => [['port' => 0], 'puerto'];
        yield 'port 70000' => [['port' => 70000], 'puerto'];
        yield 'unknown encryption' => [['encryption' => 'starttls'], 'cifrado'];
        yield 'bad sender' => [['from_email' => 'x'], 'remitente'];
        yield 'line break in name' => [['from_name' => "a\r\nBcc: x@y.z"], 'nombre'];
    }

    /** @param array<string, mixed> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testValidationNamesTheWrongField(array $over, string $word): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . $word . '/i');
        MailSettings::validated($over + ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'from_email' => 'a@b.es', 'from_name' => '']);
    }

    public function testThePublicViewNeverHasThePassword(): void
    {
        $view = $this->settings()->toPublicArray();

        $this->assertTrue($view['has_password']);
        $this->assertArrayNotHasKey('password', $view);
        $this->assertStringNotContainsString('p@ss', json_encode($view, JSON_THROW_ON_ERROR));
    }
}
