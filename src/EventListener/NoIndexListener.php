<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The TPV is private and must never appear in search engines. Every response says
 * noindex (public/robots.txt already asks crawlers not to visit, and public/.htaccess
 * adds the same header to static files), and known search and AI crawlers are refused
 * even when they ignore robots.txt. WhatsApp's link preview is not a crawler here:
 * the ticket links sent by WhatsApp must keep working.
 */
final class NoIndexListener
{
    public const HEADER = 'noindex, nofollow, noarchive';

    private const CRAWLERS = '/googlebot|google-inspectiontool|bingbot|bingpreview|yandex|baiduspider|duckduckbot|applebot|ahrefsbot|semrushbot|mj12bot|petalbot|dotbot|seznambot|sogou|exabot|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-web|anthropic-ai|ccbot|perplexitybot|bytespider|amazonbot|facebookbot|meta-externalagent/i';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $userAgent = (string) $event->getRequest()->headers->get('User-Agent', '');
        if ($userAgent !== '' && preg_match(self::CRAWLERS, $userAgent) === 1) {
            $event->setResponse(new Response('', Response::HTTP_FORBIDDEN, ['X-Robots-Tag' => self::HEADER]));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -256)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set('X-Robots-Tag', self::HEADER);
        }
    }
}
