<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Label;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\LabelCatalog;
use VenPosTPV\Service\Label\LabelEscPosRenderer;
use VenPosTPV\Service\Label\LabelPrintService;
use VenPosTPV\Service\Label\LabelSettingsStore;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Tests\Integration\Service\PrintAgent\PrintAgentFixtures;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * AC14 / T7 / L6 (amended: strips of 10, each with its cut): a sale during a
 * 200-label batch prints before the remaining labels.
 */
final class LabelBatchInterruptMySqlIntegrationTest extends TestCase
{
    use PrintAgentFixtures;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->cleanPrintAgentFixtures($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->cleanPrintAgentFixtures($this->connection);
            $this->connection->close();
        }
    }

    public function testATicketCanInterruptA200LabelBatch(): void
    {
        $db = $this->connection;
        \assert($db instanceof Connection);
        $till = $this->till($db, 'AC14');
        $queue = new PrintJobQueue($db);
        $service = new LabelPrintService($db, $this->createMock(LabelCatalog::class), new LabelEscPosRenderer(), new LabelSettingsStore($db), $queue, new AgentRegistry($db, 's'));
        $send = new \ReflectionMethod($service, 'send');
        $result = $send->invoke($service, $till, $service->printerConfig($till), array_fill(0, 200, LabelPrintService::testLabel()), 'label', 1, false);
        $this->assertCount(20, $result['jobs'], '200 labels: 20 strips of 10');

        $first = $queue->claim($till);
        $this->assertSame(['label'], array_column($first, 'type'), 'one label job per poll');
        $strip = base64_decode($first[0]['payload_b64'], true);
        // One code-zone raster (GS v 0, 576 dots) per label; the 2-row ones are the dotted lines.
        $this->assertSame(10, preg_match_all('/\x1Dv0\x00\x48\x00(?!\x02\x00)/', (string) $strip), 'the first strip holds 10 labels');
        $this->assertSame(1, substr_count((string) $strip, "\x1dV\x41\x00"), 'with its own cut');

        $sale = $queue->enqueue($till, 'ticket', 'SALE', 'sale', '1');
        $next = $queue->claim($till);

        $this->assertSame($sale, $next[0]['id'], 'the ticket comes before the remaining 190 labels');
        $this->assertSame(['ticket', 'label'], array_column($next, 'type'));
        $this->assertSame(18, (int) $db->fetchOne("SELECT COUNT(*) FROM print_job WHERE terminal_id = ? AND type = 'label' AND status = 'pending'", [$till]));
    }
}
