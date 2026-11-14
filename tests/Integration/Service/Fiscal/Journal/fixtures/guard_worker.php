<?php

declare(strict_types=1);

/*
 * One till of the guard's no-false-block test (fiscal-journal S5): before each
 * ticket it asks the guard as the request listener does (no transaction), then
 * again inside the issuing transaction under the chain lock, after a plain read
 * that fixes the transaction's REPEATABLE READ snapshot and a short wait in which
 * the other tills commit and journal their tickets. Every ticket is flushed after
 * its commit, as the after-response listener does. Prints the counts as JSON.
 */

use Doctrine\DBAL\DriverManager;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalLocations;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalWriter;
use VenPosTPV\Service\Sale\TicketNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 6) . '/vendor/autoload.php';

$root = (string) ($argv[1] ?? '');
$count = (int) ($argv[2] ?? 0);
$terminalId = (int) ($argv[3] ?? 0);
$sessionId = (int) ($argv[4] ?? 0);

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
    $locations = new FiscalJournalLocations($root . '/backups/fiscal-journal', $root . '/var/fiscal-journal');
    $intents = new FiscalJournalIntents($locations);
    $writer = new FiscalJournalWriter($connection, $locations, $intents);
    $guard = new FiscalJournalGuard($connection, $locations);
    $numberer = new TicketNumberer($connection);
    $recorder = new FiscalRecorder($connection, journal: $intents);

    $sold = 0;
    $refused = [];
    for ($i = 1; $i <= $count; ++$i) {
        $date = new \DateTimeImmutable();
        try {
            $guard->assertCanIssue();
        } catch (FiscalJournalBehindException $e) {
            $refused[] = 'early:' . $e->missingNumber;

            continue;
        }
        $numberer->prepareYear($terminalId, $date);
        $connection->beginTransaction();
        try {
            $connection->fetchOne('SELECT COUNT(*) FROM order_meta');
            usleep(random_int(0, 3000));
            $connection->fetchOne('SELECT last_hash FROM fiscal_chain WHERE id = 1 FOR UPDATE');
            try {
                $guard->assertCanIssue();
            } catch (FiscalJournalBehindException $e) {
                $refused[] = 'locked:' . $e->missingNumber;
                $connection->rollBack();

                continue;
            }
            $reference = $numberer->next($terminalId, $date);
            $connection->insert('order_meta', [
                'reference' => $reference,
                'terminal_id' => $terminalId,
                'session_id' => $sessionId,
                'user_id' => 1,
                'payment_breakdown' => json_encode(['reference' => $reference, 'total' => 12.10, 'tax' => 2.10], JSON_THROW_ON_ERROR),
                'printed' => 0,
                'synced' => 0,
                'created_at' => $date->format('Y-m-d H:i:s'),
            ]);
            $recorder->recordSale((int) $connection->lastInsertId(), $terminalId, $reference, 12.10, 2.10, $date);
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
        ++$sold;
        $writer->flushAfterIssue();
    }

    echo json_encode(['ok' => true, 'sold' => $sold, 'refused' => $refused], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
