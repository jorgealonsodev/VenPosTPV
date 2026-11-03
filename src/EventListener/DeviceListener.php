<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\Service\Device\DeviceContext;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceNotLinkedException;
use VenPosTPV\Service\Device\DeviceRegistry;

/**
 * Resolves the till of the computer behind each API request (S10).
 *
 * The device cookie decides the till, never the first active terminal: services
 * read it from the request attribute, and the session's terminal_id (still read by
 * the drawer, refund, exchange, ticket and parked-ticket controllers) is set to it.
 * APIs that work on a till answer 409 {"code":"device_not_linked"} to a browser
 * without one, so the POS shows "Este equipo no tiene caja asignada".
 */
final class DeviceListener
{
    /** Path prefixes of the APIs that need this computer's till. */
    private const TILL_APIS = [
        '/api/cash/',
        '/api/checkout',
        '/api/refund',
        '/api/exchange',
        '/api/drawer/',
        '/api/ticket/reprint',
        '/api/parked-tickets',
    ];

    /**
     * No till context needed (and no database lookup) for these. The print agent
     * API works with its Bearer key only: no session and no cookies (PRD E9), so
     * this listener must not open the session there.
     */
    private const SKIPPED = ['/api/health', '/api/auth/', '/api/agent/v1/'];

    /** Session keys of the drawer cached for one till. */
    private const CASH_CACHE = ['cash_session_id', 'cash_opened_at', 'opening_cash'];

    public function __construct(private readonly DeviceRegistry $devices)
    {
    }

    // After the router (32) and the session listener (128).
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/') || $this->startsWithAny($path, self::SKIPPED)) {
            return;
        }

        $terminalId = $this->devices->terminalForToken(DeviceCookie::read($request));
        $request->attributes->set(DeviceContext::ATTRIBUTE, $terminalId);
        $this->syncSession($request, $terminalId);

        if ($terminalId === null && $this->startsWithAny($path, self::TILL_APIS)) {
            $event->setResponse(DeviceContext::notLinkedResponse());
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof DeviceNotLinkedException) {
            $event->setResponse(DeviceContext::notLinkedResponse());
        }
    }

    private function syncSession(Request $request, ?int $terminalId): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        if (!$session->isStarted() && !$request->hasPreviousSession()) {
            return;
        }

        $cached = $session->get('terminal_id');
        if ($cached !== null && $cached !== $terminalId) {
            // The drawer cached in this session belongs to another till.
            foreach (self::CASH_CACHE as $key) {
                $session->remove($key);
            }
        }

        if ($terminalId === null) {
            $session->remove('terminal_id');
        } else {
            $session->set('terminal_id', $terminalId);
        }
    }

    /** @param list<string> $prefixes */
    private function startsWithAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
