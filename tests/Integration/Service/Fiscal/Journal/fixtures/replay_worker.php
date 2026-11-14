<?php

declare(strict_types=1);

/*
 * A replay killed in the middle (fiscal-journal S6): runs `tpv:fiscal:replay --apply`
 * against the restored database and kills itself with SIGKILL right after the
 * given number of documents were written in its transaction.
 */

use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Tests\Integration\Service\Fiscal\Journal\JournalRestore;

require dirname(__DIR__, 6) . '/vendor/autoload.php';

$root = (string) ($argv[1] ?? '');
$database = (string) ($argv[2] ?? '');
$killAfter = (int) ($argv[3] ?? 0);

$connection = JournalRestore::connect($database);
$locations = new FiscalJournalLocations($root . '/backups/fiscal-journal', $root . '/var/fiscal-journal');
$replay = new FiscalReplay($connection, $locations, $root . '/var/fiscal-replay.lock', afterDocument: static function (array $line, int $done) use ($killAfter): void {
    if ($done === $killAfter) {
        echo 'killing after ' . $done . "\n";
        fflush(STDOUT);
        posix_kill(getmypid(), 9); // SIGKILL (the pcntl constants may be missing)
    }
});
$result = $replay->run(true);
echo json_encode(['status' => $result->status, 'message' => $result->message], JSON_THROW_ON_ERROR);
