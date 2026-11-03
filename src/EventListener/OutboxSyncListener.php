<?php

declare(strict_types=1);

namespace VenPosTPV\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;

/**
 * The immediate upload attempt of PRD S1: when a request queued an upload, it runs
 * after the response was sent, so the cashier never waits for PrestaShop.
 *
 * Under PHP-FPM the response is closed before kernel.terminate. Under the CGI
 * front controller used here it is flushed with an explicit Content-Length and
 * "Connection: close", so the browser has the whole answer before the upload
 * starts. Whatever this attempt does not finish, `tpv:sync:outbox` (every minute)
 * does.
 */
final class OutboxSyncListener
{
    /** Rows one after-response attempt uploads at most; the minute task takes the rest. */
    public const LIMIT = 10;

    public function __construct(
        private readonly OutboxSyncTrigger $trigger,
        private readonly OutboxSync $sync,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->trigger->isRequested()) {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();
        if (\is_string($content) && !$response->headers->has('Content-Length') && !$response->headers->has('Transfer-Encoding')) {
            $response->headers->set('Content-Length', (string) \strlen($content));
        }
        $response->headers->set('Connection', 'close');
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        if (!$this->trigger->consume()) {
            return;
        }

        // The browser may disconnect: the upload still finishes.
        ignore_user_abort(true);
        try {
            $this->sync->run(self::LIMIT);
        } catch (\Throwable $e) {
            // Never surfaces: the sale is stored and the minute task retries.
            $this->logger?->warning('Upload after the response failed: {message}', ['message' => $e->getMessage()]);
        }
    }
}
