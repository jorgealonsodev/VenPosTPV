<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\SecurityHeadersListener;

/**
 * docs/prd-seguridad-strix.md R10: the HTML pages get a CSP with a per-request
 * nonce plus the usual hardening headers; the APIs are left unchanged.
 */
final class SecurityHeadersListenerTest extends TestCase
{
    private function handle(string $path, ?Response $response = null, ?string $shopUrl = null, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $listener = new SecurityHeadersListener($shopUrl);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);
        $listener->onRequest(new RequestEvent($kernel, $request, $type));
        $response ??= new Response('<html></html>');
        $listener->onResponse(new ResponseEvent($kernel, $request, $type, $response));

        return $response;
    }

    private static function nonceOf(Response $response): string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $m), $csp);

        return $m[1];
    }

    public function testTheHtmlPagesGetTheSecurityHeaders(): void
    {
        foreach (['/app/pos', '/app/login', '/app/dev-login', '/sso'] as $path) {
            $response = $this->handle($path);
            $nonce = self::nonceOf($response);

            $this->assertSame(
                "default-src 'self'; script-src 'self' 'nonce-" . $nonce . "' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com; "
                . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
                . "img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
                $response->headers->get('Content-Security-Policy'),
                $path
            );
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $path);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
            $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'), $path);
        }
    }

    public function testTheNonceIsRandomBase64OfSixteenBytesAndChangesPerRequest(): void
    {
        $first = self::nonceOf($this->handle('/app/pos'));
        $second = self::nonceOf($this->handle('/app/pos'));

        $this->assertNotSame($first, $second);
        $this->assertSame(16, \strlen((string) base64_decode($first, true)));
    }

    public function testTheNonceInTheHeaderIsTheOneTheTemplatesReceive(): void
    {
        $listener = new SecurityHeadersListener(null);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/app/login');
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $nonce = $request->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE);
        $response = new Response('');
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertIsString($nonce);
        $this->assertSame($nonce, self::nonceOf($response));
    }

    public function testShopProductImagesAreAllowed(): void
    {
        // Catalog images are served by PrestaShop (CatalogSyncService image_url).
        $csp = (string) $this->handle('/app/pos', null, 'https://tienda.example.com/shop/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("img-src 'self' data: https://tienda.example.com;", $csp);
    }

    public function testTheApisAreLeftUnchanged(): void
    {
        foreach (['/api/agent/v1/jobs', '/api/checkout', '/api/health'] as $path) {
            $response = $this->handle($path, new JsonResponse(['ok' => true]));

            foreach (['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy'] as $header) {
                $this->assertFalse($response->headers->has($header), $path . ' ' . $header);
            }
        }
    }

    public function testHeadersAlreadySetAreKept(): void
    {
        $response = new Response('');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");

        $this->handle('/app/pos', $response);

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        $response = $this->handle('/app/pos', null, null, HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }
}
