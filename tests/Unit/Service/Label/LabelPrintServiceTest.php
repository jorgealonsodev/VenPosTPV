<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Label\BarcodeSelector;
use VenPosTPV\Service\Label\Label;
use VenPosTPV\Service\Label\LabelCatalog;
use VenPosTPV\Service\Label\LabelEscPosRenderer;
use VenPosTPV\Service\Label\LabelException;
use VenPosTPV\Service\Label\LabelPrintException;
use VenPosTPV\Service\Label\LabelPrintService;
use VenPosTPV\Service\Label\LabelSettings;
use VenPosTPV\Service\Label\LabelSettingsStore;
use VenPosTPV\Service\Print\PrinterConfig;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;

/**
 * docs/prd/agente-impresion.md L8, L9, T2, T7, T8, AC5: a till with the print agent
 * gets `label` jobs (target `label`) of at most 10 labels, one strip with its cut
 * each (L6, amended), at most 100 per line;
 * other tills print straight to their printer, like tickets. When the agent is not
 * connected the labels are held until the till answers "¿Imprimirlas?".
 */
final class LabelPrintServiceTest extends TestCase
{
    private const AGENT = '{"enabled":true,"type":"agent"}';
    private const SYSTEM = '{"enabled":true,"type":"system","queue":"Etiquetas_TM-T20"}';
    private const PDF = '{"enabled":false,"type":"disabled"}';

    /** @var list<array<int, mixed>> */
    private array $enqueued = [];
    /** @var list<string> */
    private array $statements = [];
    /** @var list<string> */
    private array $sent = [];

    private function service(string $printerConfig, bool $connected = true, ?LabelSettings $settings = null): LabelPrintService
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn($printerConfig);
        $conn->method('transactional')->willReturnCallback(static fn (\Closure $fn): mixed => $fn($conn));
        $conn->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->statements[] = $sql;

