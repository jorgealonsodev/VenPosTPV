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
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$projectDir = dirname(__DIR__);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . '</title><style>'
        . 'body{font:15px/1.5 system-ui,sans-serif;background:#f4f5f7;color:#1d2330;margin:0;padding:24px 16px}'
        . 'main{max-width:680px;margin:0 auto;background:#fff;border-radius:10px;padding:24px 28px;box-shadow:0 1px 3px #0002}'
        . 'h1{font-size:22px;margin:0 0 16px}h2{font-size:16px;margin:24px 0 8px;border-bottom:1px solid #e3e5ea;padding-bottom:4px}'
        . 'label{display:block;margin:10px 0 2px;font-weight:600}small{color:#5b6475;font-weight:400}'
        . 'input,select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #c7ccd6;border-radius:6px;font:inherit}'
        . '.row{display:flex;gap:12px}.row>div{flex:1}'
        . 'button{margin-top:20px;padding:10px 18px;border:0;border-radius:6px;background:#1f6feb;color:#fff;font:inherit;font-weight:600;cursor:pointer}'
        . '.err{background:#fdecea;border:1px solid #f5c2bd;color:#8a1c12;padding:10px 14px;border-radius:6px}'
        . '.ok{background:#e8f6ec;border:1px solid #b9e2c4;color:#165c2b;padding:10px 14px;border-radius:6px}'
        . 'code,pre{background:#f0f2f5;border-radius:4px;padding:2px 5px;font-size:13px}pre{padding:10px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}'
        . '</style></head><body><main><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

if (PHP_VERSION_ID < 80400) {
    page('Instalación del TPV', '<p class="err">Este TPV necesita PHP 8.4 o superior y el servidor usa PHP ' . h(PHP_VERSION)
        . '. En Plesk: <em>Sitios web y dominios → el subdominio → Configuración de PHP</em> y elige 8.4.</p>');
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
    page('Instalación del TPV', '<p class="err">Abre el instalador con <strong>https://</strong>. Las contraseñas no deben viajar sin cifrar. En Plesk: <em>Certificados SSL/TLS</em> → Let\'s Encrypt para el subdominio.</p>');
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
        $errors[] = 'La clave de instalación no es correcta. Está en el archivo INSTALL_KEY de la carpeta del subdominio (ábrelo con el Administrador de archivos de Plesk).';
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
                $errors[] = 'La instalación falló. El detalle está en el registro de errores de PHP (Plesk: Registros del subdominio). '
                    . 'Si se crearon tablas, vacía la base de datos antes de volver a intentarlo.';
            }

            if ($errors === []) {
                $deleted = @unlink(__FILE__);
                $root = $projectDir;
                $php = '/opt/plesk/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '/bin/php';
                $cron = "cd {$root} && {$php} bin/console tpv:sync:outbox --env=prod\n"
                    . "cd {$root} && {$php} bin/console tpv:catalog:sync --stock-only --env=prod";
                $nightly = "cd {$root} && {$php} bin/console tpv:catalog:sync --full --env=prod\n"
                    . "cd {$root} && {$php} bin/console tpv:customers:sync --env=prod";
                $tpvUrl = $result['values']['TPV_URL'];
                page('TPV instalado', '<p class="ok">Base de datos preparada (' . (int) $executed . ' migraciones aplicadas) y configuración guardada en <code>.env</code>.</p>'
                    . ($deleted ? '' : '<p class="err">No se pudo borrar <code>public/install.php</code>. Bórralo a mano; de todas formas ya está bloqueado.</p>')
                    . '<h2>1. Tareas programadas</h2><p>En Plesk: <em>Tareas programadas → Añadir tarea → Ejecutar un comando</em>.</p>'
                    . '<p><strong>Cada minuto</strong> (<code>* * * * *</code>), una tarea por línea:</p><pre>' . h($cron) . '</pre>'
                    . '<p><strong>Cada noche</strong> (<code>0 3 * * *</code>). Ejecuta también ahora la primera una vez con «Ejecutar ahora» para cargar el catálogo:</p><pre>' . h($nightly) . '</pre>'
                    . '<p><small>Si tu PHP no está en esa ruta, cámbiala por la que muestra Plesk para PHP ' . h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) . '.</small></p>'
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
    . '<label>Clave <small>— está en el archivo <code>INSTALL_KEY</code> de la carpeta del subdominio (Administrador de archivos de Plesk)</small></label>'
    . '<input name="install_key" required>'
    . '<h2>Base de datos del TPV</h2><p><small>Créala vacía en Plesk (<em>Bases de datos → Añadir base de datos</em>). No uses la de PrestaShop.</small></p>'
    . '<div class="row"><div><label>Servidor</label><input name="db_host" value="' . $v('db_host') . '" required></div>'
    . '<div style="flex:0 0 110px"><label>Puerto</label><input name="db_port" value="' . $v('db_port') . '" required></div></div>'
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
