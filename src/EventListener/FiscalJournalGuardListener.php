<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;

/**
 * The till cannot issue documents while the database is behind the fiscal journal
 * (fiscal-journal S5, D5, D7, A3): every endpoint that issues a ticket, a credit
 * note or an invoice asks FiscalJournalGuard first, once the route is known and
 * before its controller runs, so the refusal comes before any number is taken and
 * before PrestaShop is called (a coupon sale, an online refund). Everything else
 * (catalog, reports, Ajustes, previews, PDFs) keeps working.
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

    public function __construct(private readonly FiscalJournalGuard $guard)
    {
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
