<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Refund;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\PrestaShop\CouponCreationService;
use VenPosTPV\Service\PrestaShop\Exception\CouponCreationException;
use VenPosTPV\Service\Refund\Exception\RefundException;

/**
 * Issues the PrestaShop voucher (1 year, partial use, one use) of a refund or of an
 * exchange closed with its open credit, at most once. The row's voucher_amount is
 * what the voucher is worth. The request is marked (voucher_requested_at) before
 * the cart rule is posted; when its outcome is unknown the mark stays and the row
 * keeps waiting until someone checks PrestaShop: a second voucher is never created
 * automatically. The code is stored as soon as it exists, so a later failure
 * retries without a new voucher.
 */
final class VoucherIssuer
{
    private const VALIDITY = '+1 year';

    /** Table => the status the row must still have while its voucher is issued. */
    private const WAITING_STATUS = ['refund' => 'pending', 'exchange' => 'credit_open'];

    public function __construct(
        private readonly Connection $conn,
        private readonly ?CouponCreationService $couponCreationService,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->couponCreationService !== null;
    }

    /**
     * @param 'refund'|'exchange' $table
     *
     * @return string the voucher code
     *
     * @throws RefundException
     */
    public function issue(string $table, int $id, int $shopId): string
    {
        $status = self::WAITING_STATUS[$table];
        $row = $this->conn->fetchAssociative(sprintf('SELECT voucher_code, voucher_amount, error_message FROM %s WHERE id = ?', $table), [$id]);
        if ($row === false) {
            throw RefundException::conflict(sprintf('%s #%d does not exist', ucfirst($table), $id));
        }
        if ($row['voucher_code'] !== null) {
            return (string) $row['voucher_code'];
        }
        if ($this->couponCreationService === null) {
            throw RefundException::psUnavailable('The voucher service is not configured');
        }

        $claimed = $this->conn->executeStatement(
            sprintf('UPDATE %s SET voucher_requested_at = ? WHERE id = ? AND status = ? AND voucher_requested_at IS NULL AND voucher_code IS NULL', $table),
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id, $status]
        );
        if ($claimed === 0) {
            throw RefundException::conflict(sprintf(
                'The voucher of %s #%d may already exist in PrestaShop%s. Check it there; a voucher is never created twice automatically',
                $table,
                $id,
                $row['error_message'] !== null ? ' (' . $row['error_message'] . ')' : ''
            ));
        }

        try {
            $coupon = $this->couponCreationService->create([
                'discount_type' => 'amount',
                'value' => round((float) $row['voucher_amount'], 2),
                'quantity' => 1,
                'quantity_per_user' => 1,
                'expires_at' => (new \DateTimeImmutable(self::VALIDITY))->format('Y-m-d'),
                'partial_use' => true,
            ], $shopId);
        } catch (CouponCreationException $e) {
            if ($e->getCode() !== 409) {
                // Validation and an unavailable schema fail before the cart rule is
                // posted, so the voucher may be requested again on a retry.
                $this->conn->update($table, ['voucher_requested_at' => null, 'error_message' => mb_substr($e->getMessage(), 0, 1000)], ['id' => $id]);

                throw RefundException::psUnavailable($e->getMessage(), $e);
            }
            $this->conn->update($table, ['error_message' => mb_substr($e->getMessage(), 0, 1000)], ['id' => $id]);

            throw RefundException::conflict($e->getMessage());
        } catch (\Throwable $e) {
            $this->conn->update($table, ['error_message' => mb_substr('Voucher outcome unknown: ' . $e->getMessage(), 0, 1000)], ['id' => $id]);

            throw RefundException::conflict('Voucher outcome unknown: ' . $e->getMessage());
        }

        $this->conn->update($table, [
            'voucher_code' => $coupon['code'],
            'voucher_cart_rule_id' => $coupon['id'],
            'error_message' => null,
        ], ['id' => $id]);

        return (string) $coupon['code'];
    }
}
