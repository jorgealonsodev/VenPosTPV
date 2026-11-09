<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use VenPosTPV\Controller\Api\ShareController;
use VenPosTPV\Tests\Unit\Service\Share\ShareFixture;

/**
 * «WhatsApp» next to «Enviar por email»: the till asks for a link to the PDF and a
 * short message, then opens WhatsApp with them. Same roles as each email endpoint.
 */
final class ShareControllerTest extends TestCase
{
    private const CASHIER = ['user_id' => 9, 'role' => 'cashier', 'terminal_id' => 3];

    /** @param array<string, mixed> $session @param array<string, mixed> $body */
    private function request(array $session, array $body = []): Request
    {
        $request = Request::create('/api/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $s = new Session(new MockArraySessionStorage());
        foreach ($session as $key => $value) {
            $s->set($key, $value);
        }
        $request->setSession($s);

        return $request;
    }

    private function controller(ShareFixture $fixture): ShareController
    {
        return new ShareController($fixture->service, $fixture->invoices, $fixture->slips);
    }

    /** @return array<string, mixed> */
    private function json(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    /** The link's token, checked against the signer: it opens that document only. */
    private function assertLinkTo(ShareFixture $fixture, string $url, string $type, int $id): void
    {
        self::assertStringStartsWith('https://tpv.example.com/d/', $url);
        self::assertSame(['type' => $type, 'id' => $id], $fixture->signer->verify(substr($url, \strlen('https://tpv.example.com/d/'))));
    }

    public function testTheTicketLinkComesWithAShortSpanishMessage(): void
    {
        $fixture = new ShareFixture($this);

        $response = $this->controller($fixture)->ticket($this->request(self::CASHIER), ShareFixture::SALE);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        $this->assertLinkTo($fixture, $data['url'], 'ticket', ShareFixture::SALE);
        self::assertSame('Hola, aquí tienes tu ticket R26000012 de Nada Más: ' . $data['url'], $data['message']);
    }

    public function testTheCouponLinkNamesTheCodeAndTheShop(): void
    {
        $fixture = new ShareFixture($this);

        $data = $this->json($this->controller($fixture)->coupon($this->request(self::CASHIER), ShareFixture::COUPON))['data'];

        $this->assertLinkTo($fixture, $data['url'], 'coupon', ShareFixture::COUPON);
        self::assertSame('Hola, aquí tienes tu cupón de descuento ABCD2345 de Nada Más: ' . $data['url'], $data['message']);
    }

    public function testWithoutASessionNoLinkIsMade(): void
    {
        $controller = $this->controller(new ShareFixture($this));

        self::assertSame(401, $controller->ticket($this->request([]), ShareFixture::SALE)->getStatusCode());
        self::assertSame(401, $controller->invoice($this->request([], ['consent' => true]), ShareFixture::INVOICE)->getStatusCode());
        self::assertSame(401, $controller->coupon($this->request([]), ShareFixture::COUPON)->getStatusCode());
    }

    public function testTheCouponKeepsTheRolesOfItsEmail(): void
    {
        $controller = $this->controller(new ShareFixture($this));

        self::assertSame(403, $controller->coupon($this->request(['user_id' => 9, 'role' => 'manager']), ShareFixture::COUPON)->getStatusCode());
        self::assertSame(200, $controller->coupon($this->request(['user_id' => 8, 'role' => 'admin']), ShareFixture::COUPON)->getStatusCode());
        // The ticket and the invoice email only ask for a signed-in user.
        self::assertSame(200, $controller->ticket($this->request(['user_id' => 9, 'role' => 'manager']), ShareFixture::SALE)->getStatusCode());
    }

    public function testAnUnknownDocumentIs404(): void
    {
        $controller = $this->controller(new ShareFixture($this));

        self::assertSame(404, $controller->ticket($this->request(self::CASHIER), 99)->getStatusCode());
        self::assertSame(404, $controller->invoice($this->request(self::CASHIER, ['consent' => true]), 99)->getStatusCode());
        self::assertSame(404, $controller->coupon($this->request(self::CASHIER), 99)->getStatusCode());
    }

    public function testAnInvoiceWithoutConsentGetsNoLinkAndNothingIsRecorded(): void
    {
        $fixture = new ShareFixture($this);
        $fixture->invoices->expects(self::never())->method('recordEmailConsent');

        $response = $this->controller($fixture)->invoice($this->request(self::CASHIER), ShareFixture::INVOICE);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => ShareController::NO_CONSENT_MESSAGE], $this->json($response));
    }

    public function testTheConsentGivenNowIsStoredWithTheInvoiceLikeTheEmail(): void
    {
        $fixture = new ShareFixture($this);
        $fixture->invoices->expects(self::once())->method('recordEmailConsent')->with(ShareFixture::INVOICE, self::isInstanceOf(\DateTimeImmutable::class));

        $response = $this->controller($fixture)->invoice($this->request(self::CASHIER, ['consent' => true]), ShareFixture::INVOICE);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        $this->assertLinkTo($fixture, $data['url'], 'invoice', ShareFixture::INVOICE);
        self::assertSame('Hola, aquí tienes tu factura F26000004 de Nada Más: ' . $data['url'], $data['message']);
    }

    public function testAnInvoiceWithStoredConsentNeedsNoNewOne(): void
    {
        $fixture = new ShareFixture($this, consent: true);
        $fixture->invoices->expects(self::never())->method('recordEmailConsent');

        self::assertSame(200, $this->controller($fixture)->invoice($this->request(self::CASHIER), ShareFixture::INVOICE)->getStatusCode());
    }
}
