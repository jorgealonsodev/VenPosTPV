<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\EventListener\FiscalJournalFlushListener;
use VenPosTPV\EventListener\OutboxSyncListener;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalFlushResult;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;

/** D3.2: the flush after the response, only when documents were issued, never failing. */
final class FiscalJournalFlushListenerTest extends TestCase
{
    public function testNothingIsFlushedWhenTheRequestIssuedNoDocument(): void
    {
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->method('issuedHere')->willReturn(false);
        $writer = $this->createMock(FiscalJournalWriter::class);
        $writer->expects($this->never())->method('flushAfterIssue');

        (new FiscalJournalFlushListener($intents, $writer))->onTerminate(new \stdClass());
    }

    public function testTheJournalIsFlushedAfterARequestThatIssuedDocuments(): void
    {
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->method('issuedHere')->willReturn(true);
        $writer = $this->createMock(FiscalJournalWriter::class);
        $writer->expects($this->once())->method('flushAfterIssue')->willReturn(new FiscalJournalFlushResult(FiscalJournalFlushResult::OK, 1));

        (new FiscalJournalFlushListener($intents, $writer))->onTerminate(new \stdClass());
    }

    public function testAFailingFlushNeverSurfaces(): void
    {
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->method('issuedHere')->willReturn(true);
        $writer = $this->createMock(FiscalJournalWriter::class);
        $writer->method('flushAfterIssue')->willThrowException(new \RuntimeException('disk full'));

        (new FiscalJournalFlushListener($intents, $writer))->onTerminate(new \stdClass());

        $this->addToAssertionCount(1);
    }

    public function testItRunsOnRequestAndCommandEndBeforeTheUpload(): void
    {
        $events = [];
        foreach ((new \ReflectionMethod(FiscalJournalFlushListener::class, 'onTerminate'))->getAttributes(AsEventListener::class) as $attribute) {
            $listener = $attribute->newInstance();
            $events[$listener->event] = $listener->priority;
        }
        $upload = null;
        foreach ((new \ReflectionMethod(OutboxSyncListener::class, 'onTerminate'))->getAttributes(AsEventListener::class) as $attribute) {
            $upload = $attribute->newInstance()->priority ?? 0;
        }

        $this->assertSame([KernelEvents::TERMINATE, ConsoleEvents::TERMINATE], array_keys($events));
        $this->assertGreaterThan($upload, $events[KernelEvents::TERMINATE]);
    }

    /** @return array{ResponseEvent, TerminateEvent} */
    private function httpEvents(JsonResponse $response): array
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/checkout/coupon', 'POST');

        return [
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
            new TerminateEvent($kernel, $request, $response),
        ];
    }

    public function testAResponseThatIssuedADocumentIsComplete(): void
    {
        // CGI has no fastcgi_finish_request: without these headers the browser waits for the flush.
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->method('issuedHere')->willReturn(true);
        $response = new JsonResponse(['data' => ['number' => 'XKBKNABJK']]);
        [$responseEvent] = $this->httpEvents($response);

        (new FiscalJournalFlushListener($intents, $this->createMock(FiscalJournalWriter::class)))->onResponse($responseEvent);

        $this->assertSame((string) \strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
        $this->assertSame('close', $response->headers->get('Connection'));
    }

    public function testAResponseThatIssuedNothingIsLeftAlone(): void
    {
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->method('issuedHere')->willReturn(false);
        $response = new JsonResponse(['data' => []]);
        [$responseEvent] = $this->httpEvents($response);

        (new FiscalJournalFlushListener($intents, $this->createMock(FiscalJournalWriter::class)))->onResponse($responseEvent);

        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertFalse($response->headers->has('Connection'));
    }

    public function testTheFlushAfterAResponseIsBoundedInDocumentsAndLockWait(): void
    {
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->method('issuedHere')->willReturn(true);
        $writer = $this->createMock(FiscalJournalWriter::class);
        $writer->expects($this->once())->method('flushAfterIssue')
            ->with(FiscalJournalFlushListener::AFTER_RESPONSE_LIMIT, FiscalJournalFlushListener::LOCK_WAIT_SECONDS)
            ->willReturn(new FiscalJournalFlushResult(FiscalJournalFlushResult::OK, 1));
        [, $terminateEvent] = $this->httpEvents(new JsonResponse([]));

        (new FiscalJournalFlushListener($intents, $writer))->onTerminate($terminateEvent);

        $this->assertLessThanOrEqual(2.0, FiscalJournalFlushListener::LOCK_WAIT_SECONDS);
        $this->assertGreaterThan(0, FiscalJournalFlushListener::AFTER_RESPONSE_LIMIT);
    }

    public function testACommandFlushesEverything(): void
    {
        $intents = $this->createMock(FiscalJournalIntents::class);
        $intents->method('issuedHere')->willReturn(true);
        $writer = $this->createMock(FiscalJournalWriter::class);
        $writer->expects($this->once())->method('flushAfterIssue')->with(null, null)
            ->willReturn(new FiscalJournalFlushResult(FiscalJournalFlushResult::OK, 1));

        (new FiscalJournalFlushListener($intents, $writer))->onTerminate(new ConsoleTerminateEvent(new Command('tpv:test'), new ArrayInput([]), new NullOutput(), 0));
    }

    public function testItClosesTheResponseLikeTheUploadListener(): void
    {
        $priorities = [];
        foreach ([FiscalJournalFlushListener::class, OutboxSyncListener::class] as $class) {
            foreach ((new \ReflectionMethod($class, 'onResponse'))->getAttributes(AsEventListener::class) as $attribute) {
                $listener = $attribute->newInstance();
                $priorities[$class] = [$listener->event, $listener->priority];
            }
        }

        $this->assertSame([KernelEvents::RESPONSE, -1024], $priorities[FiscalJournalFlushListener::class]);
        $this->assertSame($priorities[OutboxSyncListener::class], $priorities[FiscalJournalFlushListener::class]);
    }
}
