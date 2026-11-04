<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use VenPosTPV\Controller\App\LoginController;
use VenPosTPV\Controller\App\PosController;
use VenPosTPV\EventListener\SecurityHeadersListener;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceRegistry;
use VenPosTPV\Tests\Support\SessionRequest;

/**
 * docs/prd-seguridad-strix.md R9 and R10: the session JSON cannot close the
 * inline script, and every inline script carries the request's CSP nonce.
 */
final class PageScriptSecurityTest extends TestCase
{
    private const NONCE = 'q83vEjRmYXJ0bG9uZ25vbmNlMQ==';

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../templates'));
        $twig->addGlobal('base_path', '/tpv');

        return $twig;
    }

    /** @param array<string, mixed> $session */
    private function posPage(array $session): Response
    {
        $devices = $this->createMock(DeviceRegistry::class);
        $devices->method('terminalForToken')->willReturn(4);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['name' => 'Caja 1', 'ticket_letter' => 'A']);

        $request = SessionRequest::create([], $session);
        $request->attributes->set(SecurityHeadersListener::NONCE_ATTRIBUTE, self::NONCE);

        $controller = new PosController($this->twig(), $connection, $devices, $this->createMock(DeviceCookie::class), '/tpv');

        return $controller->index($request);
    }

    private function loginPage(): Response
    {
        $request = SessionRequest::create();
        $request->attributes->set(SecurityHeadersListener::NONCE_ATTRIBUTE, self::NONCE);

        return (new LoginController($this->twig(), '/tpv'))->index($request);
    }

    /** @return list<string> the opening tags of the inline scripts (no src) */
    private static function inlineScripts(string $html): array
    {
        preg_match_all('/<script\b[^>]*>/i', $html, $m);

        return array_values(array_filter($m[0], static fn (string $tag): bool => !str_contains($tag, ' src=')));
    }

    public function testThePosPageStillRendersForASignedInCashier(): void
    {
        $response = $this->posPage(['user_id' => 3, 'cashier_name' => 'Ana']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"cashier_name":"Ana"', (string) $response->getContent());
        $this->assertStringContainsString('"terminal_name":"Caja 1"', (string) $response->getContent());
    }

    public function testTheLoginPageStillRenders(): void
    {
        $response = $this->loginPage();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<login-screen />', (string) $response->getContent());
    }

    public function testTheSessionJsonCannotBreakOutOfItsScript(): void
    {
        $benign = (string) $this->posPage(['user_id' => 3, 'cashier_name' => 'Ana'])->getContent();
        $html = (string) $this->posPage(['user_id' => 3, 'cashier_name' => "</script><!--<script>x'\"&"])->getContent();

        $this->assertStringContainsString('\u003C\/script\u003E\u003C!--\u003Cscript\u003Ex\u0027\u0022\u0026', $html);
        $this->assertStringNotContainsString('<!--<script>', $html);
        $this->assertSame(substr_count($benign, '<script'), substr_count($html, '<script'));
        $this->assertSame(substr_count($benign, '</script'), substr_count($html, '</script'));
    }

    public function testEveryInlineScriptOfThePosPageCarriesTheNonce(): void
    {
        $scripts = self::inlineScripts((string) $this->posPage(['user_id' => 3])->getContent());

        $this->assertCount(3, $scripts);
        foreach ($scripts as $tag) {
            $this->assertStringContainsString('nonce="' . self::NONCE . '"', $tag);
        }
    }

    public function testEveryInlineScriptOfTheLoginPageCarriesTheNonce(): void
    {
        $scripts = self::inlineScripts((string) $this->loginPage()->getContent());

        $this->assertCount(2, $scripts);
        foreach ($scripts as $tag) {
            $this->assertStringContainsString('nonce="' . self::NONCE . '"', $tag);
        }
    }

    public function testNoComponentUsesInlineEventHandlerAttributes(): void
    {
        // The CSP has no 'unsafe-inline' for scripts, so onmouseover="..." attributes
        // would be blocked; components use Vue handlers (@mouseover) instead.
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/public/assets/js', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            if (preg_match_all('/\son[a-z]+\s*=\s*["\']/i', (string) file_get_contents($file->getPathname()), $m) > 0) {
                $offenders[] = $file->getFilename() . ' (' . \count($m[0]) . ')';
            }
        }

        self::assertSame([], $offenders);
    }

    public function testEveryInlineScriptOfEveryPageTemplateHasANonceAttribute(): void
    {
        foreach (glob(__DIR__ . '/../../templates/*.html.twig') ?: [] as $template) {
            foreach (self::inlineScripts((string) file_get_contents($template)) as $tag) {
                $this->assertStringContainsString('nonce="{{ csp_nonce }}"', $tag, basename($template));
            }
        }
    }

    public function testVueIsPinnedWithSubresourceIntegrity(): void
    {
        foreach (['pos' => (string) $this->posPage(['user_id' => 3])->getContent(), 'login' => (string) $this->loginPage()->getContent()] as $page => $html) {
            $this->assertSame(1, preg_match('#<script src="https://unpkg\.com/vue@(\d+\.\d+\.\d+)/dist/vue\.global\.prod\.js"([^>]*)>#', $html, $m), $page);
            $this->assertMatchesRegularExpression('/integrity="sha384-[A-Za-z0-9+\/=]{64}"/', $m[2], $page);
            $this->assertStringContainsString('crossorigin="anonymous"', $m[2], $page);
        }
    }
}
