<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;

/**
 * Journals the fiscal documents a request or command issued, once its response is
 * sent (fiscal-journal D3.2). It runs before OutboxSyncListener::onTerminate, so
 * the upload that follows finds the lines. A failure is logged and never reaches
 * the sale: what is not written now is written by the next flush (S2).
 *
 * A request that issued a document gets its response closed first (AfterResponse:
 * the CGI front controller has no fastcgi_finish_request), and its flush is bounded:
 * at most AFTER_RESPONSE_LIMIT lines, and journal.lock is only waited for
 * LOCK_WAIT_SECONDS; the every-minute job takes the rest. A command flushes
 * everything.
 */
final class FiscalJournalFlushListener
{
    /** Above OutboxSyncListener::onTerminate (priority 0). */
    public const PRIORITY = 64;
    /** Lines one after-response flush writes at most (about 10 ms each). */
    public const AFTER_RESPONSE_LIMIT = 20;
    /** How long an after-response flush waits for another flush to release journal.lock. */
    public const LOCK_WAIT_SECONDS = 2.0;

    public function __construct(
        private readonly FiscalJournalIntents $intents,
        private readonly FiscalJournalWriter $writer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->intents->issuedHere()) {
            return;
        }

        AfterResponse::closeConnection($event->getResponse());
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: self::PRIORITY)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: self::PRIORITY)]
    public function onTerminate(object $event): void
    {
        if (!$this->intents->issuedHere()) {
            return;
        }

        ignore_user_abort(true);
        try {
            if ($event instanceof TerminateEvent) {
                $this->writer->flushAfterIssue(self::AFTER_RESPONSE_LIMIT, self::LOCK_WAIT_SECONDS);
            } else {
                $this->writer->flushAfterIssue(null, null);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Fiscal journal flush after the response failed: {message}', ['message' => $e->getMessage()]);
        }
    }
}
