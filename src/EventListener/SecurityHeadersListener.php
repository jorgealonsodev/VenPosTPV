<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security headers of the HTML pages, /app/* and /sso (docs/prd-seguridad-strix.md R10).
 *
 * Each request gets a fresh CSP nonce, stored in the "csp_nonce" request attribute;
 * the page controllers hand it to Twig and every inline <script> carries it.
 * 'unsafe-eval' stays because Vue compiles the components' template: strings in
 * the browser; Tailwind's CDN build injects <style> elements, hence the inline
 * styles. Product images come from PrestaShop, so the shop's origin is allowed
 * in img-src. The APIs are left unchanged. A header a controller already set wins.
 */
final class SecurityHeadersListener
{
    public const NONCE_ATTRIBUTE = 'csp_nonce';

    private readonly string $imageSources;

    public function __construct(?string $shopUrl = null)
    {
        $shop = self::origin((string) $shopUrl);
        $this->imageSources = "'self' data:" . ($shop !== null ? ' ' . $shop : '');
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && self::isPage($event->getRequest())) {
            self::nonce($event->getRequest());
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isPage($request)) {
            return;
        }

        $headers = [
            'Content-Security-Policy' => $this->policy(self::nonce($request)),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ];
        $responseHeaders = $event->getResponse()->headers;
        foreach ($headers as $name => $value) {
            if (!$responseHeaders->has($name)) {
                $responseHeaders->set($name, $value);
            }
        }
    }

    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $nonce . "' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            'img-src ' . $this->imageSources,
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /** The request's nonce, created on first use (an error page may skip onRequest). */
    private static function nonce(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (!\is_string($nonce) || $nonce === '') {
            $nonce = base64_encode(random_bytes(16));
            $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    private static function isPage(Request $request): bool
    {
        $path = $request->getPathInfo();

        return $path === '/sso' || $path === '/app' || str_starts_with($path, '/app/');
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
