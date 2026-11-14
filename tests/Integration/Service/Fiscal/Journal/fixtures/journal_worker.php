<?php

declare(strict_types=1);

/*
 * One till of the concurrency test: issues tickets one after another, each in its
 * own transaction with the fiscal journal intent, and flushes the journal after
 * every commit as the after-response listener does. Prints the numbers as JSON.
 */

use Doctrine\DBAL\DriverManager;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Tests\Integration\Service\Fiscal\Journal\JournalIssue;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 6) . '/vendor/autoload.php';

$root = (string) ($argv[1] ?? '');
$count = (int) ($argv[2] ?? 0);
$terminalId = (int) ($argv[3] ?? 0);
$sessionId = (int) ($argv[4] ?? 0);

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
    $locations = new FiscalJournalLocations($root . '/backups/fiscal-journal', $root . '/var/fiscal-journal');
    $intents = new FiscalJournalIntents($locations);
    $writer = new FiscalJournalWriter($connection, $locations, $intents);

    $numbers = [];
    for ($i = 1; $i <= $count; ++$i) {
        $numbers[] = JournalIssue::ticket($connection, $intents, $terminalId, $sessionId);
        usleep(random_int(0, 2000));
        $writer->flushAfterIssue();
    }

    echo json_encode(['ok' => true, 'numbers' => $numbers], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