            return 1;
        });

        $queue = $this->createMock(PrintJobQueue::class);
        $queue->method('enqueue')->willReturnCallback(function (...$args): int {
            $this->enqueued[] = $args;

            return 500 + \count($this->enqueued);
        });

        $agents = $this->createMock(AgentRegistry::class);
        $agents->method('statusOf')->willReturn(['paired' => true, 'connected' => $connected]);

        $catalog = $this->createMock(LabelCatalog::class);
        $catalog->method('label')->willReturnCallback(static function (int $productId, int $attributeId): Label {
            if ($productId === 8) {
                throw new LabelException('Sin EAN ni referencia: no se puede imprimir la etiqueta');
            }

            return new Label('Camiseta', 'M · Azul', 23.14, (new BarcodeSelector())->select('8412345678905', null, null));
        });

        $store = $this->createMock(LabelSettingsStore::class);
        $store->method('forTerminal')->willReturn($settings ?? LabelSettings::defaults());

        $sent = &$this->sent;

        return new class ($conn, $catalog, new LabelEscPosRenderer(), $store, $queue, $agents, $sent) extends LabelPrintService {
            /** @param list<string> $sent */
            public function __construct(Connection $c, LabelCatalog $l, LabelEscPosRenderer $r, LabelSettingsStore $s, PrintJobQueue $q, AgentRegistry $a, private array &$sent)
            {
                parent::__construct($c, $l, $r, $s, $q, $a);
            }

            protected function sendRaw(PrinterConfig $config, string $bytes): void
            {
                $this->sent[] = $config->queue . ':' . $bytes;
            }
        };
    }

    /** @return list<array{product_id: int, id_product_attribute: int, quantity: int}> */
    private static function items(int ...$quantities): array
    {
        return array_map(static fn (int $q): array => ['product_id' => 7, 'id_product_attribute' => 71, 'quantity' => $q], $quantities);
    }

    public function testAnAgentTillGetsOneLabelJobForTenLabelsInOneStrip(): void
    {
        $result = $this->service(self::AGENT)->print(3, self::items(10), 12);

        self::assertCount(1, $this->enqueued);
        [$terminal, $type, $payload, $source, $sourceRef, $user] = $this->enqueued[0];
        self::assertSame([3, 'label', 'label', '10', 12], [$terminal, $type, $source, $sourceRef, $user]);
        self::assertSame(1, substr_count((string) $payload, "\x1DV\x41\x00"), 'one cut for the whole strip');
        self::assertSame(10, substr_count((string) $payload, "\x1Dk\x43\x0C841234567890"));
        self::assertSame(['jobs' => [501], 'print_status' => 'queued', 'labels' => 10, 'errors' => []], $result);
        self::assertSame([], $this->sent);
    }

    public function testLabelsAreSplitIntoStripsOfTenEachWithItsOwnCut(): void
    {
        $result = $this->service(self::AGENT)->print(3, self::items(100, 100), null);

        self::assertCount(20, $this->enqueued, '200 labels: 20 jobs (L6, AC14)');
        self::assertSame(array_fill(0, 20, '10'), array_map(static fn (array $job): string => (string) $job[4], $this->enqueued));
        foreach ($this->enqueued as $job) {
            self::assertSame(1, substr_count((string) $job[2], "\x1DV\x41\x00"), 'one cut per strip');
            self::assertSame(10, substr_count((string) $job[2], "\x1Dk\x43\x0C841234567890"));
        }
        self::assertSame(range(501, 520), $result['jobs']);
        self::assertSame(200, $result['labels']);

        $this->enqueued = [];
        $this->service(self::AGENT)->print(3, self::items(25), null);
        self::assertSame(['10', '10', '5'], array_map(static fn (array $job): string => (string) $job[4], $this->enqueued));
    }

    public function testAPrinterWithoutTheAgentAlsoGetsStripsOfTen(): void
    {
        $result = $this->service(self::SYSTEM)->print(3, self::items(25), null);

        self::assertSame('printed', $result['print_status']);
        self::assertCount(3, $this->sent);
        self::assertSame([10, 10, 5], array_map(static fn (string $bytes): int => substr_count($bytes, "\x1Dk\x43\x0C841234567890"), $this->sent));
    }

    public function testMoreThanAHundredOnOneLineIsRefused(): void
    {
        try {
            $this->service(self::AGENT)->print(3, self::items(101), null);
            self::fail('101 on one line was accepted');
        } catch (LabelPrintException $e) {
            self::assertSame('Máximo 100 etiquetas por línea', $e->getMessage());
            self::assertSame(422, $e->status);
        }
        self::assertSame([], $this->enqueued);
    }

    public function testWithTheAgentDisconnectedTheLabelsAreHeldForTheQuestion(): void
    {
        $result = $this->service(self::AGENT, false)->print(3, self::items(4), null);

        self::assertSame('held', $result['print_status']);
        self::assertCount(1, $this->enqueued);
        self::assertCount(1, $this->statements);
        self::assertStringContainsString('next_attempt_at = expires_at', $this->statements[0]);
    }

    public function testATillWithASystemPrinterPrintsStraightAway(): void
    {
        $result = $this->service(self::SYSTEM, true, new LabelSettings(40, true))->print(1, self::items(2), null);

        self::assertSame([], $this->enqueued);
        self::assertCount(1, $this->sent);
        self::assertStringStartsWith('Etiquetas_TM-T20:' . "\x1B@\x1Bt\x10", $this->sent[0]);
        self::assertSame(2, substr_count($this->sent[0], "\x1DV\x41\x00"), 'cut each label, from the till settings');
        self::assertSame(['jobs' => [], 'print_status' => 'printed', 'labels' => 2, 'errors' => []], $result);
    }

    public function testATillWithoutPrinterCannotPrintLabels(): void
    {
        try {
            $this->service(self::PDF)->print(1, self::items(2), null);
            self::fail('printed without printer');
        } catch (LabelPrintException $e) {
            self::assertSame('Esta caja no tiene impresora para etiquetas', $e->getMessage());
            self::assertSame('no_label_printer', $e->errorCode);
            self::assertSame(409, $e->status);
        }
    }

    public function testALabelThatCannotBePrintedIsReportedAndTheRestPrint(): void
    {
        $items = [...self::items(3), ['product_id' => 8, 'id_product_attribute' => 0, 'quantity' => 2]];
        $result = $this->service(self::AGENT)->print(3, $items, null);

        self::assertSame(3, $result['labels']);
        self::assertSame([['product_id' => 8, 'id_product_attribute' => 0, 'reason' => 'Sin EAN ni referencia: no se puede imprimir la etiqueta']], $result['errors']);
    }

    public function testNothingPrintableIsRefused(): void
    {
        $this->expectException(LabelPrintException::class);
        $this->expectExceptionMessage('Ninguna etiqueta se puede imprimir');

        $this->service(self::AGENT)->print(3, [['product_id' => 8, 'id_product_attribute' => 0, 'quantity' => 2]], null);
    }

    public function testTheTestLabelIsOneJobNeverHeld(): void
    {
        $result = $this->service(self::AGENT, false)->printTest(3, 1);

        self::assertCount(1, $this->enqueued);
        self::assertSame(['label', 'label_test', '1'], [$this->enqueued[0][1], $this->enqueued[0][3], $this->enqueued[0][4]]);
        self::assertSame([], $this->statements);
        self::assertSame('queued', $result['print_status']);
    }
}
