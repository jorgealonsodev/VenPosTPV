<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * The web installer shows the VenPosTPV logo embedded in the page, so it appears
 * whatever the hosting does with /assets before the TPV is installed.
 */
final class InstallerLogoTest extends TestCase
{
    private const PUBLIC_DIR = __DIR__ . '/../../../public';

    protected function setUp(): void
    {
        require_once self::PUBLIC_DIR . '/install-logo.php';
    }

    public function testTheLogoIsEmbeddedForTheLightAndTheDarkTheme(): void
    {
        $html = \venpostpv_installer_logo(self::PUBLIC_DIR);

        self::assertSame(2, substr_count($html, '<svg'), 'one logo per theme');
        self::assertStringContainsString('class="logo-light"', $html);
        self::assertStringContainsString('class="logo-dark"', $html);
        self::assertStringNotContainsString('src="assets/', $html, 'no request to /assets');
        self::assertStringNotContainsString('<?xml', $html);
        self::assertSame(1, preg_match_all('/aria-label="VenPosTPV"/', $html), 'one accessible name');
        // The two copies must not repeat an element id in the same page.
        preg_match_all('/\bid="([^"]+)"/', $html, $ids);
        self::assertSame($ids[1], array_unique($ids[1]));
    }

    public function testWithoutTheLogoFilesTheNameIsShownInstead(): void
    {
        self::assertSame('<span class="brand-text">VenPos<b>TPV</b></span>', \venpostpv_installer_logo(sys_get_temp_dir() . '/no-such-dir-' . bin2hex(random_bytes(4))));
    }

    public function testTheInstallerUsesIt(): void
    {
        $installer = (string) file_get_contents(self::PUBLIC_DIR . '/install.php');
        self::assertStringContainsString("require_once __DIR__ . '/install-logo.php';", $installer);
        self::assertStringContainsString('venpostpv_installer_logo(__DIR__)', $installer);
        self::assertStringNotContainsString('<img src="assets/img/brand/', $installer);
    }
}
