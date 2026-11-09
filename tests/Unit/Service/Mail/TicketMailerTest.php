<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use VenPosTPV\Service\Mail\MailSettings;
use VenPosTPV\Service\Mail\MailSettingsStore;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;
use VenPosTPV\Service\Print\TicketData;
use VenPosTPV\Service\Print\TicketDataResolver;
use VenPosTPV\Service\Print\TicketLine;
use VenPosTPV\Service\Print\TicketPdfGenerator;

final class TicketMailerTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];
    /** @var list<string> */
    private array $dsns = [];

    private function mailer(bool $configured = true, ?TicketData $ticket = null, bool $failSend = false): TicketMailer
    {
        $store = $this->createMock(MailSettingsStore::class);
        $store->method('load')->willReturn(MailSettings::fromArray($configured ? [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'from_email' => 'tienda@example.com', 'from_name' => 'Nada Más',
        ] : [], 'pw'));
        $resolver = $this->createMock(TicketDataResolver::class);
        $resolver->method('resolve')->willReturn($ticket);
        $pdf = $this->createMock(TicketPdfGenerator::class);
        $pdf->method('generate')->willReturn('%PDF-1.7 fake');
        $test = $this;
        $factory = function (string $dsn) use ($test, $failSend): AbstractTransport {
            $test->dsns[] = $dsn;

            return new class ($test, $failSend) extends AbstractTransport {
                public function __construct(private readonly TicketMailerTest $test, private readonly bool $fail)
                {
                    parent::__construct();
                }

                protected function doSend(SentMessage $message): void
                {
                    if ($this->fail) {
                        throw new \Symfony\Component\Mailer\Exception\TransportException('Connection refused');
                    }
                    $original = $message->getOriginalMessage();
                    \assert($original instanceof Email);
                    $this->test->record($original);
                }

                public function __toString(): string
                {
                    return 'test://';
                }
            };
        };

        return new TicketMailer($store, $resolver, $pdf, $factory);
    }

    public function record(Email $email): void
    {
        $this->sent[] = $email;
    }

    private function ticket(): TicketData
    {
        return new TicketData(
            orderId: 42, reference: 'A26000003', storeName: 'Nada Más', storeAddress: '', ticketHeader: '', ticketFooter: '',
            ticketLogo: null, ticketWidthPx: 302, date: new \DateTimeImmutable('2026-11-05 10:00:00'), cashierName: 'Ana',
            lines: [new TicketLine('Gorro', 1, 8.0, 8.0)], subtotal: 6.61, tax: 1.39, total: 8.0,
            paymentMethod: 'card', amountTendered: 8.0, changeDue: 0.0, verificationUrl: 'https://tpv.example.com/v',
        );
    }

    public function testTheSaleTicketIsSentAsAPdfAttachmentFromTheShopAccount(): void
    {
        $this->mailer(ticket: $this->ticket())->sendSale(42, 'cliente@example.com');

        $this->assertCount(1, $this->sent);
        $email = $this->sent[0];
        $this->assertSame('cliente@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('tienda@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Nada Más', $email->getFrom()[0]->getName());
        $this->assertStringContainsString('A26000003', (string) $email->getSubject());
        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('ticket-A26000003.pdf', $attachments[0]->getFilename());
        $this->assertSame('application/pdf', $attachments[0]->getMediaType() . '/' . $attachments[0]->getMediaSubtype());
        $this->assertSame(['smtp://u:pw@smtp.example.com:587'], $this->dsns);
    }

    /** @return iterable<string, array{0: bool, 1: bool, 2: string, 3: int}> */
    public static function failures(): iterable
    {
        yield 'not configured' => [false, true, 'cliente@example.com', 409];
        yield 'bad address' => [true, true, 'no-es-email', 422];
        yield 'header injection' => [true, true, "a@b.es\r\nBcc: x@y.z", 422];
        yield 'unknown sale' => [true, false, 'cliente@example.com', 404];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testRefusedSendsSayWhy(bool $configured, bool $withTicket, string $to, int $status): void
    {
        try {
            $this->mailer($configured, $withTicket ? $this->ticket() : null)->sendSale(42, $to);
            $this->fail('expected a refusal');
        } catch (TicketMailException $e) {
            $this->assertSame($status, $e->status);
        }
        $this->assertSame([], $this->sent);
    }

    public function testASmtpFailureIsReportedNotThrownRaw(): void
    {
        $this->expectException(TicketMailException::class);
        $this->expectExceptionMessageMatches('/servidor de correo/');
        $this->mailer(ticket: $this->ticket(), failSend: true)->sendSale(42, 'cliente@example.com');
    }

    public function testACouponIsSentAsItsPdfFromTheShopAccount(): void
    {
        $this->mailer()->sendCoupon('cliente@example.com', 'ABCD2345', 'Nada Más', '%PDF-1.7 cupon');

        $this->assertCount(1, $this->sent);
        $email = $this->sent[0];
        $this->assertSame('cliente@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('tienda@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Tu cupón de descuento ABCD2345 - Nada Más', $email->getSubject());
        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('cupon-ABCD2345.pdf', $attachments[0]->getFilename());
        $this->assertSame('%PDF-1.7 cupon', $attachments[0]->getBody());
    }

    /** @return iterable<string, array{0: bool, 1: string, 2: int}> */
    public static function couponFailures(): iterable
    {
        yield 'not configured' => [false, 'cliente@example.com', 409];
        yield 'bad address' => [true, 'no-es-email', 422];
        yield 'header injection' => [true, "a@b.es\r\nBcc: x@y.z", 422];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('couponFailures')]
    public function testRefusedCouponSendsSayWhy(bool $configured, string $to, int $status): void
    {
        try {
            $this->mailer($configured)->sendCoupon($to, 'ABCD2345', 'Nada Más', '%PDF');
            $this->fail('expected a refusal');
        } catch (TicketMailException $e) {
            $this->assertSame($status, $e->status);
        }
        $this->assertSame([], $this->sent);
    }

    public function testTheTestEmailUsesTheAccountAndHasNoAttachment(): void
    {
        $this->mailer()->sendTest('admin@example.com');

        $this->assertCount(1, $this->sent);
        $this->assertSame('admin@example.com', $this->sent[0]->getTo()[0]->getAddress());
        $this->assertSame([], $this->sent[0]->getAttachments());
    }
}
