<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Backup;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\DatabaseHealthCheck;
use VenPosTPV\Service\Fiscal\FiscalChainVerifier;

final class DatabaseHealthCheckTest extends TestCase
{
    /**
     * @param array<string, list<array<string, string>>> $checkRows CHECK TABLE rows per table
     */
    private function check(array $checkRows): DatabaseHealthCheck
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllNumeric')->willReturn(array_map(static fn (string $t): array => [$t, 'BASE TABLE'], array_keys($checkRows)));
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql) use ($checkRows): array {
            if (preg_match('/\ACHECK TABLE `([^`]+)`\z/', $sql, $m) !== 1) {
                throw new \LogicException('Unexpected query ' . $sql);
            }

            return $checkRows[$m[1]];
        });

        // No fiscal_record table in this list, so the chain verifier is never run.
        return new DatabaseHealthCheck($connection, new FiscalChainVerifier($connection));
    }

    public function testHealthyTablesHaveNoProblems(): void
    {
        $report = $this->check([
            'order_meta' => [['Table' => 'db.order_meta', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'OK']],
            'terminal' => [
                ['Table' => 'db.terminal', 'Op' => 'check', 'Msg_type' => 'note', 'Msg_text' => 'The storage engine for the table doesn\'t support check'],
                ['Table' => 'db.terminal', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'Table is already up to date'],
            ],
        ])->run();

        $this->assertSame([], $report->problems);
        $this->assertTrue($report->ok());
        $this->assertSame(2, $report->tables);
        $this->assertNull($report->fiscalRecords);
    }

    public function testACheckTableErrorOrABadStatusIsAProblem(): void
    {
        $report = $this->check([
            'order_meta' => [
                ['Table' => 'db.order_meta', 'Op' => 'check', 'Msg_type' => 'error', 'Msg_text' => 'Index for table \'order_meta\' is corrupt'],
                ['Table' => 'db.order_meta', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'Corrupt'],
            ],
            'terminal' => [['Table' => 'db.terminal', 'Op' => 'check', 'Msg_type' => 'status', 'Msg_text' => 'OK']],
        ])->run();

        $this->assertFalse($report->ok());
        $this->assertSame([
            "Table order_meta: Index for table 'order_meta' is corrupt",
            'Table order_meta: Corrupt',
        ], $report->problems);
    }
}
