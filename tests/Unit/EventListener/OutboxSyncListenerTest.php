<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\OutboxSyncListener;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;

final class OutboxSyncListenerTest extends TestCase
{
    private function events(JsonResponse $response): array
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/checkout', 'POST');

        return [
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
            new TerminateEvent($kernel, $request, $response),
        ];
    }

    public function testAQueuedUploadRunsOnceAfterAClosedResponse(): void
    {
        $trigger = new OutboxSyncTrigger();
        $trigger->request();
        $sync = $this->createMock(OutboxSync::class);
        $sync->expects($this->once())->method('run')->with(OutboxSyncListener::LIMIT);
        $listener = new OutboxSyncListener($trigger, $sync);
        $response = new JsonResponse(['data' => ['order_id' => 15]]);
        [$responseEvent, $terminateEvent] = $this->events($response);

        $listener->onResponse($responseEvent);
        $listener->onTerminate($terminateEvent);
        $listener->onTerminate($terminateEvent);

        $this->assertSame((string) \strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
        $this->assertSame('close', $response->headers->get('Connection'));
        $this->assertFalse($trigger->isRequested());
    }

    public function testRequestsWithoutUploadsAreLeftAlone(): void
    {
        $sync = $this->createMock(OutboxSync::class);
        $sync->expects($this->never())->method('run');
        $listener = new OutboxSyncListener(new OutboxSyncTrigger(), $sync);
        $response = new JsonResponse(['data' => []]);
        [$responseEvent, $terminateEvent] = $this->events($response);

        $listener->onResponse($responseEvent);
        $listener->onTerminate($terminateEvent);

        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertFalse($response->headers->has('Connection'));
    }

    public function testAFailedAttemptNeverSurfaces(): void
    {
        $trigger = new OutboxSyncTrigger();
        $trigger->request();
        $sync = $this->createMock(OutboxSync::class);
        $sync->method('run')->willThrowException(new \RuntimeException('PrestaShop down'));
        [, $terminateEvent] = $this->events(new JsonResponse([]));

        (new OutboxSyncListener($trigger, $sync))->onTerminate($terminateEvent);

        $this->assertFalse($trigger->isRequested());
    }
}
