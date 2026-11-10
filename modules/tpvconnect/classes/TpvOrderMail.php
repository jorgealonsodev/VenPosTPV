<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

/**
 * PrestaShop sends its own e-mails while validateOrder creates an order (the
 * confirmation, the voucher with a partial-use rest…). A till order does not need
 * them: the till hands the ticket and the voucher over itself. And a shop whose
 * mail cannot be sent (sendmail needs proc_open, which some hostings disable)
 * dies with a fatal error half way through the order: no order state, the coupon
 * not consumed, its rest left bound to the till's customer.
 *
 * While a till order is created, the module's actionEmailSendBefore hook answers
 * false, so Mail::send() skips the message and reports it as sent. Web orders are
 * untouched.
 */
final class TpvOrderMail
{
    private static int $blocking = 0;

    /**
     * Runs $createOrder with PrestaShop's e-mails blocked and returns its result.
     *
     * @template T
     *
     * @param callable(): T $createOrder
     *
     * @return T
     */
    public static function withoutShopMail(callable $createOrder): mixed
    {
        ++self::$blocking;
        try {
            return $createOrder();
        } finally {
            --self::$blocking;
        }
    }

    public static function blocks(): bool
    {
        return self::$blocking > 0;
    }
}
