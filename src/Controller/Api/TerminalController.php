<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Fiscal\SpanishTaxId;
use VenPosTPV\Service\Label\LabelSettings;
use VenPosTPV\Service\Label\LabelSettingsStore;
use VenPosTPV\Service\Print\PrinterConfig;
use VenPosTPV\Service\Print\SystemPrintQueue;

#[AsController]
final class TerminalController
{
    /**
     * Shop data (S10 "Tienda"): the same on every till, so each till prints the same
     * header. Stored on every terminal row of the shop rather than in a new table:
     * TicketDataResolver reads them per terminal (terminalPresentation) and keeps
     * working unchanged. Everything else (name, letter, printer, dataphone, ticket
     * width, sync intervals) belongs to one till ("Cajas").
     */
    private const SHOP_FIELDS = [
        'store_name', 'store_address', 'legal_name', 'tax_id',
        'ticket_header', 'ticket_footer', 'ticket_logo', 'refund_window_days', 'ticket_code',
    ];

    private readonly LabelSettingsStore $labelSettings;

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemPrintQueue $systemPrintQueue,
        ?LabelSettingsStore $labelSettings = null,
    )
    {
        $this->labelSettings = $labelSettings ?? new LabelSettingsStore($connection);
    }

    #[Route('/terminal/{id}', methods: ['GET'])]
    public function get(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, ps_shop_id, name, ticket_letter, printer_config, scanner_config, hotkeys, dataphone_mode, printer_type, store_name, store_address, legal_name, tax_id, ticket_header, ticket_footer, ticket_logo, ticket_width_px, stock_sync_interval_seconds, stock_push_interval_seconds, refund_window_days, ticket_code, active, created_at, updated_at FROM terminal WHERE id = ?',
            [$id]
        );

        if (!$row) {
            return new JsonResponse(['error' => 'Terminal no encontrado'], Response::HTTP_NOT_FOUND);
        }

        // Expose the stored printer settings in the shape the settings form edits.
        $printer = PrinterConfig::fromJson(\is_string($row['printer_config'] ?? null) ? $row['printer_config'] : null);
        $row['printer_ip'] = $printer->type === 'network' ? $printer->host : '';
        $row['printer_port'] = $printer->type === 'network' ? $printer->port : 9100;
        $row['printer_queue'] = $printer->type === 'system' ? $printer->queue : '';
        // Labels of this till (L5, L6); the defaults until migration V38 runs.
        $labels = $this->labelSettings->forTerminal($id);
        $row['label_height_mm'] = $labels->heightMm;
        $row['label_cut_each'] = $labels->cutEach;

        return new JsonResponse(['data' => $row]);
    }

    /**
     * Printers installed on the machine running the TPV (e.g. a USB receipt printer).
     */
    #[Route('/printers/system', methods: ['GET'])]
    public function systemPrinters(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        return new JsonResponse(['data' => $this->systemPrintQueue->list()]);
    }

    #[Route('/terminal/{id}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        $row = $this->connection->fetchAssociative('SELECT id, ps_shop_id FROM terminal WHERE id = ?', [$id]);
        if (!$row) {
            return new JsonResponse(['error' => 'Terminal no encontrado'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode((string) $request->getContent(), true);
        $fields = [];

        if (isset($body['name'])) {
            $fields['name'] = trim((string) $body['name']);
        }

        if (array_key_exists('ticket_letter', $body)) {
            // First character of every ticket number (A26000123): one letter per terminal.
            $letter = strtoupper(trim((string) $body['ticket_letter']));
            if (!preg_match('/^[A-Z]$/', $letter)) {
                return new JsonResponse(['error' => 'La letra de caja debe ser una letra de la A a la Z'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($this->letterUsedByAnotherTerminal($letter, $id)) {
                return $this->letterInUse();
            }
            $fields['ticket_letter'] = $letter;
        }

        if (isset($body['dataphone_mode'])) {
            $mode = (string) $body['dataphone_mode'];
            if (!in_array($mode, ['integrated', 'independent'], true)) {
                return new JsonResponse(['error' => 'Modo de datáfono no válido'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['dataphone_mode'] = $mode;
        }

        if (isset($body['printer_type'])) {
            $type = (string) $body['printer_type'];
            if (!in_array($type, ['none', 'pdf', 'escpos', 'system', 'agent'], true)) {
                return new JsonResponse(['error' => 'Tipo de impresora no válido'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['printer_type'] = $type;

            // printer_config is what the print service reads: keep it in step with the type.
            if ($type === 'escpos') {
                $host = trim((string) ($body['printer_ip'] ?? ''));
                $port = (int) ($body['printer_port'] ?? 9100);
                if ($host === '' || !preg_match('/^[A-Za-z0-9.:-]+$/', $host)) {
                    return new JsonResponse(['error' => 'Indica la IP de la impresora de red'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if ($port < 1 || $port > 65535) {
                    return new JsonResponse(['error' => 'Puerto de impresora no válido (1-65535)'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $config = ['enabled' => true, 'type' => 'network', 'host' => $host, 'port' => $port];
            } elseif ($type === 'system') {
                $queue = trim((string) ($body['printer_queue'] ?? ''));
                if (!\in_array($queue, $this->systemPrintQueue->list(), true)) {
                    return new JsonResponse(['error' => 'Elige una impresora instalada en este equipo'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $config = ['enabled' => true, 'type' => 'system', 'queue' => $queue];
            } elseif ($type === 'agent') {
                // The print agent on the till's computer prints; it chooses its printers itself (PRD S5).
                $config = ['enabled' => true, 'type' => 'agent'];
            } else {
                $config = ['enabled' => false, 'type' => $type];
            }
            $fields['printer_config'] = json_encode($config, JSON_THROW_ON_ERROR);
        }

        if (array_key_exists('store_address', $body)) {
            $fields['store_address'] = trim((string) $body['store_address']);
        }

        if (array_key_exists('store_name', $body)) {
            $fields['store_name'] = $this->normalizeNullableText($body['store_name']);
        }

        if (array_key_exists('legal_name', $body)) {
            $fields['legal_name'] = $this->normalizeNullableText($body['legal_name']);
        }

        if (array_key_exists('tax_id', $body)) {
            $taxId = SpanishTaxId::normalize((string) $body['tax_id']);
            if ($taxId !== '' && !SpanishTaxId::isValid($taxId)) {
                return new JsonResponse(['error' => 'NIF no válido: revisa las cifras y la letra de control'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['tax_id'] = $taxId === '' ? null : $taxId;
        }

        if (array_key_exists('ticket_header', $body)) {
            $fields['ticket_header'] = $this->normalizeNullableText($body['ticket_header']);
        }

        if (array_key_exists('ticket_footer', $body)) {
            $fields['ticket_footer'] = $this->normalizeNullableText($body['ticket_footer']);
        }

        if (array_key_exists('ticket_logo', $body)) {
            $fields['ticket_logo'] = $this->normalizeNullableText($body['ticket_logo']);
        }

        if (array_key_exists('ticket_width_px', $body)) {
            $width = $body['ticket_width_px'];
            if ($width === null || $width === '') {
                $fields['ticket_width_px'] = null;
            } else {
                $widthInt = (int) $width;
                if ($widthInt < 100 || $widthInt > 600) {
                    return new JsonResponse(['error' => 'Ancho de ticket no válido (100-600)'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $fields['ticket_width_px'] = $widthInt;
            }
        }

        if (isset($body['stock_sync_interval_seconds'])) {
            $seconds = (int) $body['stock_sync_interval_seconds'];
            if ($seconds < 0 || $seconds > 3600) {
                return new JsonResponse(['error' => 'Intervalo de sincronización no válido (0-3600)'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['stock_sync_interval_seconds'] = $seconds;
        }

        if (isset($body['stock_push_interval_seconds'])) {
            $seconds = (int) $body['stock_push_interval_seconds'];
            if ($seconds < 0 || $seconds > 3600) {
                return new JsonResponse(['error' => 'Intervalo de push no válido (0-3600)'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['stock_push_interval_seconds'] = $seconds;
        }

        if (isset($body['ticket_code'])) {
            $code = (string) $body['ticket_code'];
            if (!\in_array($code, ['barcode', 'qr', 'both'], true)) {
                return new JsonResponse(['error' => 'Código del ticket no válido'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['ticket_code'] = $code;
        }

        if (isset($body['refund_window_days'])) {
            $days = (int) $body['refund_window_days'];
            if ($days < 1 || $days > 3650) {
                return new JsonResponse(['error' => 'Plazo de devolución no válido (1-3650 días)'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['refund_window_days'] = $days;
        }

        // Labels of this till (docs/prd/agente-impresion.md L5, L6).
        if (isset($body['label_height_mm']) || isset($body['label_cut_each'])) {
            if (!$this->labelSettings->isAvailable()) {
                return new JsonResponse(['error' => 'Falta aplicar la migración de etiquetas (Version00000038)'], Response::HTTP_CONFLICT);
            }
            if (isset($body['label_height_mm'])) {
                try {
                    $fields['label_height_mm'] = LabelSettings::validated(is_numeric($body['label_height_mm']) ? (int) $body['label_height_mm'] : 0, false)->heightMm;
                } catch (\InvalidArgumentException $e) {
                    return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
            if (isset($body['label_cut_each'])) {
                $fields['label_cut_each'] = $body['label_cut_each'] === true || $body['label_cut_each'] === 1 || $body['label_cut_each'] === '1' ? 1 : 0;
            }
        }

        if (isset($body['active'])) {
            $fields['active'] = $body['active'] ? 1 : 0;
        }

        $shopFields = array_intersect_key($fields, array_flip(self::SHOP_FIELDS));
        $tillFields = array_diff_key($fields, $shopFields);

        if ($fields) {
            $this->connection->beginTransaction();
            try {
                if ($shopFields) {
                    $this->connection->update('terminal', $shopFields, ['ps_shop_id' => (int) ($row['ps_shop_id'] ?? 0)]);
                }
                if ($tillFields) {
                    $this->connection->update('terminal', $tillFields, ['id' => $id]);
                }
                $this->connection->commit();
            } catch (UniqueConstraintViolationException) {
                $this->connection->rollBack();

                // Another terminal took the same letter between the check and the update.
                return $this->letterInUse();
            } catch (\Throwable $e) {
                $this->connection->rollBack();

                throw $e;
            }
        }

        return new JsonResponse(['data' => ['updated' => true]]);
    }

    /**
     * New till ("Cajas" > "Crear caja"): name and its own letter; the shop data is
     * copied from the shop's tills so the new one prints the same ticket header.
     */
    #[Route('/terminal', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return new JsonResponse(['error' => 'Se espera un cuerpo JSON'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $body = json_decode((string) $request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) {
            return new JsonResponse(['error' => 'Indica el nombre de la caja (hasta 64 caracteres)'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $letter = strtoupper(trim((string) ($body['ticket_letter'] ?? '')));
        if (!preg_match('/^[A-Z]$/', $letter)) {
            return new JsonResponse(['error' => 'La letra de caja debe ser una letra de la A a la Z'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($this->letterUsedByAnotherTerminal($letter, 0)) {
            return $this->letterInUse();
        }

        $shopId = (int) ($request->getSession()->get('shop_id') ?? 0);
        $shopId = $shopId > 0 ? $shopId : 1;
        $shop = $this->connection->fetchAssociative(
            'SELECT ' . implode(', ', self::SHOP_FIELDS) . ' FROM terminal WHERE ps_shop_id = ? AND active = 1 ORDER BY id LIMIT 1',
            [$shopId]
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $fields = ['ps_shop_id' => $shopId, 'name' => $name, 'ticket_letter' => $letter, 'active' => 1, 'created_at' => $now, 'updated_at' => $now];
        if (\is_array($shop)) {
            foreach (self::SHOP_FIELDS as $field) {
                if (\array_key_exists($field, $shop) && $shop[$field] !== null) {
                    $fields[$field] = $shop[$field];
                }
            }
        }

        try {
            $this->connection->insert('terminal', $fields);
        } catch (UniqueConstraintViolationException) {
            return $this->letterInUse();
        }

        return new JsonResponse(['data' => ['id' => (int) $this->connection->lastInsertId()]], Response::HTTP_CREATED);
    }

    // -------------------------------------------------------------------------

    private function isAdmin(Request $request): bool
    {
        return $request->getSession()->get('role') === 'admin';
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Acceso denegado — solo administradores'], Response::HTTP_FORBIDDEN);
    }

    private function letterUsedByAnotherTerminal(string $letter, int $id): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM terminal WHERE ticket_letter = ? AND id <> ?',
            [$letter, $id]
        ) > 0;
    }

    private function letterInUse(): JsonResponse
    {
        return new JsonResponse(['error' => 'Esa letra ya la usa otra caja'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
