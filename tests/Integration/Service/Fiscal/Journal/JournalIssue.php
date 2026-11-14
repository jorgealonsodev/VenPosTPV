<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Integration\Service\Fiscal\Journal;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Fiscal\FiscalRecorder;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalIntents;
use VenPosTPV\Service\Sale\TicketNumberer;

/**
 * Issues a ticket the way checkout does at its core (number, sale, fiscal record in
 * one transaction), without the rest of the checkout, so protocol tests stay fast.
 * Also used by the concurrency worker.
 */
final class JournalIssue
{
    /** @return string the ticket number */
    public static function ticket(Connection $connection, ?FiscalJournalIntents $intents, int $terminalId, int $sessionId, bool $commit = true, ?\DateTimeImmutable $at = null): string
    {
        $date = $at ?? new \DateTimeImmutable();
        $numberer = new TicketNumberer($connection);
        $numberer->prepareYear($terminalId, $date);
        $connection->beginTransaction();
        try {
            $reference = $numberer->next($terminalId, $date);
            $connection->insert('order_meta', [
                'reference' => $reference,
                'terminal_id' => $terminalId,
                'session_id' => $sessionId,
                'user_id' => 1,
                'payment_breakdown' => json_encode(['reference' => $reference, 'total' => 12.10, 'tax' => 2.10], JSON_THROW_ON_ERROR),
                'printed' => 0,
                'synced' => 0,
                'created_at' => $date->format('Y-m-d H:i:s'),
            ]);
            $orderId = (int) $connection->lastInsertId();
            (new FiscalRecorder($connection, journal: $intents))->recordSale($orderId, $terminalId, $reference, 12.10, 2.10, $date);
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
        if ($commit) {
            $connection->commit();
        } else {
            $connection->rollBack();
        }

        return $reference;
    }
}
