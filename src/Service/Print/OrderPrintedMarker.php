<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Print;

use Doctrine\DBAL\Connection;

/**
 * Marks a sale's ticket as printed, shared by the direct printers (PrinterService)
 * and the print agent queue when the agent confirms a ticket.
 */
final class OrderPrintedMarker
{
    /**
     * @param int $orderId a PrestaShop order id, or a TicketDataResolver::saleKey() (the
     *                     negative local id) for a sale that may not be uploaded yet
     */
    public static function mark(Connection $connection, int $orderId): void
    {
        if ($orderId < 0) {
            $connection->executeStatement(
                'UPDATE order_meta SET printed = 1, printed_at = NOW(), print_attempts = print_attempts + 1 WHERE id = ?',
                [-$orderId]
            );

            return;
        }
        $connection->executeStatement(
            'UPDATE order_meta SET printed = 1, printed_at = NOW(), print_attempts = print_attempts + 1 WHERE ps_order_id = ?',
            [$orderId]
        );
    }
}
