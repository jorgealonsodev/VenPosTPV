<?php

declare(strict_types=1);

/*
 * One cron-like OutboxSync run against the test database with a fake module: every
 * upload appends its reference to the log file given as argument. Several of these
 * run at once to prove no row is uploaded twice.
 */

use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpClient\MockHttpClient;
use VenPosTPV\Service\Checkout\PsOrderClient;
use VenPosTPV\Service\Sync\OutboxSync;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$log = (string) ($argv[1] ?? '');

try {
    // Connect directly: TestDatabase::connection() would also run the schema setup.
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
    $client = new class ($log) extends PsOrderClient {
        public function __construct(private readonly string $log)
        {
            parent::__construct('http://localhost', 'secret', new MockHttpClient());
        }

        public function createReferencedOrder(array $payload): array
        {
            usleep(random_int(1000, 20000));
            $reference = (string) $payload['reference'];
            file_put_contents($this->log, $reference . "\n", FILE_APPEND | LOCK_EX);

            return ['order_id' => 880000 + (int) substr($reference, -4), 'reference' => $reference, 'total_paid' => 10.0, 'replayed' => false];
        }
    };

    $counts = (new OutboxSync($connection, $client))->run();
    echo json_encode(['ok' => true, 'counts' => $counts], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
