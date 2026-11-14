<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;

/**
 * Journals the fiscal documents a request or command issued, once its response is
 * sent (fiscal-journal D3.2). It runs before OutboxSyncListener::onTerminate, so
 * the upload that follows finds the lines. A failure is logged and never reaches
 * the sale: what is not written now is written by the next flush (S2).
 */
final class FiscalJournalFlushListener
{
    /** Above OutboxSyncListener::onTerminate (priority 0). */
    public const PRIORITY = 64;

    public function __construct(
        private readonly FiscalJournalIntents $intents,
        private readonly FiscalJournalWriter $writer,
        private readonly ?LoggerInterface $logger = null,
    ) {
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
            $this->writer->flushAfterIssue();
        } catch (\Throwable $e) {
            $this->logger?->error('Fiscal journal flush after the response failed: {message}', ['message' => $e->getMessage()]);
        }
    }
}
