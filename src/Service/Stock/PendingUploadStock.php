<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Stock;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Sync\Outbox;

/**
 * The stock movements the TPV already applied to its local catalog_stock copy but
 * PrestaShop has not seen yet, because their outbox row is not uploaded.
 *
 * A sale lowers the local stock at once (CheckoutService::decrementStock) and
 * PrestaShop lowers its own stock when the module creates the order; a refund or a
 * refund without a ticket with restock raises the local stock at once and
 * PrestaShop raises its own when the slip or restock is uploaded. Until the row is
 * `done`, the two copies differ by exactly these quantities:
 *
 *   PrestaShop now = local + owed
 *   local          = PrestaShop now - owed
 *
 * where `owed` is what the not-yet-uploaded sales will still take off PrestaShop
 * minus what the not-yet-uploaded restocks will still put back. The absolute stock
 * push and the inbound stock sync both use it, so neither direction counts a
 * pending operation twice nor loses it.
 *
 * Rows pending, processing or in incident count: none of them has reached
 * PrestaShop as far as the TPV knows, and each may still be uploaded. Done rows
 * never count, nor any other status (a row given up for good will never move
 * PrestaShop's stock, so the local count is the one to keep).
 */
class PendingUploadStock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public static function key(int $productId, int $attributeId): string
    {
        return $productId . ':' . $attributeId;
    }

    /**
     * Run inside the same transaction (or snapshot) as the read or write of
     * catalog_stock it adjusts, so a checkout committing in between is either in both
     * reads or in neither.
     *
     * @return array<string, int> product:attribute => quantity PrestaShop will still
     *                            lose (positive) or gain (negative) when the pending
     *                            uploads apply; entries that net to 0 are left out
     */
    public function owedByPendingUploads(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, payload FROM outbox WHERE status IN (?, ?, ?) AND type IN (?, ?, ?)',
            [Outbox::STATUS_PENDING, Outbox::STATUS_PROCESSING, Outbox::STATUS_INCIDENT, Outbox::TYPE_SALE, Outbox::TYPE_REFUND, Outbox::TYPE_RESTOCK]
        );

        // Discarded rows (Outbox::STATUS_DISCARDED) never reach PrestaShop: not owed.
        $owed = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!\is_array($payload)) {
                continue;
            }

            if ($row['type'] === Outbox::TYPE_SALE) {
                // The module's order request (CheckoutService::orderPayload).
                foreach (self::lines($payload['products'] ?? null) as $line) {
                    self::add($owed, $line, 1);
                }
                continue;
            }

            // Refund slip or restock (RefundService::moduleLines): only restocked lines move stock.
            foreach (self::lines($payload['lines'] ?? null) as $line) {
                if (!empty($line['restock'])) {
                    self::add($owed, $line, -1);
                }
            }
        }

        return array_filter($owed, static fn (int $quantity): bool => $quantity !== 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lines(mixed $lines): array
    {
        return \is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }

    /**
     * @param array<string, int> $owed
     * @param array<string, mixed> $line
     */
    private static function add(array &$owed, array $line, int $sign): void
    {
        $productId = (int) ($line['id_product'] ?? 0);
        $quantity = (int) ($line['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            return;
        }

        $key = self::key($productId, (int) ($line['id_product_attribute'] ?? 0));
        $owed[$key] = ($owed[$key] ?? 0) + $sign * $quantity;
    }
}
