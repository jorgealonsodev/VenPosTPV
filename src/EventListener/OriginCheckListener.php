<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cross-site request check of the browser API (docs/prd-seguridad-strix.md R7).
 *
 * The browser API authenticates with the session cookie only, and SameSite=Lax
 * does not stop a sibling subdomain (the shop on tienda.example.com, the TPV on
 * tpv.example.com). A request that changes data is therefore accepted only when
 * its Origin (or, without one, its Referer) is the TPV itself: the host the
 * request reached or the public TPV_URL (behind a hosting proxy PHP may see http
 * while the browser uses https). A request with neither header is not a browser
 * and passes. The print agent API uses its Bearer key, not a cookie, and is not
 * checked.
 */
final class OriginCheckListener
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private readonly ?string $tpvOrigin;

    public function __construct(?string $tpvUrl = null)
    {
        $this->tpvOrigin = self::origin((string) $tpvUrl);
    }

    // After the router (32), before the DeviceListener (8): a rejected request
    // never touches the session or the device cookie.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (\in_array($request->getMethod(), self::SAFE_METHODS, true)
            || !str_starts_with($path, '/api/')
            || str_starts_with($path, '/api/agent/v1/')) {
            return;
        }

        $sender = $request->headers->get('Origin') ?? $request->headers->get('Referer');
        if ($sender === null) {
            return;
        }

        $origin = self::origin($sender);
        if ($origin !== null && ($origin === self::origin($request->getSchemeAndHttpHost()) || $origin === $this->tpvOrigin)) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => 'Origin not allowed'], Response::HTTP_FORBIDDEN));
    }

    /** scheme://host[:port] of a URL, lower case and without the default port; null when it has none. */
    private static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $origin = $scheme . '://' . strtolower($parts['host']);
        if (isset($parts['port']) && $parts['port'] !== (self::DEFAULT_PORTS[$scheme] ?? null)) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
