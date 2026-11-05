<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\EmailSettingsController;
use VenPosTPV\Service\Mail\MailSettings;
use VenPosTPV\Service\Mail\MailSettingsStore;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;

final class EmailSettingsControllerTest extends TestCase
{
    private function request(string $method, ?string $role, array $body = []): Request
    {
        $request = Request::create('/api/settings/email', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $session = new Session(new MockArraySessionStorage());
        if ($role !== null) {
            $session->set('user_id', 1);
            $session->set('role', $role);
        }
        $request->setSession($session);

        return $request;
    }

    private function valid(): array
    {
        return ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'u@example.com', 'from_email' => 'tienda@example.com', 'from_name' => 'Nada Más'];
    }

    public function testOnlyAnAdminSeesOrChangesTheAccount(): void
    {
        $store = $this->createMock(MailSettingsStore::class);
        $store->expects($this->never())->method('save');
        $controller = new EmailSettingsController($store, $this->createMock(TicketMailer::class));

        $this->assertSame(403, $controller->show($this->request('GET', 'cashier'))->getStatusCode());
        $this->assertSame(403, $controller->update($this->request('PUT', 'cashier', $this->valid()))->getStatusCode());
        $this->assertSame(403, $controller->test($this->request('POST', null, ['to' => 'a@b.es']))->getStatusCode());
    }

    public function testShowNeverReturnsThePassword(): void
    {
        $store = $this->createMock(MailSettingsStore::class);
        $store->method('load')->willReturn(MailSettings::fromArray($this->valid(), 'secreto'));
        $response = (new EmailSettingsController($store, $this->createMock(TicketMailer::class)))->show($this->request('GET', 'admin'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('secreto', (string) $response->getContent());
        $this->assertTrue(json_decode((string) $response->getContent(), true)['data']['has_password']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: ?string}> */
    public static function passwords(): iterable
    {
        yield 'typed: replaces' => [['password' => 'nueva'], 'nueva'];
        yield 'empty: keeps' => [['password' => ''], null];
        yield 'absent: keeps' => [[], null];
        yield 'clear: removes' => [['clear_password' => true], ''];
    }

    /** @param array<string, mixed> $extra */
    #[\PHPUnit\Framework\Attributes\DataProvider('passwords')]
    public function testUpdateSavesValidatedSettingsAndThePasswordRule(array $extra, ?string $expected): void
    {
        $store = $this->createMock(MailSettingsStore::class);
        $store->expects($this->once())->method('save')->with($this->isInstanceOf(MailSettings::class), $expected);
        $store->method('load')->willReturn(MailSettings::fromArray($this->valid(), null));

        $response = (new EmailSettingsController($store, $this->createMock(TicketMailer::class)))->update($this->request('PUT', 'admin', $this->valid() + $extra));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnInvalidAccountIsA422WithTheReason(): void
    {
        $store = $this->createMock(MailSettingsStore::class);
        $store->expects($this->never())->method('save');
        $response = (new EmailSettingsController($store, $this->createMock(TicketMailer::class)))->update($this->request('PUT', 'admin', ['port' => 0] + $this->valid()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('puerto', (string) $response->getContent());
    }

    public function testTheTestEmailReportsTheServerAnswer(): void
    {
        $mailer = $this->createMock(TicketMailer::class);
        $mailer->method('sendTest')->willThrowException(new TicketMailException('El servidor de correo no aceptó el envío: auth', 502));
        $response = (new EmailSettingsController($this->createMock(MailSettingsStore::class), $mailer))->test($this->request('POST', 'admin', ['to' => 'a@b.es']));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('servidor de correo', (string) $response->getContent());
    }
}
