<?php

declare(strict_types=1);

/*
 * Takes ticket numbers for one terminal in separate transactions, rolling back every
 * Nth one, and prints the committed numbers as JSON. Several of these run at once to
 * prove the series stays unique and gapless under concurrency.
 */

use Doctrine\DBAL\DriverManager;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$terminalId = (int) ($argv[1] ?? 0);
$date = new \DateTimeImmutable((string) ($argv[2] ?? 'now'));
$count = (int) ($argv[3] ?? 0);
$rollbackEvery = (int) ($argv[4] ?? 0);

try {
    // Connect directly: TestDatabase::connection() would also run the schema setup,
    // which must not race between workers.
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
    $numberer = new TicketNumberer($connection);

    $numbers = [];
    for ($i = 1; $i <= $count; ++$i) {
        $numberer->prepareYear($terminalId, $date);
        $connection->beginTransaction();
        $number = $numberer->next($terminalId, $date);
        usleep(random_int(0, 3000));
        if ($rollbackEvery > 0 && $i % $rollbackEvery === 0) {
            $connection->rollBack();
            continue;
        }
        $connection->commit();
        $numbers[] = $number;
    }

    echo json_encode(['ok' => true, 'numbers' => $numbers], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
