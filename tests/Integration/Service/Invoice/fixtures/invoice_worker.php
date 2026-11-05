<?php

declare(strict_types=1);

/*
 * Issues invoice numbers with their fiscal record in separate transactions, the lock
 * order of a real invoice (counter, then fiscal chain), and prints the committed
 * numbers as JSON. Two of these run at once (factura-completa.md criterion 7).
 */

use Doctrine\DBAL\DriverManager;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Invoice\InvoiceNumberer;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$terminalId = (int) ($argv[1] ?? 0);
$date = new \DateTimeImmutable((string) ($argv[2] ?? 'now'));
$count = (int) ($argv[3] ?? 0);
$invoiceIdBase = (int) ($argv[4] ?? 0);

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
    $numberer = new InvoiceNumberer($connection);
    $fiscal = new FiscalRecorder($connection);

    $numbers = [];
    for ($i = 1; $i <= $count; ++$i) {
        $numberer->prepareYear($date);
        $numbers[] = $connection->transactional(function () use ($numberer, $fiscal, $date, $terminalId, $invoiceIdBase, $i): string {
            $number = $numberer->next($date);
            usleep(random_int(0, 2000));
            $fiscal->recordInvoice($invoiceIdBase + $i, FiscalRecorder::INVOICE_REPLACING_TICKET, $number['number'], $terminalId, 12.10, 2.10, $date);

            return $number['number'];
        });
    }

    echo json_encode(['ok' => true, 'numbers' => $numbers], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
