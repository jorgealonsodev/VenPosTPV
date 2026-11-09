<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VenPosTPV\EventListener\NoIndexListener;

/**
 * The TPV must never appear in search engines: every response says noindex, and
 * known search and AI crawlers get a 403. WhatsApp's link preview is not blocked.
 */
final class NoIndexListenerTest extends TestCase
{
    private function request(string $path, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0'): Request
    {
        $request = Request::create($path);
        $request->headers->set('User-Agent', $userAgent);

        return $request;
    }

    public function testEveryResponseSaysNoindex(): void
    {
        foreach (['/app/login', '/api/health', '/d/abc.def', '/sso'] as $path) {
            $response = new Response('ok');
            (new NoIndexListener())->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $this->request($path), HttpKernelInterface::MAIN_REQUEST, $response));
            self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'), $path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function crawlers(): iterable
    {
        yield 'Google' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'Bing' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'];
        yield 'Yandex' => ['Mozilla/5.0 (compatible; YandexBot/3.0)'];
        yield 'Baidu' => ['Mozilla/5.0 (compatible; Baiduspider/2.0)'];
        yield 'DuckDuckGo' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)'];
        yield 'Apple' => ['Mozilla/5.0 (Macintosh) Applebot/0.1'];
        yield 'Ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0)'];
        yield 'Semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl)'];
        yield 'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2)'];
        yield 'ClaudeBot' => ['Mozilla/5.0 (compatible; ClaudeBot/1.0)'];
        yield 'CCBot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)'];
    }

    #[DataProvider('crawlers')]
    public function testKnownCrawlersAreRefused(string $userAgent): void
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $this->request('/app/login', $userAgent), HttpKernelInterface::MAIN_REQUEST);
        (new NoIndexListener())->onRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
        self::assertSame('noindex, nofollow, noarchive', $event->getResponse()->headers->get('X-Robots-Tag'));
    }

    /** @return iterable<string, array{string}> */
    public static function people(): iterable
    {
        yield 'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36'];
        yield 'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1'];
        yield 'WhatsApp preview' => ['WhatsApp/2.24.20.79 A'];
        yield 'print agent' => ['venpos-agent/0.1.1'];
        yield 'no user agent' => [''];
    }

    #[DataProvider('people')]
    public function testBrowsersWhatsappAndThePrintAgentPass(string $userAgent): void
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $this->request('/app/login', $userAgent), HttpKernelInterface::MAIN_REQUEST);
        (new NoIndexListener())->onRequest($event);

        self::assertNull($event->getResponse());
    }
}
