<?php

declare(strict_types=1);

namespace VenPosTPV\Controller\App;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
class PosController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Connection $connection,
        private readonly string $basePath = '',
    ) {
    }

    #[Route('/pos', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');

        if ($userId === null || !is_int($userId) || $userId < 0) {
            return new RedirectResponse($this->basePath . '/app/login');
        }

        $terminalId = $session->get('terminal_id');
        $dataphoneMode = 'integrated';
        $printerType = 'pdf';

        $stockSyncInterval = 60;
        $stockPushInterval = 0;

        if ($terminalId !== null && is_int($terminalId) && $terminalId > 0) {
            $row = $this->connection->fetchAssociative(
                'SELECT dataphone_mode, printer_type, stock_sync_interval_seconds, stock_push_interval_seconds FROM terminal WHERE id = ?',
                [$terminalId]
            );
            if ($row && isset($row['dataphone_mode'])) {
                $dataphoneMode = (string) $row['dataphone_mode'];
            }
            if ($row && isset($row['printer_type'])) {
                $printerType = (string) $row['printer_type'];
            }
            if ($row && isset($row['stock_sync_interval_seconds'])) {
                $stockSyncInterval = (int) $row['stock_sync_interval_seconds'];
            }
            if ($row && isset($row['stock_push_interval_seconds'])) {
                $stockPushInterval = (int) $row['stock_push_interval_seconds'];
            }
        } else {
            $fallback = $this->connection->fetchAssociative(
                'SELECT id, dataphone_mode, printer_type, stock_sync_interval_seconds, stock_push_interval_seconds FROM terminal WHERE active = 1 ORDER BY id ASC LIMIT 1'
            );
            if ($fallback) {
                $terminalId = (int) $fallback['id'];
                if (isset($fallback['dataphone_mode'])) {
                    $dataphoneMode = (string) $fallback['dataphone_mode'];
                }
                if (isset($fallback['printer_type'])) {
                    $printerType = (string) $fallback['printer_type'];
                }
                if (isset($fallback['stock_sync_interval_seconds'])) {
                    $stockSyncInterval = (int) $fallback['stock_sync_interval_seconds'];
                }
                if (isset($fallback['stock_push_interval_seconds'])) {
                    $stockPushInterval = (int) $fallback['stock_push_interval_seconds'];
                }
            }
        }

        $sessionData = [
            'user_id' => $userId,
            'cashier_name' => $session->get('cashier_name', 'Cajero'),
            'store_name' => $session->get('store_name', 'VenPosTPV'),
            'role' => $session->get('role', 'cashier'),
            'shop_id' => $session->get('shop_id', 1),
            'terminal_id' => $terminalId,
            'cash_session_id' => $session->get('cash_session_id'),
            'cash_opened_at' => $session->get('cash_opened_at'),
            'opening_cash' => $session->get('opening_cash'),
            'dataphone_mode' => $dataphoneMode,
            'printer_type' => $printerType,
            'stock_sync_interval_seconds' => $stockSyncInterval,
            'stock_push_interval_seconds' => $stockPushInterval,
        ];

        return new Response($this->twig->render('pos.html.twig', [
            'session_data' => json_encode($sessionData, JSON_THROW_ON_ERROR),
        ]));
    }
}
