<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * A submit that fails shows the form again with everything the person typed,
 * the install key and the two passwords included, so only the wrong field has
 * to be corrected. The page runs for real, in a separate PHP process, on a copy
 * of the installer inside an empty project folder.
 */
final class InstallerFormRefillTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/venpos-installer-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/public', 0700, true);
        copy(self::ROOT . '/public/install.php', $this->project . '/public/install.php');
        copy(self::ROOT . '/public/install-logo.php', $this->project . '/public/install-logo.php');
        symlink((string) realpath(self::ROOT . '/vendor'), $this->project . '/vendor');
    }

    protected function tearDown(): void
    {
        foreach (['/public/install.php', '/public/install-logo.php', '/public/run.php', '/INSTALL_KEY', '/vendor'] as $file) {
            @unlink($this->project . $file);
        }
        @rmdir($this->project . '/public');
        @rmdir($this->project);
    }

    public function testAFailedSubmitKeepsTheInstallKeyAndThePasswords(): void
    {
        $html = $this->request([
            'install_key' => 'WRONG-KEY-"1"',
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => 'tpv',
            'db_user' => 'tpv_user',
            'db_password' => ' db <pass> & "quoted" ',
            'tpv_url' => 'http://localhost',
            'admin_user' => 'admin',
            'admin_password' => "admin's-long-password",
            'store_name' => 'Tienda',
            'ps_url' => 'https://shop.example',
            'ps_mode' => 'pair',
            'pair_code' => '123456',
        ]);

        self::assertStringContainsString('La clave de instalación no es correcta', $html);
        self::assertSame('WRONG-KEY-"1"', $this->fieldValue($html, 'install_key'));
        // Exactly as typed: passwords are never trimmed.
        self::assertSame(' db <pass> & "quoted" ', $this->fieldValue($html, 'db_password'));
        self::assertSame("admin's-long-password", $this->fieldValue($html, 'admin_password'));
        self::assertStringNotContainsString('<pass>', $html, 'typed text is escaped in the page');
        // The other fields are kept as before.
        self::assertSame('tpv_user', $this->fieldValue($html, 'db_user'));
        self::assertSame('Tienda', $this->fieldValue($html, 'store_name'));
        self::assertSame('123456', $this->fieldValue($html, 'pair_code'));
    }

    public function testTheFirstVisitShowsTheSecretFieldsEmpty(): void
    {
        $html = $this->request(null);

        self::assertSame('', $this->fieldValue($html, 'install_key'));
        self::assertSame('', $this->fieldValue($html, 'db_password'));
        self::assertSame('', $this->fieldValue($html, 'admin_password'));
        self::assertSame('localhost', $this->fieldValue($html, 'db_host'));
    }

    /** maxlength would cut a pasted " 024 261" to " 024 26". */
    public function testThePairingCodeFieldDoesNotCutAPastedCode(): void
    {
        $html = $this->request(null);

        self::assertSame(1, preg_match('/<input\b[^>]*\bname="pair_code"[^>]*>/', $html, $tag));
        self::assertStringNotContainsString('maxlength', $tag[0]);
    }

    /** A password manager must not offer to overwrite an unrelated saved login. */
    public function testThePasswordFieldsAskForANewPassword(): void
    {
        $html = $this->request(null);

        foreach (['db_password', 'admin_password'] as $name) {
            self::assertSame(1, preg_match('/<input\b[^>]*\bname="' . $name . '"[^>]*>/', $html, $tag), "field {$name} is in the form");
            self::assertStringContainsString('autocomplete="new-password"', $tag[0], $name);
        }
    }

    /** @param array<string, string>|null $post null for a GET */
    private function request(?array $post): string
    {
        $public = $this->project . '/public';
        file_put_contents($public . '/run.php', '<?php'
            . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($post === null ? 'GET' : 'POST', true) . ';'
            . ' $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . ' $_SERVER["DOCUMENT_ROOT"] = ' . var_export($public, true) . ';'
            . ' $_POST = ' . var_export($post ?? [], true) . ';'
            . ' require __DIR__ . "/install.php";');

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($public . '/run.php') . ' 2>&1', $output);

        return implode("\n", $output);
    }

    private function fieldValue(string $html, string $name): string
    {
        self::assertSame(1, preg_match('/<input\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/', $html, $tag), "field {$name} is in the form");

        return preg_match('/\bvalue="([^"]*)"/', $tag[0], $value) === 1
            ? html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }
}
