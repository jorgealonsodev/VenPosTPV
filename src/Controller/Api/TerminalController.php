<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class TerminalController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/terminal/{id}', methods: ['GET'])]
    public function get(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->unauthorized();
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, ps_shop_id, name, printer_config, scanner_config, hotkeys, dataphone_mode, printer_type, store_address, ticket_header, ticket_footer, ticket_logo, ticket_width_px, stock_sync_interval_seconds, stock_push_interval_seconds, refund_window_days, active, created_at, updated_at FROM terminal WHERE id = ?',
            [$id]
        );

        if (!$row) {
            return new JsonResponse(['error' => 'Terminal no encontrado'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $row]);
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
            if (!in_array($type, ['none', 'pdf', 'escpos'], true)) {
                return new JsonResponse(['error' => 'Tipo de impresora no válido'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fields['printer_type'] = $type;
        }

        if (array_key_exists('store_address', $body)) {
            $fields['store_address'] = trim((string) $body['store_address']);
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
