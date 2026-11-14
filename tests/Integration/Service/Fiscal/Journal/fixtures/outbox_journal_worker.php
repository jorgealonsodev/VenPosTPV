<?php

declare(strict_types=1);

/*
 * One uploader of the concurrent outbox test: runs OutboxSync in a loop, as several
 * tpv:sync:outbox processes and after-response uploads do at once, until no journal
 * row is waiting. The shop is the module's own JournalStore in this process, on a
 * JSON file under flock (one request at a time across processes, like the module's
 * table), and every request is logged as "pid seq status". Never contacts a shop.
 *
 * argv: store file, request log, seconds at most. Exit code 1 on any uncaught error.
 */

use Doctrine\DBAL\DriverManager;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\PrestaShop\PsJournalClient;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 6) . '/vendor/autoload.php';
require dirname(__DIR__, 6) . '/modules/tpvconnect/tests/bootstrap.php';
require_once dirname(__DIR__, 6) . '/modules/tpvconnect/classes/JournalStore.php';
require_once __DIR__ . '/locked_journal_storage.php';

[, $store, $log, $seconds] = $argv;
$secret = 'journal-test-secret';

try {
    $settings = TestDatabase::settings();
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => $settings['host'],
        'port' => $settings['port'],
        'dbname' => $settings['name'],
        'user' => $settings['user'],
        'password' => $settings['password'],
        'charset' => 'utf8mb4',
    ]);
    $client = new PsJournalClient('https://tienda.tutienda.com', $secret, new MockHttpClient(static function (string $method, string $url, array $options) use ($store, $log, $secret): MockResponse {
        $auth = '';
        foreach ($options['headers'] as $header) {
            if (str_starts_with($header, 'Authorization: ')) {
                $auth = substr($header, \strlen('Authorization: '));
            }
        }
        [$status, $body] = LockedFileJournalStorage::respond($store, $log, (string) $options['body'], $auth, $secret);

        return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['Content-Type: application/json']]);
    }));
    $logger = new class extends AbstractLogger {
        public function log($level, \Stringable|string $message, array $context = []): void
        {
            fwrite(STDERR, getmypid() . ' ' . $level . ' ' . $message . ' ' . json_encode($context) . "\n");
        }
    };
    $orders = (new ReflectionClass(PsOrderClient::class))->newInstanceWithoutConstructor();
    $sync = new OutboxSync($connection, $orders, $logger, null, journalClient: $client);
    $deadline = microtime(true) + (float) $seconds;
    while (microtime(true) < $deadline) {
        $sync->run(random_int(1, 5));
        usleep(random_int(0, 5000));
        if ((int) $connection->fetchOne("SELECT COUNT(*) FROM outbox WHERE type = 'journal' AND status IN ('pending', 'processing')") === 0) {
            break;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'UNCAUGHT ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
