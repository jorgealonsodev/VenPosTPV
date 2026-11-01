<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class CashAssetsTemplateTest extends TestCase
{
    public function testCashStateModulesUseVersionedScriptUrls(): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../templates'));
        $html = $twig->render('pos.html.twig', [
            'base_path' => '/tpv',
            'session_data' => '{}',
        ]);

        preg_match_all('/<script src="([^"]+)"/', $html, $matches);
        $scripts = $matches[1];

        foreach ([
            '/tpv/assets/js/stores/useSession.js',
            '/tpv/assets/js/stores/useCart.js',
            '/tpv/assets/js/stores/useParkedTickets.js',
            '/tpv/assets/js/components/PaymentModal.js',
            '/tpv/assets/js/components/SaleScreen.js',
            '/tpv/assets/js/components/TicketListPanel.js',
            '/tpv/assets/js/components/CashCloseScreen.js',
        ] as $asset) {
            $url = $this->findAssetUrl($scripts, $asset);
            $query = parse_url($url, PHP_URL_QUERY);

            self::assertIsString($query, sprintf('%s must have a cache version', $asset));
            parse_str($query, $parameters);
            self::assertNotEmpty($parameters['v'] ?? null, sprintf('%s must have a non-empty cache version', $asset));
        }
    }

    /** @param list<string> $scripts */
    private function findAssetUrl(array $scripts, string $asset): string
    {
        foreach ($scripts as $script) {
            if (str_starts_with($script, $asset . '?')) {
                return $script;
            }
        }

        self::fail(sprintf('Missing rendered script URL for %s', $asset));
    }
}
