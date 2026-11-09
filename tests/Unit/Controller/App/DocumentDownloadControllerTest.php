<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Controller\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use VenPosTPV\Controller\App\DocumentDownloadController;
use VenPosTPV\Tests\Unit\Service\Share\ShareFixture;

/** GET /d/{token}: the PDF behind a WhatsApp link, without a session. */
final class DocumentDownloadControllerTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: int, 2: string, 3: string}> */
    public static function documents(): iterable
    {
        yield 'ticket' => ['ticket', ShareFixture::SALE, '%PDF-ticket-R26000012', 'inline; filename="ticket-R26000012.pdf"'];
        yield 'invoice' => ['invoice', ShareFixture::INVOICE, '%PDF-invoice-F26000004', 'inline; filename="factura-F26000004.pdf"'];
        yield 'coupon' => ['coupon', ShareFixture::COUPON, '%PDF-coupon-ABCD2345', 'inline; filename="cupon-ABCD2345.pdf"'];
    }

    #[DataProvider('documents')]
    public function testAValidLinkServesThePdfInlineWithoutASession(string $type, int $id, string $pdf, string $disposition): void
    {
        $fixture = new ShareFixture($this);
        $request = Request::create('/d/x');

        $response = (new DocumentDownloadController($fixture->service))->download($request, $fixture->signer->sign($type, $id));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($pdf, $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame($disposition, $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertFalse($request->hasSession(), 'no session is needed or opened');
    }

    public function testAnInvalidExpiredOrOrphanLinkIsAPlain404(): void
    {
        $now = time();
        $fixture = new ShareFixture($this, $now);
        $expired = (new ShareFixture($this, $now - 31 * 86400))->signer->sign('ticket', ShareFixture::SALE);
        $controller = new DocumentDownloadController($fixture->service);

        foreach ([
            'garbage' => 'nope',
            'tampered' => substr($fixture->signer->sign('ticket', ShareFixture::SALE), 0, -2) . 'xx',
            'expired' => $expired,
            'deleted sale' => $fixture->signer->sign('ticket', 99),
            'deleted invoice' => $fixture->signer->sign('invoice', 99),
            'deleted coupon' => $fixture->signer->sign('coupon', 99),
        ] as $case => $token) {
            $response = $controller->download(Request::create('/d/' . $token), $token);

            self::assertSame(404, $response->getStatusCode(), $case);
            self::assertSame('', (string) $response->getContent(), $case . ': no detail');
            self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $case);
        }
    }
}
