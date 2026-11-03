<?php

declare(strict_types=1);

/*
 * One till creating customers against the test database: argv[1] is the email
 * domain, argv[2] how many (email i@domain). Several of these run at once to prove
 * the same email is stored once.
 */

use Doctrine\DBAL\DriverManager;
use VenPosTPV\Service\Customer\CustomerDirectory;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Tests\Support\TestDatabase;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$domain = (string) ($argv[1] ?? '');
$count = (int) ($argv[2] ?? 0);

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
    $directory = new CustomerDirectory($connection, new Outbox($connection));
    $existing = 0;
    for ($i = 0; $i < $count; ++$i) {
        $existing += $directory->create(['firstname' => 'Eva', 'lastname' => 'Carrera', 'email' => $i . $domain])['existing'] ? 1 : 0;
    }
    echo json_encode(['ok' => true, 'existing' => $existing], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
