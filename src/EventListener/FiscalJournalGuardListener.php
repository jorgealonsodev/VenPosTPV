<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuardCache;

/**
 * The till cannot issue documents while the database is behind the fiscal journal
 * (fiscal-journal S5, D5, D7, A3): every endpoint that issues a ticket, a credit
 * note or an invoice asks FiscalJournalGuard first, once the route is known and
 * before its controller runs, so the refusal comes before any number is taken and
 * before PrestaShop is called (a coupon sale, an online refund).
 *
 * Every other state-changing API request (any method but GET, HEAD and OPTIONS under
 * /api/) is refused the same way while the guard blocks (onRequest): a cash
 * movement, a till opening, a coupon, an upload row or a customer would take ids the
 * recovery needs. Deny by default: only ALLOWED_WHILE_BLOCKED passes, so an endpoint
 * added later is blocked without anyone listing it. Reading (catalog, reports,
 * Ajustes, previews, PDFs) keeps working. That check uses FiscalJournalGuardCache
 * (a "not blocked" answer reused for a few seconds for the same chain head).
 *
 * The refusal is HTTP 423 with the POS message and `code: fiscal_journal_behind`.
 * The same answer is given when the exception is thrown deeper in a request: under
 * the chain lock (FiscalRecorder::append, D5) it rolls the issue back, and when a
 * service or controller catches it and answers an error of its own, that answer is
 * replaced (a successful answer, e.g. an exchange whose credit note was issued
 * before its sale was refused, is kept: it says what was done).
 * A request without a logged-in user is left to the controller (401).
 */
final class FiscalJournalGuardListener
{
    /** The controllers that issue a fiscal document (FiscalRecorder::append). */
    public const ISSUING = [
        'VenPosTPV\Controller\Api\CheckoutController::checkout',
        'VenPosTPV\Controller\Api\RefundController::refund',
        'VenPosTPV\Controller\Api\RefundController::refundWithoutTicket',
        'VenPosTPV\Controller\Api\RefundController::retry',
        'VenPosTPV\Controller\Api\ExchangeController::exchange',
        'VenPosTPV\Controller\Api\ExchangeController::retry',
        'VenPosTPV\Controller\Api\InvoiceController::redeem',
    ];

    /**
     * The state-changing requests that still run while blocked: what it takes to get
     * out of the block (log in as an administrator on this computer's till and run the
     * recovery). None of them inserts into a journaled table.
     */
    public const ALLOWED_WHILE_BLOCKED = [
        // Logging in: an administrator must reach Ajustes to recover the documents.
        'VenPosTPV\Controller\Api\AuthController::login',
        // Logging out: only ends the session.
        'VenPosTPV\Controller\Api\AuthController::logout',
        // The login screen's employee search (read-only in effect: a lookup, no row written).
        'VenPosTPV\Controller\Api\AuthController::employeeLookup',
        // Linking this computer to an existing till (terminal_device only), so the till
        // screens and Ajustes open; creating a till stays blocked.
        'VenPosTPV\Controller\Api\DeviceController::link',
        // Putting back the device cookie the browser kept, on load (terminal_device only).
        'VenPosTPV\Controller\Api\DeviceController::restore',
        // The recovery itself (Ajustes → VERI*FACTU → Diario fiscal → «Recuperar documentos»);
        // its status (GET /api/fiscal/journal) is a read and needs no entry.
        'VenPosTPV\Controller\Api\FiscalJournalController::replay',
    ];

    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly FiscalJournalGuard $guard,
        private readonly ?FiscalJournalGuardCache $cache = null,
    ) {
    }

    // After the router (32), the Origin check (9) and the device lookup (8): a foreign
    // site is still told 403 and a computer without a till is sent to link one.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->cache === null || !$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (\in_array($request->getMethod(), self::READ_METHODS, true)
            || !str_starts_with($request->getPathInfo(), '/api/')
            || \in_array($request->attributes->get('_controller'), self::ALLOWED_WHILE_BLOCKED, true)) {
            return;
        }

        if ($this->cache->blocks()) {
            $event->setResponse(self::locked());
        }
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!\in_array($request->attributes->get('_controller'), self::ISSUING, true)) {
            return;
        }
        if (!$request->hasSession() || $request->getSession()->get('user_id') === null) {
            return;
        }

        $this->guard->assertCanIssue();
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 16)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->guard->takeRefusal()) {
            return;
        }
        if ($event->getResponse()->getStatusCode() >= 400) {
            $event->setResponse(self::locked());
        }
    }

    // Before the framework's error listener (-128) turns it into a 500.
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 8)]
    public function onException(ExceptionEvent $event): void
    {
        for ($e = $event->getThrowable(); $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof FiscalJournalBehindException) {
                $event->setResponse(self::locked());

                return;
            }
        }
    }

    private static function locked(): JsonResponse
    {
        return new JsonResponse(['error' => FiscalJournalBehindException::MESSAGE, 'code' => FiscalJournalBehindException::CODE, 'retry' => false], 423);
    }
}
