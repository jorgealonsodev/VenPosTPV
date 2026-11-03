<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Print\PrinterConfig;
use VenPosTPV\Service\Print\SystemPrintQueue;
use VenPosTPV\Service\PrintAgent\AgentRegistry;
use VenPosTPV\Service\PrintAgent\PrintJobQueue;
use VenPosTPV\Service\PrintAgent\TerminalNotResolvedException;

/**
 * Prints labels on a till (docs/prd/agente-impresion.md L1-L9, T2, T7, T8).
 *
 * - At most 100 labels per line and 500 per job (L8, T8); more labels make several
 *   jobs, each one strip with a single cut (L6).
 * - A till with the print agent gets `label` jobs (target `label`); the queue hands
 *   tickets and the drawer out first (T7). A job's `source_ref` is its label count.
 * - When the agent is not connected the jobs are created held: `pending` with
 *   `next_attempt_at` = `expires_at`, which the agent can never claim. When it is
 *   back the till asks "Hay N etiquetas pendientes. ¿Imprimirlas?" (L9) and releases
 *   or cancels them. Held labels still expire after 24 h (T6).
 * - Any other till prints straight to its network or system printer, like tickets.
 */
class LabelPrintService
{
    public const MAX_PER_LINE = 100;
    public const MAX_PER_JOB = 500;
    public const MAX_PER_REQUEST = 5000;
    public const SOURCE = 'label';
    public const TEST_SOURCE = 'label_test';
    private const HELD = "type = 'label' AND status = 'pending' AND next_attempt_at IS NOT NULL AND next_attempt_at >= expires_at AND expires_at > ?";

    public function __construct(
        private readonly Connection $connection,
        private readonly LabelCatalog $catalog,
        private readonly LabelEscPosRenderer $renderer,
        private readonly LabelSettingsStore $settings,
        private readonly PrintJobQueue $jobs,
        private readonly AgentRegistry $agents,
        private readonly SystemPrintQueue $systemPrintQueue = new SystemPrintQueue(),
    ) {
    }

