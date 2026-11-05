<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use VenPosTPV\Service\Mail\BackupAlertMailer;
use VenPosTPV\Service\Mail\MailSettings;
use VenPosTPV\Service\Mail\MailSettingsStore;
use VenPosTPV\Service\Mail\TicketMailException;

final class BackupAlertMailerTest extends TestCase
{
    /** @var list<Email> */
    public array $sent = [];

    private function mailer(string $alertTo, bool $configured = true, bool $failSend = false): BackupAlertMailer
    {
        $store = $this->createMock(MailSettingsStore::class);
        $store->method('backupAlertEmail')->willReturn($alertTo);
        $store->method('load')->willReturn(MailSettings::fromArray($configured ? [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'from_email' => 'tienda@example.com', 'from_name' => 'Nada Más',
        ] : [], 'pw'));
        $test = $this;
        $factory = static fn (string $dsn): AbstractTransport => new class ($test, $failSend) extends AbstractTransport {
            public function __construct(private readonly BackupAlertMailerTest $test, private readonly bool $fail)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                if ($this->fail) {
                    throw new TransportException('Connection refused');
                }
                $original = $message->getOriginalMessage();
                \assert($original instanceof Email);
                $this->test->sent[] = $original;
            }

            public function __toString(): string
            {
                return 'test://';
            }
        };

        return new BackupAlertMailer($store, $factory);
    }

    public function testTheAlertIsSentFromTheShopAccountToTheAlertAddress(): void
    {
        $this->assertTrue($this->mailer('admin@example.com')->send('VenPosTPV: copia de seguridad fallida', "Error: disco lleno\n"));

        $this->assertCount(1, $this->sent);
        $email = $this->sent[0];
        $this->assertSame('admin@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('tienda@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('VenPosTPV: copia de seguridad fallida', $email->getSubject());
        $this->assertSame("Error: disco lleno\n", $email->getTextBody());
    }

    public function testNoAddressSendsNothing(): void
    {
        $this->assertFalse($this->mailer('')->send('VenPosTPV: copia de seguridad fallida', 'x'));
        $this->assertSame([], $this->sent);
    }

    public function testAnUnconfiguredAccountIsAnError(): void
    {
        $this->expectException(TicketMailException::class);
        $this->mailer('admin@example.com', configured: false)->send('s', 'x');
    }

    public function testATransportFailureIsAnError(): void
    {
        $this->expectException(TicketMailException::class);
        $this->expectExceptionMessage('Connection refused');
        $this->mailer('admin@example.com', failSend: true)->send('s', 'x');
    }
}
