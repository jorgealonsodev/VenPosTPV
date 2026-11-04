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
use VenPosTPV\EventListener\SecurityHeadersListener;
use VenPosTPV\Service\Device\DeviceCookie;
use VenPosTPV\Service\Device\DeviceRegistry;

#[AsController]
class PosController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Connection $connection,
        private readonly DeviceRegistry $devices,
        private readonly DeviceCookie $deviceCookie,
        private readonly string $basePath = '',
    ) {
    }

    /**
     * The POS works on the till of this computer (S10), never on whichever terminal
     * happens to be first. Without a linked till the page shows "Este equipo no tiene
     * caja asignada" — except on a single-till shop whose till has no computer yet:
     * the first computer adopts it.
     */
    #[Route('/pos', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');

        if ($userId === null || !is_int($userId) || $userId < 0) {
            return new RedirectResponse($this->basePath . '/app/login');
        }

        $shopId = (int) ($session->get('shop_id') ?? 1);
        $terminalId = $this->devices->terminalForToken(DeviceCookie::read($request));
        $issuedToken = null;
        if ($terminalId === null) {
            $adopted = $this->devices->adoptOnlyTill($shopId > 0 ? $shopId : 1, $userId);
            if ($adopted !== null) {
                $terminalId = $adopted['terminal_id'];
                $issuedToken = $adopted['token'];
            }
        }

        $row = $terminalId !== null
            ? $this->connection->fetchAssociative(
                'SELECT name, ticket_letter, dataphone_mode, printer_type, stock_sync_interval_seconds, stock_push_interval_seconds FROM terminal WHERE id = ?',
                [$terminalId]
            )
            : false;
        $row = \is_array($row) ? $row : [];

        $sessionData = [
            'user_id' => $userId,
            'cashier_name' => $session->get('cashier_name', 'Cajero'),
            'store_name' => $session->get('store_name', 'VenPosTPV'),
            'role' => $session->get('role', 'cashier'),
            'shop_id' => $session->get('shop_id', 1),
            'device_linked' => $terminalId !== null,
            'terminal_id' => $terminalId,
            'terminal_name' => isset($row['name']) ? (string) $row['name'] : null,
            'ticket_letter' => isset($row['ticket_letter']) ? (string) $row['ticket_letter'] : null,
            'cash_session_id' => $session->get('cash_session_id'),
            'cash_opened_at' => $session->get('cash_opened_at'),
            'opening_cash' => $session->get('opening_cash'),
            'dataphone_mode' => isset($row['dataphone_mode']) ? (string) $row['dataphone_mode'] : 'integrated',
            'printer_type' => isset($row['printer_type']) ? (string) $row['printer_type'] : 'pdf',
            'stock_sync_interval_seconds' => isset($row['stock_sync_interval_seconds']) ? (int) $row['stock_sync_interval_seconds'] : 60,
            'stock_push_interval_seconds' => isset($row['stock_push_interval_seconds']) ? (int) $row['stock_push_interval_seconds'] : 0,
        ];
        if ($issuedToken !== null) {
            // Handed to the page once so it can keep a copy in localStorage.
            $sessionData['device_token'] = $issuedToken;
        }

        $response = new Response($this->twig->render('pos.html.twig', [
            // Printed raw inside a <script>: < > & ' " are escaped so no value can close it (R9).
            'session_data' => json_encode($sessionData, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'csp_nonce' => (string) $request->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE, ''),
        ]));
        if ($issuedToken !== null) {
            $response->headers->setCookie($this->deviceCookie->create($issuedToken, $request));
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
