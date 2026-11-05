<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\TicketEmailController;
use VenPosTPV\Service\Mail\TicketMailer;
use VenPosTPV\Service\Mail\TicketMailException;

final class TicketEmailControllerTest extends TestCase
{
    private function request(bool $loggedIn, array $body = []): Request
    {
        $request = Request::create('/api/ticket/sale/5/email', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $session = new Session(new MockArraySessionStorage());
        if ($loggedIn) {
            $session->set('user_id', 3);
        }
        $request->setSession($session);

        return $request;
    }

    public function testACashierSendsTheSaleTicket(): void
    {
        $mailer = $this->createMock(TicketMailer::class);
        $mailer->expects($this->once())->method('sendSale')->with(5, 'cliente@example.com');

        $response = (new TicketEmailController($mailer))->send($this->request(true, ['email' => 'cliente@example.com']), 5);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testWithoutSessionNothingIsSent(): void
    {
        $mailer = $this->createMock(TicketMailer::class);
        $mailer->expects($this->never())->method('sendSale');

        $this->assertSame(401, (new TicketEmailController($mailer))->send($this->request(false, ['email' => 'a@b.es']), 5)->getStatusCode());
        $this->assertSame(401, (new TicketEmailController($mailer))->enabled($this->request(false))->getStatusCode());
    }

    public function testARefusalKeepsItsStatusAndMessage(): void
    {
        $mailer = $this->createMock(TicketMailer::class);
        $mailer->method('sendSale')->willThrowException(new TicketMailException('El email del cliente no es válido.', 422));

        $response = (new TicketEmailController($mailer))->send($this->request(true, ['email' => 'x']), 5);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['error' => 'El email del cliente no es válido.'], json_decode((string) $response->getContent(), true));
    }

    public function testEnabledFollowsTheAccount(): void
    {
        $mailer = $this->createMock(TicketMailer::class);
        $mailer->method('isEnabled')->willReturn(true);

        $response = (new TicketEmailController($mailer))->enabled($this->request(true));

        $this->assertSame(['data' => ['enabled' => true]], json_decode((string) $response->getContent(), true));
    }
}
