<?php

declare(strict_types=1);

/*
 * One competing process for PrintAgentConcurrencyMySqlIntegrationTest. Waits for
 * the start file, then either claims every job it can for a till ("claim") or tries
 * to pair with a code ("pair"), and prints JSON on stdout.
 *
 * argv: mode, start file, till id (claim) or code (pair).
 */

use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\PairingCodeInvalidException;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

[, $mode, $startFile, $argument] = $argv;
$connection = TestDatabase::connection();
if ($connection === null) {
    fwrite(STDERR, "no database\n");
    exit(2);
}

$deadline = microtime(true) + 10;
while (!is_file($startFile)) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(1000);
}

if ($mode === 'claim') {
    $queue = new PrintJobQueue($connection);
    $ids = [];
    $empty = 0;
    while ($empty < 3) {
        $jobs = $queue->claim((int) $argument);
        if ($jobs === []) {
            ++$empty;
            usleep(2000);
            continue;
        }
        $empty = 0;
        foreach ($jobs as $job) {
            $ids[] = $job['id'];
        }
    }
    echo json_encode(['ids' => $ids], JSON_THROW_ON_ERROR);
    exit(0);
}

$registry = new AgentRegistry($connection, 'concurrency-test-secret');
try {
    $paired = $registry->pair($argument, 'host-' . getmypid(), 'linux', '1.0.0', '127.0.0.1');
    echo json_encode(['paired' => true, 'key_hash' => hash('sha256', $paired['key'])], JSON_THROW_ON_ERROR);
} catch (PairingCodeInvalidException) {
    echo json_encode(['paired' => false], JSON_THROW_ON_ERROR);
}
