<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use VenPosTPV\Service\Fiscal\SpanishTaxId;
use VenPosTPV\Service\Print\PrinterConfig;
use VenPosTPV\Service\Print\SystemPrintQueue;

#[AsController]
final class TerminalController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemPrintQueue $systemPrintQueue,
    )
    {
    }

    #[Route('/terminal/{id}', methods: ['GET'])]
    public function get(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, ps_shop_id, name, printer_config, scanner_config, hotkeys, dataphone_mode, printer_type, store_name, store_address, legal_name, tax_id, ticket_header, ticket_footer, ticket_logo, ticket_width_px, stock_sync_interval_seconds, stock_push_interval_seconds, refund_window_days, ticket_code, active, created_at, updated_at FROM terminal WHERE id = ?',
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

        $row = $this->connection->fetchAssociative('SELECT id FROM terminal WHERE id = ?', [$id]);
        if (!$row) {
            return new JsonResponse(['error' => 'Terminal no encontrado'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode((string) $request->getContent(), true);
        $fields = [];

        if (isset($body['name'])) {
            $fields['name'] = trim((string) $body['name']);
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
            if (!in_array($type, ['none', 'pdf', 'escpos', 'system'], true)) {
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

        if (isset($body['active'])) {
            $fields['active'] = $body['active'] ? 1 : 0;
        }

        if ($fields) {
            $this->connection->update('terminal', $fields, ['id' => $id]);
        }

        return new JsonResponse(['data' => ['updated' => true]]);
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

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
