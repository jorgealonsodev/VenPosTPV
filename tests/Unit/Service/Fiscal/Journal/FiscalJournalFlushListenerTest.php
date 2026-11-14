<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
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
}
