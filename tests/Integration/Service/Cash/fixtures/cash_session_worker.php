<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\RequestStack;
use VenPosTPV\Service\Cash\CashSessionService;
use VenPosTPV\Service\Cash\TerminalService;
use VenPosTPV\Tests\Support\SessionRequest;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

final class MovementInsertBarrierConnection extends Connection
{
    /** @param array<string, mixed> $data @param array<string, mixed> $types */
    public function insert($table, array $data, array $types = []): int
    {
        if ($table === 'movement' && getenv('TPV_CASH_CLOSE_BARRIER') !== false) {
            $barrier = (string) getenv('TPV_CASH_CLOSE_BARRIER');
            file_put_contents($barrier . '/movement-at-insert', 'ready', LOCK_EX);
            $deadline = microtime(true) + 8;
            while (!file_exists($barrier . '/allow-movement') && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if (!file_exists($barrier . '/allow-movement')) {
                throw new \RuntimeException('Movement barrier timed out');
            }
        }

        return parent::insert($table, $data, $types);
    }
}

$mode = $argv[1] ?? '';
$userId = (int) ($argv[2] ?? 0);
$shopId = (int) ($argv[3] ?? 0);
$sessionId = (int) ($argv[4] ?? 0);

try {
    $config = [
        'driver' => 'pdo_mysql',
        'host' => (string) getenv('TPV_DB_HOST'),
        'port' => (int) getenv('TPV_DB_PORT'),
        'dbname' => (string) getenv('TPV_DB_NAME'),
        'user' => (string) getenv('TPV_DB_USER'),
        'password' => (string) getenv('TPV_DB_PASSWORD'),
        'charset' => 'utf8mb4',
    ];
    if ($mode === 'movement') {
        $config['wrapperClass'] = MovementInsertBarrierConnection::class;
    }

    $connection = DriverManager::getConnection($config);
    $requestStack = new RequestStack();
    $requestStack->push(SessionRequest::create([], [
        'shop_id' => $shopId,
        'cash_session_id' => $sessionId,
    ]));
    $service = new CashSessionService($connection, new TerminalService($connection), $requestStack);

    $result = match ($mode) {
        'movement' => $service->addMovement($userId, 'cash_in', 25.0, 'Concurrent cash in'),
        'close' => $service->closeSession($userId, 100.0),
        default => throw new \InvalidArgumentException('Unknown worker mode'),
    };

    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
