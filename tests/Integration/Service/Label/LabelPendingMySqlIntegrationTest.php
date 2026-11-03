<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Label;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\ProductSearchService;
use VenPosTPV\Service\Label\LabelCatalog;
use VenPosTPV\Service\Label\LabelEscPosRenderer;
use VenPosTPV\Service\Label\LabelPrintService;
use VenPosTPV\Service\Label\LabelSettingsStore;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Tests\Integration\Service\PrintAgent\PrintAgentFixtures;
use VenPosTPV\Tests\Support\TestDatabase;

/**
 * docs/prd/agente-impresion.md L9, T7, AC14 on MySQL: labels printed while the agent
 * is away are held (the agent cannot claim them) until the till answers
 * "¿Imprimirlas?"; "Imprimir" lets the agent claim them, "Descartar" cancels them.
 * A ticket still goes before a label job.
 */
final class LabelPendingMySqlIntegrationTest extends TestCase
{
    use PrintAgentFixtures;

    private const PRODUCT = 937101;

    private ?Connection $connection = null;
    private PrintJobQueue $queue;
    private int $till = 0;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::connection();
        if ($this->connection === null) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $this->clean();
        $this->till = $this->till($this->connection, 'Caja etiquetas');
        $this->connection->insert('catalog_product', ['id' => self::PRODUCT, 'name' => 'Camiseta etiqueta', 'reference' => 'CAM-ET', 'ean13' => '8412345678905', 'price' => 19.12, 'price_ttc' => 23.14, 'active' => 1]);
        $this->queue = new PrintJobQueue($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->clean();
            $this->connection->close();
        }
    }

    private function clean(): void
    {
        \assert($this->connection !== null);
        $this->cleanPrintAgentFixtures($this->connection);
        $this->connection->executeStatement('DELETE FROM catalog_product WHERE id = ?', [self::PRODUCT]);
    }

    private function service(): LabelPrintService
    {
        \assert($this->connection !== null);

        return new LabelPrintService(
            $this->connection,
            new LabelCatalog(new ProductSearchService($this->connection), $this->connection),
            new LabelEscPosRenderer(),
            new LabelSettingsStore($this->connection),
            $this->queue,
            new AgentRegistry($this->connection, 'label-test-secret'),
        );
    }

    public function testLabelsPrintedWithTheAgentAwayWaitForTheAnswer(): void
    {
        $result = $this->service()->print($this->till, [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 7]], null);

        self::assertSame('held', $result['print_status']);
        self::assertSame([], $this->queue->claim($this->till), 'the agent cannot claim held labels');
        self::assertSame(['jobs' => 1, 'labels' => 7], $this->service()->pending($this->till));

        self::assertSame(7, $this->service()->releasePending($this->till));
        self::assertSame(['jobs' => 0, 'labels' => 0], $this->service()->pending($this->till));
        $claimed = $this->queue->claim($this->till);
        self::assertCount(1, $claimed);
        self::assertSame(['label', 'label'], [$claimed[0]['type'], $claimed[0]['target']]);
        self::assertSame(7, substr_count((string) base64_decode($claimed[0]['payload_b64'], true), "\x1Dk\x43\x0C"));
    }

    public function testDiscardedLabelsAreCancelledAndNeverPrinted(): void
    {
        $this->service()->print($this->till, [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 3]], null);

        self::assertSame(3, $this->service()->discardPending($this->till));
        self::assertSame([], $this->queue->claim($this->till));
        self::assertSame('cancelled', $this->connection?->fetchOne('SELECT status FROM print_job WHERE terminal_id = ?', [$this->till]));
    }

    public function testATicketGoesBeforeTheLabels(): void
    {
        $this->service()->print($this->till, [['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 100], ['product_id' => self::PRODUCT, 'id_product_attribute' => 0, 'quantity' => 100]], null);
        $this->service()->releasePending($this->till);
        $this->queue->enqueue($this->till, 'ticket', "\x1B@ticket", 'sale', '-1');

        $types = array_column($this->queue->claim($this->till), 'type');

        self::assertSame(['ticket', 'label'], $types);
    }

    public function testTheLabelSettingsOfTheTillAreStored(): void
    {
        \assert($this->connection !== null);
        $store = new LabelSettingsStore($this->connection);
        self::assertTrue($store->isAvailable());
        self::assertSame([30, false], [$store->forTerminal($this->till)->heightMm, $store->forTerminal($this->till)->cutEach]);

        $this->connection->update('terminal', ['label_height_mm' => 45, 'label_cut_each' => 1], ['id' => $this->till]);
        self::assertSame([45, true], [$store->forTerminal($this->till)->heightMm, $store->forTerminal($this->till)->cutEach]);
    }
}
