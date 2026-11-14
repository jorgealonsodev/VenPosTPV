<?php

declare(strict_types=1);

/*
 * A replay interrupted in the middle (fiscal-journal S6): runs `tpv:fiscal:replay
 * --apply` against the restored database and, right after the given number of
 * documents were written in its transaction, either kills itself with SIGKILL
 * (mode «kill») or touches <root>/paused and sleeps the given seconds (mode
 * «pause») before going on. Prints the result as JSON.
 *
 * Arguments: <journal root> <database> <after> [kill|pause] [seconds]
 */

use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalReplay;
use VenPosTPV\Tests\Integration\Service\Fiscal\Journal\JournalRestore;

require dirname(__DIR__, 6) . '/vendor/autoload.php';

$root = (string) ($argv[1] ?? '');
$database = (string) ($argv[2] ?? '');
$after = (int) ($argv[3] ?? 0);
$mode = (string) ($argv[4] ?? 'kill');
$seconds = (int) ($argv[5] ?? 0);

$connection = JournalRestore::connect($database);
$locations = new FiscalJournalLocations($root . '/backups/fiscal-journal', $root . '/var/fiscal-journal');
$replay = new FiscalReplay($connection, $locations, $root . '/var/fiscal-replay.lock', afterDocument: static function (array $line, int $done) use ($after, $mode, $seconds, $root): void {
    if ($done !== $after) {
        return;
    }
    if ($mode === 'kill') {
        echo 'killing after ' . $done . "\n";
        fflush(STDOUT);
        posix_kill(getmypid(), 9); // SIGKILL (the pcntl constants may be missing)
    }
    touch($root . '/paused');
    sleep($seconds);
});
$result = $replay->run(true);
echo json_encode(['status' => $result->status, 'message' => $result->message], JSON_THROW_ON_ERROR);
