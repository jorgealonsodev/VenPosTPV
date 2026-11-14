<?php

declare(strict_types=1);

/*
 * A local stand-in for the shop, run with `php -S 127.0.0.1:<port>` by the installer
 * tests, so the real installer page and the installed app talk HTTP to it and never
 * to a real shop. Its state lives in the folder FAKE_SHOP_DIR names:
 * - `mode`: `module` (default) answers the journal with the module's own JournalStore
 *   (../../Service/Fiscal/Journal/fixtures/fake_journal_module.php, table in
 *   `store.json`, signed with `secret`); `old` answers 404 like a module older than 0.9.0;
 * - `pair.json`: the answer to POST /module/tpvconnect/pair;
 * - `requests.log`: one line per request (its path and action).
 */

$dir = (string) getenv('FAKE_SHOP_DIR');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$data = json_decode($body, true);
file_put_contents($dir . '/requests.log', $path . ' ' . (is_array($data) ? (string) ($data['action'] ?? '') : '') . "\n", FILE_APPEND | LOCK_EX);
$mode = is_file($dir . '/mode') ? trim((string) file_get_contents($dir . '/mode')) : 'module';

if ($path === '/module/tpvconnect/pair' && is_file($dir . '/pair.json')) {
    header('Content-Type: application/json');
    echo file_get_contents($dir . '/pair.json');

    return true;
}
if ($path === '/module/tpvconnect/journal' && $mode === 'module') {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../../Service/Fiscal/Journal/fixtures/fake_journal_module.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode([
        'store' => $dir . '/store.json',
        'secret' => trim((string) file_get_contents($dir . '/secret')),
        'body' => $body,
        'auth' => (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
    ], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $response = json_decode($out, true);
    http_response_code(is_array($response) ? (int) $response['status'] : 500);
    header('Content-Type: application/json');
    echo is_array($response) ? (string) $response['body'] : '{"error":"fake module failed"}';

    return true;
}

http_response_code(404);
header('Content-Type: text/html');
echo '<!doctype html><title>Página no encontrada</title>';

return true;
