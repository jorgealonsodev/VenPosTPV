<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\OriginCheckListener;

/**
 * docs/prd-seguridad-strix.md R7: the browser API authenticates with the session
 * cookie only, so a request that changes data must come from the TPV's own origin.
 */
final class OriginCheckListenerTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function dispatch(string $method, string $url, array $headers = [], ?string $tpvUrl = null): RequestEvent
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create($url, $method, [], [], [], $server, '{}');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new OriginCheckListener($tpvUrl))->onRequest($event);

        return $event;
    }

    private function assertBlocked(RequestEvent $event): void
    {
        $this->assertTrue($event->hasResponse(), 'the request must be blocked');
        $this->assertSame(403, $event->getResponse()->getStatusCode());
        $this->assertSame(['error' => 'Origin not allowed'], json_decode((string) $event->getResponse()->getContent(), true));
        $this->assertStringContainsString('application/json', (string) $event->getResponse()->headers->get('Content-Type'));
    }

    public function testAForeignOriginCannotCheckOut(): void
    {
        $this->assertBlocked($this->dispatch('POST', 'https://tpv.example.com/api/checkout', ['Origin' => 'https://tienda.example.com']));
    }

    public function testAForeignOriginCannotLogOut(): void
    {
        $this->assertBlocked($this->dispatch('POST', 'https://tpv.example.com/api/auth/logout', ['Origin' => 'https://evil.example.net']));
    }

    public function testEveryUnsafeMethodIsChecked(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertBlocked($this->dispatch($method, 'https://tpv.example.com/api/parked-tickets/3', ['Origin' => 'https://tienda.example.com']));
        }
    }

    public function testTheSameOriginPasses(): void
    {
        $this->assertFalse($this->dispatch('POST', 'https://tpv.example.com/api/checkout', ['Origin' => 'https://tpv.example.com'])->hasResponse());
        $this->assertFalse($this->dispatch('POST', 'http://127.0.0.1:8000/api/checkout', ['Origin' => 'http://127.0.0.1:8000'])->hasResponse(), 'non-default port');
        $this->assertFalse($this->dispatch('POST', 'https://tpv.example.com/api/checkout', ['Origin' => 'https://TPV.example.com:443'])->hasResponse(), 'case and default port are normalised');
    }

    public function testARequestWithoutOriginOrRefererPasses(): void
    {
        $this->assertFalse($this->dispatch('POST', 'https://tpv.example.com/api/checkout')->hasResponse());
    }

    public function testTheRefererIsUsedWhenThereIsNoOrigin(): void
    {
        $this->assertFalse($this->dispatch('POST', 'https://tpv.example.com/api/checkout', ['Referer' => 'https://tpv.example.com/app/pos'])->hasResponse());
        $this->assertBlocked($this->dispatch('POST', 'https://tpv.example.com/api/checkout', ['Referer' => 'https://tienda.example.com/admin/index.php']));
    }

    public function testTheOriginWinsOverTheReferer(): void
    {
        $this->assertBlocked($this->dispatch('POST', 'https://tpv.example.com/api/checkout', [
            'Origin' => 'https://tienda.example.com',
            'Referer' => 'https://tpv.example.com/app/pos',
        ]));
    }

    public function testAnOpaqueOriginIsBlocked(): void
    {
        $this->assertBlocked($this->dispatch('POST', 'https://tpv.example.com/api/checkout', ['Origin' => 'null']));
    }

    public function testThePrintAgentApiIsNotChecked(): void
    {
        foreach (['/api/agent/v1/pair', '/api/agent/v1/jobs/5/result', '/api/agent/v1/status'] as $path) {
            $this->assertFalse($this->dispatch('POST', 'https://tpv.example.com' . $path, ['Origin' => 'https://evil.example.net'])->hasResponse(), $path);
        }
    }

    public function testSafeMethodsAreNeverBlocked(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $this->assertFalse($this->dispatch($method, 'https://tpv.example.com/api/auth/employees-public', ['Origin' => 'https://evil.example.net'])->hasResponse(), $method);
        }
    }

    public function testPagesOutsideTheApiAreNotChecked(): void
    {
        $this->assertFalse($this->dispatch('POST', 'https://tpv.example.com/app/login', ['Origin' => 'https://evil.example.net'])->hasResponse());
    }

    public function testTheTpvUrlOriginPassesWhenTheProxySpeaksHttp(): void
    {
        // Plesk's nginx terminates TLS: PHP sees http while the browser sends https.
        $this->assertFalse($this->dispatch('POST', 'http://tpv.example.com/api/checkout', ['Origin' => 'https://tpv.example.com'], 'https://tpv.example.com/tpv')->hasResponse());
        $this->assertBlocked($this->dispatch('POST', 'http://tpv.example.com/api/checkout', ['Origin' => 'https://tpv.example.com'], ''));
    }

    public function testSubRequestsAreNotChecked(): void
    {
        $request = Request::create('https://tpv.example.com/api/checkout', 'POST', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example.net']);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST);
        (new OriginCheckListener(null))->onRequest($event);

        $this->assertFalse($event->hasResponse());
    }
}
