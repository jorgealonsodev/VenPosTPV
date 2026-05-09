<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Sync;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Sync\SyncLogRepository;

final class SyncLogRepositoryTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    public function testUpsertExecutesInsertOnDuplicateKeyUpdate(): void
    {
        $conn = $this->createMockConnection();
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO `sync_log`'),
                $this->callback(function (array $params) {
                    return $params[0] === 'catalog'
                        && $params[1] === 'inbound'
                        && $params[2] === '2026-05-05 12:00:00'
                        && $params[3] === 42;
                })
            );

        $repo = new SyncLogRepository($conn);
        $repo->upsert('catalog', 'inbound', new \DateTimeImmutable('2026-05-05 12:00:00'), 42);
    }

    public function testUpsertAllowsNullRowCount(): void
    {
        $conn = $this->createMockConnection();
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->callback(function (array $params) {
                    return $params[3] === null;
                })
            );

        $repo = new SyncLogRepository($conn);
        $repo->upsert('stock', 'outbound', new \DateTimeImmutable('2026-05-05 12:00:00'), null);
    }

    public function testGetLastSyncByDirectionReturnsEntryWhenFound(): void
    {
        $conn = $this->createMockConnection();
        $conn->method('executeQuery')
            ->willReturn($this->createMock(\Doctrine\DBAL\Result::class));

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')
            ->willReturn([
                'id' => '1',
                'type' => 'catalog',
                'direction' => 'inbound',
                'synced_at' => '2026-05-05 12:00:00',
                'row_count' => '42',
                'created_at' => '2026-05-05 12:00:00',
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')
            ->with(
                $this->stringContains('SELECT'),
                ['inbound']
            )
            ->willReturn($result);

        $repo = new SyncLogRepository($conn);
        $entry = $repo->getLastSyncByDirection('inbound');

        $this->assertNotNull($entry);
        $this->assertSame('catalog', $entry->type);
        $this->assertSame('inbound', $entry->direction);
        $this->assertSame(42, $entry->rowCount);
    }

    public function testGetLastSyncByDirectionReturnsNullWhenEmpty(): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $repo = new SyncLogRepository($conn);
        $entry = $repo->getLastSyncByDirection('inbound');

        $this->assertNull($entry);
    }

    public function testGetLastSyncsByDirectionReturnsEntriesKeyedByType(): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => '1',
                    'type' => 'catalog',
                    'direction' => 'inbound',
                    'synced_at' => '2026-05-05 12:00:00',
                    'row_count' => '42',
                    'created_at' => '2026-05-05 12:00:00',
                ],
                [
                    'id' => '2',
                    'type' => 'stock',
                    'direction' => 'inbound',
                    'synced_at' => '2026-05-05 11:00:00',
                    'row_count' => '100',
                    'created_at' => '2026-05-05 11:00:00',
                ],
            ]);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')
            ->with(
                $this->stringContains('SELECT'),
                ['inbound']
            )
            ->willReturn($result);

        $repo = new SyncLogRepository($conn);
        $entries = $repo->getLastSyncsByDirection('inbound');

        $this->assertCount(2, $entries);
        $this->assertArrayHasKey('catalog', $entries);
        $this->assertArrayHasKey('stock', $entries);
        $this->assertSame(42, $entries['catalog']->rowCount);
        $this->assertSame(100, $entries['stock']->rowCount);
    }

    public function testGetLastSyncsByDirectionReturnsEmptyArrayWhenNoRows(): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $repo = new SyncLogRepository($conn);
        $entries = $repo->getLastSyncsByDirection('inbound');

        $this->assertSame([], $entries);
    }
}
