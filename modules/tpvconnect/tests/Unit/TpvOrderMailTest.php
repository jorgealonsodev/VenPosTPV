<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/TpvOrderMail.php';
require_once __DIR__ . '/../../install/install.php';

use PHPUnit\Framework\TestCase;

/**
 * A till order never sends PrestaShop's own e-mails (order_conf, the leftover
 * voucher…): the till hands the ticket over itself, and a shop whose mail cannot
 * be sent (no proc_open for sendmail) must not break the order half way.
 */
final class TpvOrderMailTest extends TestCase
{
    public function test_mail_is_blocked_only_while_a_till_order_is_created(): void
    {
        self::assertFalse(TpvOrderMail::blocks(), 'web orders send their e-mails');

        $seen = TpvOrderMail::withoutShopMail(static fn (): bool => TpvOrderMail::blocks());

        self::assertTrue($seen);
        self::assertFalse(TpvOrderMail::blocks(), 'released after the order');
    }

    public function test_it_is_released_when_the_order_fails_and_returns_the_result(): void
    {
        try {
            TpvOrderMail::withoutShopMail(static function (): void {
                throw new RuntimeException('validateOrder failed');
            });
            self::fail('The exception must go through.');
        } catch (RuntimeException $e) {
            self::assertSame('validateOrder failed', $e->getMessage());
        }
        self::assertFalse(TpvOrderMail::blocks());
        self::assertSame(['order_id' => 7], TpvOrderMail::withoutShopMail(static fn (): array => ['order_id' => 7]));
    }

    public function test_the_hook_that_blocks_it_is_registered_on_install_and_upgrade(): void
    {
        self::assertContains('actionEmailSendBefore', TpvConnectInstaller::HOOKS);
        $upgrade = (string) file_get_contents(__DIR__ . '/../../upgrade/upgrade-0.8.1.php');
        self::assertStringContainsString('registerHooks()', $upgrade);
        $module = (string) file_get_contents(__DIR__ . '/../../tpvconnect.php');
        self::assertMatchesRegularExpression('/function hookActionEmailSendBefore\(array \$params\): bool\s*\{\s*return !TpvOrderMail::blocks\(\);/', $module);
        $orders = (string) file_get_contents(__DIR__ . '/../../controllers/front/orders.php');
        self::assertMatchesRegularExpression('/TpvOrderMail::withoutShopMail\([^;]*validateOrder\(/s', $orders);
    }
}