    /**
     * Each line of a request with its label, or the reason it cannot be printed.
     * Lines with quantity 0 are left out.
     *
     * @param mixed $items list of {product_id, id_product_attribute, quantity}
     *
     * @return list<array{product_id: int, id_product_attribute: int, quantity: int, label: Label|null, reason: string|null}>
     *
     * @throws LabelPrintException
     */
    public function lineLabels(mixed $items): array
    {
        $result = [];
        foreach (self::lines($items) as $line) {
            if ($line['quantity'] === 0) {
                continue;
            }
            try {
                $result[] = $line + ['label' => $this->catalog->label($line['product_id'], $line['id_product_attribute']), 'reason' => null];
            } catch (LabelException $e) {
                $result[] = $line + ['label' => null, 'reason' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * The labels of a request, in order, and the lines that cannot be printed.
     *
     * @param mixed $items list of {product_id, id_product_attribute, quantity}
     *
     * @return array{labels: list<Label>, errors: list<array{product_id: int, id_product_attribute: int, reason: string}>}
     *
     * @throws LabelPrintException
     */
    public function resolve(mixed $items): array
    {
        $labels = [];
        $errors = [];
        foreach ($this->lineLabels($items) as $line) {
            if ($line['label'] === null) {
                $errors[] = ['product_id' => $line['product_id'], 'id_product_attribute' => $line['id_product_attribute'], 'reason' => (string) $line['reason']];
                continue;
            }
            for ($i = 0; $i < $line['quantity']; ++$i) {
                $labels[] = $line['label'];
            }
        }

        return ['labels' => $labels, 'errors' => $errors];
    }

    /**
     * @return array{jobs: list<int>, print_status: string, labels: int, errors: list<array{product_id: int, id_product_attribute: int, reason: string}>, error?: string}
     *
     * @throws LabelPrintException
     */
    public function print(int $terminalId, mixed $items, ?int $userId): array
    {
        if ($terminalId <= 0) {
            throw new TerminalNotResolvedException();
        }
        $config = $this->printerConfig($terminalId);
        if (!$config->usesAgent() && !$config->printsDirectly()) {
            throw new LabelPrintException('Esta caja no tiene impresora para etiquetas', 409, 'no_label_printer');
        }

        $resolved = $this->resolve($items);
        if ($resolved['labels'] === []) {
            throw new LabelPrintException('Ninguna etiqueta se puede imprimir', 422, 'nothing_to_print');
        }

        $result = $this->send($terminalId, $config, $resolved['labels'], self::SOURCE, $userId, true);

        return ['jobs' => $result['jobs'], 'print_status' => $result['print_status'], 'labels' => \count($resolved['labels']), 'errors' => $resolved['errors']]
            + (isset($result['error']) ? ['error' => $result['error']] : []);
    }

    /**
     * "Imprimir etiqueta de prueba" (L8, Ajustes → Cajas): one label, never held.
     *
     * @return array{jobs: list<int>, print_status: string, error?: string}
     *
     * @throws LabelPrintException
     */
    public function printTest(int $terminalId, ?int $userId): array
    {
        $config = $this->printerConfig($terminalId);
        if (!$config->usesAgent() && !$config->printsDirectly()) {
            throw new LabelPrintException('Esta caja no tiene impresora para etiquetas', 409, 'no_label_printer');
        }

        return $this->send($terminalId, $config, [self::testLabel()], self::TEST_SOURCE, $userId, false);
    }

    public static function testLabel(): Label
    {
        return new Label('Etiqueta de prueba', 'M · Azul', 23.14, (new BarcodeSelector())->select('8412345678905', null, null));
    }

    /**
     * Held labels of a till (L9).
     *
     * @return array{jobs: int, labels: int}
     */
    public function pending(int $terminalId): array
    {
        $this->jobs->resolveDue($terminalId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, source_ref FROM print_job WHERE terminal_id = ? AND ' . self::HELD,
            [$terminalId, $this->now()]
        );
        $labels = 0;
        foreach ($rows as $row) {
            $labels += max(1, (int) $row['source_ref']);
        }

        return ['jobs' => \count($rows), 'labels' => $labels];
    }

    /** "Imprimir": the agent may now claim the held labels. Returns how many. */
    public function releasePending(int $terminalId): int
    {
        $pending = $this->pending($terminalId)['labels'];
        $this->connection->executeStatement(
            'UPDATE print_job SET next_attempt_at = NULL WHERE terminal_id = ? AND ' . self::HELD,
            [$terminalId, $this->now()]
        );

        return $pending;
    }

    /** "Descartar": the held labels are cancelled. Returns how many. */
    public function discardPending(int $terminalId): int
    {
        $pending = $this->pending($terminalId)['labels'];
        $now = $this->now();
        $this->connection->executeStatement(
            "UPDATE print_job SET status = 'cancelled', finished_at = ?, next_attempt_at = NULL WHERE terminal_id = ? AND " . self::HELD,
            [$now, $terminalId, $now]
        );

        return $pending;
    }

    public function printerConfig(int $terminalId): PrinterConfig
    {
        $json = $this->connection->fetchOne('SELECT printer_config FROM terminal WHERE id = ?', [$terminalId]);

        return PrinterConfig::fromJson(\is_string($json) ? $json : null);
    }

    /**
     * Delivers bytes to a network printer (host:port) or a system queue, as tickets are.
     */
    protected function sendRaw(PrinterConfig $config, string $bytes): void
    {
        if ($config->type === 'system') {
            $this->systemPrintQueue->send($config->queue, $bytes);

            return;
        }

        $socket = @fsockopen($config->host, $config->port, $errno, $errstr, 5.0);
        if ($socket === false) {
            throw new \RuntimeException(sprintf('Printer connection failed: %s (%d)', $errstr, $errno));
        }
        stream_set_timeout($socket, 10);
        fwrite($socket, $bytes);
        fclose($socket);
    }

    /**
     * @param list<Label> $labels
     *
     * @return array{jobs: list<int>, print_status: string, error?: string}
     */
    private function send(int $terminalId, PrinterConfig $config, array $labels, string $source, ?int $userId, bool $holdWhenOffline): array
    {
        $settings = $this->settings->forTerminal($terminalId);
        $chunks = array_chunk($labels, self::MAX_PER_JOB);

        if ($config->usesAgent()) {
            $hold = $holdWhenOffline && !$this->agents->statusOf($terminalId)['connected'];
            $jobs = [];
            foreach ($chunks as $chunk) {
                $bytes = $this->renderer->render($chunk, $settings);
                $jobs[] = $this->connection->transactional(function () use ($terminalId, $bytes, $source, $chunk, $userId, $hold): int {
                    $id = $this->jobs->enqueue($terminalId, 'label', $bytes, $source, (string) \count($chunk), $userId);
                    if ($hold) {
                        $this->connection->executeStatement('UPDATE print_job SET next_attempt_at = expires_at WHERE id = ?', [$id]);
                    }

                    return $id;
                });
            }

            return ['jobs' => $jobs, 'print_status' => $hold ? 'held' : 'queued'];
        }

        try {
            foreach ($chunks as $chunk) {
                $this->sendRaw($config, $this->renderer->render($chunk, $settings));
            }
        } catch (\Throwable $e) {
            return ['jobs' => [], 'print_status' => 'failed', 'error' => 'La impresora no ha respondido: ' . mb_substr($e->getMessage(), 0, 200)];
        }

        return ['jobs' => [], 'print_status' => 'printed'];
    }

    /**
     * @return list<array{product_id: int, id_product_attribute: int, quantity: int}>
     *
     * @throws LabelPrintException
     */
    private static function lines(mixed $items): array
    {
        if (!\is_array($items) || !array_is_list($items) || $items === []) {
            throw new LabelPrintException('No hay etiquetas que imprimir');
        }
        $lines = [];
        $total = 0;
        foreach ($items as $item) {
            $productId = \is_array($item) ? filter_var($item['product_id'] ?? null, \FILTER_VALIDATE_INT) : false;
            $attributeId = \is_array($item) ? filter_var($item['id_product_attribute'] ?? 0, \FILTER_VALIDATE_INT) : false;
            $quantity = \is_array($item) ? filter_var($item['quantity'] ?? null, \FILTER_VALIDATE_INT) : false;
            if (!\is_int($productId) || $productId <= 0 || !\is_int($attributeId) || $attributeId < 0) {
                throw new LabelPrintException('Producto no válido');
            }
            if (!\is_int($quantity) || $quantity < 0) {
                throw new LabelPrintException('Cantidad no válida');
            }
            if ($quantity > self::MAX_PER_LINE) {
                throw new LabelPrintException('Máximo 100 etiquetas por línea');
            }
            $total += $quantity;
            $lines[] = ['product_id' => $productId, 'id_product_attribute' => $attributeId, 'quantity' => $quantity];
        }
        if ($total > self::MAX_PER_REQUEST) {
            throw new LabelPrintException('Máximo 5000 etiquetas cada vez');
        }

        return $lines;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
