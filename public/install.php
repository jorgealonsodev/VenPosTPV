<?php

declare(strict_types=1);

/*
 * VenPosTPV first-run installer.
 *
 * Asks for the database and shop settings, runs the migrations and writes
 * ../.env. It only runs while ../.env does not exist, needs the one-time key
 * from ../INSTALL_KEY and, when it finishes, locks itself and tries to
 * delete this file.
 */

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; form-action 'self'; frame-ancestors 'none'");

$projectDir = dirname(__DIR__);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $title, string $body): never
{
    // Same palette and type as the POS (assets/css/app.css); the mark is docs/brand/venpostpv-mark.svg.
    $mark = '<svg viewBox="0 0 64 64" width="40" height="40" aria-hidden="true">'
        . '<path d="M2 15a13 13 0 0 1 13-13h34a13 13 0 0 1 13 13v41l-5 5-5-5-5 5-5-5-5 5-5-5-5 5-5-5-5 5-5-5-5 5-5-5z" fill="var(--brand)"/>'
        . '<path d="M14 13h10.5l7.5 23 7.5-23H50L37.5 48h-11z" fill="#fff"/></svg>';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<title>' . h($title) . ' · VenPosTPV</title>'
        . '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCIgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiByb2xlPSJpbWciIGFyaWEtbGFiZWxsZWRieT0idCI+PHRpdGxlIGlkPSJ0Ij5WZW5Qb3NUUFY8L3RpdGxlPjxwYXRoIGQ9Ik0yIDE1YTEzIDEzIDAgMCAxIDEzLTEzaDM0YTEzIDEzIDAgMCAxIDEzIDEzdjQxbC01IDUtNS01LTUgNS01LTUtNSA1LTUtNS01IDUtNS01LTUgNS01LTUtNSA1LTUtNXoiIGZpbGw9IiMxYjdhMmYiLz48cGF0aCBkPSJNMTQgMTNoMTAuNWw3LjUgMjMgNy41LTIzSDUwTDM3LjUgNDhoLTExeiIgZmlsbD0iI2ZmZiIvPjwvc3ZnPgo=">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">'
        . '<style>'
        . ':root{--bg:oklch(96.5% 0.006 240);--surface:oklch(100% 0 0);--sunken:oklch(97.5% 0.005 240);--border:oklch(86% 0.01 240);--border-strong:oklch(72% 0.015 240);'
        . '--text:oklch(20% 0.018 240);--muted:oklch(45% 0.02 240);--brand:oklch(48% 0.16 145);--brand-hover:oklch(42% 0.15 145);--brand-text:oklch(42% 0.15 145);'
        . '--ring:oklch(60% 0.17 145 / .45);--ok-bg:oklch(95% 0.05 145);--ok-border:oklch(82% 0.09 145);--ok-text:oklch(34% 0.11 145);'
        . '--err-bg:oklch(96% 0.03 25);--err-border:oklch(84% 0.08 25);--err-text:oklch(40% 0.15 25);--shadow:0 1px 2px oklch(20% 0.02 240 / .06),0 8px 24px -8px oklch(20% 0.02 240 / .12)}'
        . '@media (prefers-color-scheme:dark){:root{--bg:oklch(13% 0.018 240);--surface:oklch(18% 0.022 240);--sunken:oklch(15% 0.02 240);--border:oklch(30% 0.025 240);--border-strong:oklch(42% 0.025 240);'
        . '--text:oklch(93% 0.01 240);--muted:oklch(72% 0.02 240);--brand:oklch(50% 0.16 145);--brand-hover:oklch(45% 0.15 145);--brand-text:oklch(74% 0.16 145);'
        . '--ok-bg:oklch(24% 0.06 145);--ok-border:oklch(38% 0.09 145);--ok-text:oklch(86% 0.1 145);--err-bg:oklch(22% 0.05 25);--err-border:oklch(38% 0.1 25);--err-text:oklch(86% 0.08 25);'
        . '--shadow:0 1px 2px #0006,0 12px 32px -12px #000a}}'
        . '*,*::before,*::after{box-sizing:border-box}'
        . 'html{background:var(--bg);color:var(--text);accent-color:var(--brand);-webkit-text-size-adjust:100%}'
        . 'body{margin:0;padding:40px 16px 64px;font:400 15px/1.55 "IBM Plex Sans",system-ui,sans-serif}'
        . '::selection{background:oklch(60% 0.17 145 / .3)}'
        . '.brand{max-width:720px;margin:0 auto 20px;display:flex;align-items:center;gap:12px;font-weight:700;font-size:19px;letter-spacing:-.01em}'
        . '.brand svg{flex:none;display:block}.brand b{color:var(--brand-text);font-weight:700}'
        . 'main{max-width:720px;margin:0 auto;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:32px 36px 36px;box-shadow:var(--shadow)}'
        . 'h1{font-size:24px;line-height:1.25;letter-spacing:-.015em;margin:0 0 20px;text-wrap:balance}'
        . 'h2{font-size:16px;font-weight:600;margin:36px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--border)}'
        . 'form>h2:first-child{margin-top:4px}p{margin:8px 0;max-width:68ch}'
        . 'label{display:block;margin:16px 0 6px;font-weight:600;font-size:14px}small{color:var(--muted);font-weight:400}'
        . 'input,select{width:100%;min-height:44px;padding:9px 12px;border:1px solid var(--border-strong);border-radius:8px;background:var(--sunken);color:var(--text);font:inherit;caret-color:var(--brand);transition:border-color .15s,box-shadow .15s,background-color .15s}'
        . 'input:hover{border-color:var(--muted)}input::placeholder{color:var(--muted);opacity:1}'
        . 'input:focus{outline:none;border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 3px var(--ring)}'
        . 'input:user-invalid{border-color:var(--err-text)}'
        . '.row{display:flex;gap:16px}.row>div{flex:1;min-width:0}.row>.narrow{flex:0 0 120px}'
        . 'button{margin-top:32px;min-height:48px;padding:12px 28px;border:0;border-radius:8px;background:var(--brand);color:#fff;font:inherit;font-weight:600;font-size:16px;cursor:pointer;'
        . 'box-shadow:0 1px 2px oklch(20% 0.05 145 / .3);transition:background-color .15s,transform .1s}'
        . 'button:hover{background:var(--brand-hover)}button:active{transform:translateY(1px)}'
        . 'button:focus-visible,a:focus-visible{outline:3px solid var(--ring);outline-offset:2px}'
        . 'a{color:var(--brand-text);text-underline-offset:3px;font-weight:600}'
        . '.err,.ok{border-radius:10px;padding:12px 16px;margin:0 0 16px}.err{background:var(--err-bg);border:1px solid var(--err-border);color:var(--err-text)}'
        . '.ok{background:var(--ok-bg);border:1px solid var(--ok-border);color:var(--ok-text)}.err ul{margin:6px 0 0;padding-left:20px}.err code,.ok code{background:transparent;border:1px solid currentColor;border-color:color-mix(in oklch,currentColor 30%,transparent)}'
        . '.table{overflow-x:auto;border:1px solid var(--border);border-radius:10px;margin:12px 0}'
        . 'table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:top}'
        . 'th{background:var(--sunken);font-weight:600;color:var(--muted);font-size:13px}tr:last-child td{border-bottom:0}td code{overflow-wrap:anywhere}'
        . 'code,pre{font-family:"IBM Plex Mono",ui-monospace,monospace;background:var(--sunken);border:1px solid var(--border);border-radius:5px;padding:1px 5px;font-size:13px}'
        . 'pre{padding:10px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}'
        . '@media (max-width:600px){body{padding:20px 12px 40px}main{padding:22px 18px 26px;border-radius:12px}h1{font-size:21px}.row{flex-direction:column;gap:0}.row>.narrow{flex:auto}button{width:100%}}'
        . '@media (prefers-reduced-motion:reduce){*{transition:none!important}}'
        . '</style></head><body><header class="brand">' . $mark . '<span>VenPos<b>TPV</b></span></header>'
        . '<main><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

if (PHP_VERSION_ID < 80400) {
    page('Instalación del TPV', '<p class="err">Este TPV necesita PHP 8.4 o superior y el servidor usa PHP ' . h(PHP_VERSION)
        . '. Elige PHP 8.4 o superior para el subdominio en la configuración de PHP del panel del hosting.</p>');
}
if (!extension_loaded('pdo_mysql')) {
    page('Instalación del TPV', '<p class="err">Falta la extensión <code>pdo_mysql</code> de PHP. Actívala en la configuración de PHP del subdominio.</p>');
}
if (!is_file($projectDir . '/vendor/autoload.php')) {
    page('Instalación del TPV', '<p class="err">Falta la carpeta <code>vendor/</code>. Sube el paquete completo del TPV.</p>');
}

require_once $projectDir . '/vendor/autoload.php';

$installer = new \VenPosTPV\Service\Install\WebInstaller($projectDir);

if ($installer->isLocked()) {
    // Only a finished install deletes this file; a dev checkout with .env keeps it.
    if (is_file($projectDir . '/' . \VenPosTPV\Service\Install\WebInstaller::LOCK_FILE)) {
        @unlink(__FILE__);
        @unlink($projectDir . '/install.php');
    }
    http_response_code(404);
    page('El TPV ya está instalado', '<p>Este instalador ya no se puede usar. Si quieres reinstalar, borra el archivo <code>.env</code> y <code>var/install.lock</code> y vuelve a subir <code>public/install.php</code>.</p>');
}

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$host = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$isLocal = in_array($host, ['localhost', '127.0.0.1'], true) && in_array($remote, ['127.0.0.1', '::1'], true);
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (in_array($remote, ['127.0.0.1', '::1'], true) && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
if (!$isHttps && !$isLocal) {
    page('Instalación del TPV', '<p class="err">Abre el instalador con <strong>https://</strong>. Las contraseñas no deben viajar sin cifrar. Activa un certificado SSL para el subdominio en el panel del hosting (por ejemplo, Let\'s Encrypt).</p>');
}

if (!$installer->isSafeDocumentRoot((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''))) {
    page('Instalación del TPV', '<p class="err">La raíz del documento del subdominio debe ser la carpeta del TPV (<code>'
        . h((string) realpath($projectDir)) . '</code>) con su archivo <code>.htaccess</code>, o su carpeta <code>public</code>. Ahora es <code>'
        . h((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) . '</code>. Extrae el zip directamente en la carpeta del subdominio, sin cambiar nada más.</p>');
}

try {
    $installer->ensureInstallKey();
} catch (\VenPosTPV\Service\Install\InstallException $e) {
    page('Instalación del TPV', '<p class="err">' . h($e->getMessage()) . '</p>');
}

$scheme = $isHttps ? 'https' : 'http';
$defaults = [
    'tpv_url' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? ''),
    'db_host' => 'localhost',
    'db_port' => '3306',
    'ps_currency' => '1',
    'ps_shop' => '1',
    'timezone' => 'Europe/Madrid',
];
$form = $defaults;
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (['tpv_url', 'db_host', 'db_port', 'db_name', 'db_user', 'admin_user', 'store_name', 'store_address', 'ps_url', 'ps_api_key', 'sso_secret', 'ps_currency', 'ps_shop', 'timezone'] as $k) {
        $form[$k] = (string) ($_POST[$k] ?? '');
    }

    if (!$installer->checkInstallKey((string) ($_POST['install_key'] ?? ''))) {
        usleep(500000);
        $errors[] = 'La clave de instalación no es correcta. Está en el archivo INSTALL_KEY de la carpeta del subdominio (ábrelo con el administrador de archivos del panel o por FTP).';
    } else {
        $result = $installer->validate($_POST);
        $errors = $result['errors'];
        if ($errors === []) {
            set_time_limit(300);
            $executed = 0;
            try {
                $conn = $installer->connect($result['values']);
                $installer->assertDatabaseUsable($conn);
                $executed = $installer->migrate($conn);
                $installer->finish($result['values']);
            } catch (\Doctrine\DBAL\Exception\ConnectionException $e) {
                $errors[] = 'No se pudo conectar con la base de datos: revisa servidor, puerto, nombre, usuario y contraseña.';
            } catch (\VenPosTPV\Service\Install\InstallException $e) {
                $errors[] = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('TPV installer: ' . $e::class . ': ' . $e->getMessage());
                $errors[] = 'La instalación falló. El detalle está en el registro de errores de PHP (en los registros del subdominio del panel). '
                    . 'Si se crearon tablas, vacía la base de datos antes de volver a intentarlo.';
            }

            if ($errors === []) {
                $deleted = @unlink(__FILE__);
                @unlink($projectDir . '/install.php'); // stub of a root-folder install
                $root = $projectDir;
                // Panels that run "a PHP script" pick the PHP version themselves and,
                // under /var/www/vhosts, take the path relative to the webspace;
                // panels that only run a command get the full cron line.
                $script = preg_match('#^/var/www/vhosts/[^/]+/(.+)$#', $root, $m) === 1 ? $m[1] . '/bin/console' : $root . '/bin/console';
                $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
                $task = static fn (string $when, string $args): string => '<tr><td>' . $when . '</td><td><code>' . h($args) . '</code></td></tr>';
                $tpvUrl = $result['values']['TPV_URL'];
                page('TPV instalado', '<p class="ok">Base de datos preparada (' . (int) $executed . ' migraciones aplicadas) y configuración guardada en <code>.env</code>.</p>'
                    . ($deleted ? '' : '<p class="err">No se pudo borrar <code>public/install.php</code>. Bórralo a mano; de todas formas ya está bloqueado.</p>')
                    . '<h2>1. Tareas programadas</h2><p>Crea cinco tareas programadas (cron) en el panel del hosting y notifica solo los errores. '
                    . 'Si el panel ejecuta un script PHP: ruta <code>' . h($script) . '</code>, PHP <strong>' . h($phpVersion) . '</strong> y como argumentos lo de la tabla. '
                    . 'Si solo admite un comando: <code>php ' . h($root . '/bin/console') . '</code> seguido de los argumentos (en algunos paneles el binario es <code>php' . h($phpVersion) . '</code>).</p>'
                    . '<div class="table"><table><tr><th>Ejecutar</th><th>Con argumentos</th></tr>'
                    . $task('Estilo cron <code>* * * * *</code>', 'tpv:sync:outbox --env=prod')
                    . $task('Estilo cron <code>* * * * *</code>', 'tpv:catalog:sync --stock-only --env=prod')
                    . $task('Diariamente 03:00', 'tpv:catalog:sync --full --env=prod')
                    . $task('Diariamente 03:30', 'tpv:customers:sync --env=prod')
                    . $task('Diariamente 04:00', 'tpv:backup:database --env=prod')
                    . '</table></div><p>En la tarea del catálogo completo ejecútala también una vez a mano para cargar los productos. La última guarda cada día una copia de la base de datos en <code>backups/</code> (las 7 más recientes); descarga de vez en cuando una copia y el archivo <code>.env</code> fuera del servidor.</p>'
                    . '<h2>2. PHP del subdominio</h2><p><code>max_execution_time</code> = 35 como mínimo y <code>pm.max_children</code> ≥ número de cajas + 6.</p>'
                    . '<h2>3. PrestaShop</h2><p>En el módulo <strong>TPV Connect</strong>, pon como URL del TPV <code>' . h($tpvUrl) . '</code>'
                    . ($result['values']['SSO_SHARED_SECRET'] === '' ? ' y copia su secreto SSO en <code>SSO_SHARED_SECRET</code> del archivo <code>.env</code>.' : '.') . '</p>'
                    . '<h2>4. Entrar</h2><p><a href="' . h($tpvUrl) . '/app/login">' . h($tpvUrl) . '/app/login</a> con el usuario administrador que acabas de crear.</p>');
            }
        }
    }
}

$v = static fn (string $k): string => h((string) ($form[$k] ?? ''));
$errHtml = $errors === [] ? '' : '<div class="err"><strong>Revisa esto:</strong><ul><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul></div>';

page('Instalación del TPV', $errHtml . '<form method="post" autocomplete="off">'
    . '<h2>Clave de instalación</h2>'
    . '<label>Clave <small>— está en el archivo <code>INSTALL_KEY</code> de la carpeta del subdominio (administrador de archivos del panel o FTP)</small></label>'
    . '<input name="install_key" required>'
    . '<h2>Base de datos del TPV</h2><p><small>Créala vacía en el panel del hosting. No uses la de PrestaShop.</small></p>'
    . '<div class="row"><div><label>Servidor</label><input name="db_host" value="' . $v('db_host') . '" required></div>'
    . '<div class="narrow"><label>Puerto</label><input name="db_port" value="' . $v('db_port') . '" required></div></div>'
    . '<label>Nombre de la base de datos</label><input name="db_name" value="' . $v('db_name') . '" required>'
    . '<div class="row"><div><label>Usuario</label><input name="db_user" value="' . $v('db_user') . '" required></div>'
    . '<div><label>Contraseña</label><input type="password" name="db_password"></div></div>'
    . '<h2>TPV</h2>'
    . '<label>Dirección del TPV</label><input name="tpv_url" value="' . $v('tpv_url') . '" required>'
    . '<div class="row"><div><label>Usuario administrador</label><input name="admin_user" value="' . $v('admin_user') . '" required></div>'
    . '<div><label>Contraseña <small>(mín. 12)</small></label><input type="password" name="admin_password" minlength="12" required></div></div>'
    . '<label>Nombre de la tienda <small>(sale en los tickets)</small></label><input name="store_name" value="' . $v('store_name') . '" required>'
    . '<label>Dirección de la tienda</label><input name="store_address" value="' . $v('store_address') . '">'
    . '<h2>PrestaShop</h2>'
    . '<label>Dirección de la tienda online</label><input name="ps_url" value="' . $v('ps_url') . '" placeholder="https://tutienda.com" required>'
    . '<label>Clave del webservice <small>— módulo TPV Connect → «Generar clave»</small></label><input name="ps_api_key" value="' . $v('ps_api_key') . '" required>'
    . '<label>Secreto SSO <small>— módulo TPV Connect (opcional, puedes ponerlo luego en .env)</small></label><input name="sso_secret" value="' . $v('sso_secret') . '">'
    . '<div class="row"><div><label>ID de moneda</label><input name="ps_currency" value="' . $v('ps_currency') . '"></div>'
    . '<div><label>ID de tienda</label><input name="ps_shop" value="' . $v('ps_shop') . '"></div>'
    . '<div><label>Zona horaria</label><input name="timezone" value="' . $v('timezone') . '"></div></div>'
    . '<button type="submit">Instalar</button></form>');
