<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;

/**
 * Pairing a TPV with a 6-digit code (TPV Connect 0.8.0): the configuration page
 * issues the code, POST /module/tpvconnect/pair redeems it.
 */
final class PairingTest extends TestCase
{
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        \Configuration::$values = ['TPVCONNECT_SSO_SECRET' => str_repeat('s', 64), 'TPVCONNECT_WS_KEY_ID' => '0', 'TPVCONNECT_URL' => '', 'PS_WEBSERVICE' => '1'];
        \Configuration::$db = \Configuration::$values;
        \Db::$configurationReads = [];
        \WebserviceKey::$keys = [];
        \PrestaShopLogger::$logs = [];
        \Db::$inserted = [];
        \Db::$permissionRows = [];
        \CouponTestState::reset();
    }

    private function pairing(): \TpvPairing
    {
        return new \TpvPairing(
            new \TpvConnectInstaller(new \Module()),
            new class implements \TpvPairingShop {
                public function shopId(): int { return 1; }
                public function shopUrl(): string { return 'https://shop.example.com/'; }
                public function currencyId(): int { return 2; }
                public function timezone(): string { return 'Europe/Madrid'; }
            },
            '0.8.0',
            fn (): int => $this->now,
        );
    }

    /** @return array{0: int, 1: string} */
    private function errorOf(callable $call): array
    {
        try {
            $call();
        } catch (\TpvPairingException $e) {
            return [$e->getHttpCode(), $e->getErrorCode()];
        }
        self::fail('A TpvPairingException was expected.');
    }

    private function redeem(string $code, string $tpvUrl = 'https://tpv.example.com/'): array
    {
        return $this->pairing()->redeem(['code' => $code, 'tpv_url' => $tpvUrl], '203.0.113.7');
    }

    public function test_a_code_has_six_digits_lasts_ten_minutes_and_is_stored_only_as_a_hash(): void
    {
        $issued = $this->pairing()->issueCode(3);

        self::assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        self::assertSame($this->now + 600, $issued['expires_at']);
        $stored = (string) \Configuration::$values[\TpvPairing::CONFIG_CODE];
        self::assertStringNotContainsString($issued['code'], $stored);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}:[0-9a-f]{64}$/', $stored);
        self::assertSame($this->now + 600, (int) \Configuration::$values[\TpvPairing::CONFIG_EXPIRES]);
        self::assertSame(0, (int) \Configuration::$values[\TpvPairing::CONFIG_ATTEMPTS]);
        foreach (\PrestaShopLogger::$logs as $log) {
            self::assertStringNotContainsString($issued['code'], $log['message']);
        }
        self::assertStringContainsString('employee 3', \PrestaShopLogger::$logs[0]['message']);
    }

    public function test_codes_are_random_and_a_new_one_replaces_the_previous(): void
    {
        $codes = [];
        for ($i = 0; $i < 20; ++$i) {
            $codes[] = $this->pairing()->issueCode()['code'];
        }
        self::assertGreaterThan(15, \count(array_unique($codes)));

        $first = $this->pairing()->issueCode()['code'];
        $second = $this->pairing()->issueCode()['code'];
        if ($first !== $second) {
            self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($first)));
        }
        self::assertSame('0.8.0', $this->redeem($second)['module_version']);
    }

    public function test_the_right_code_returns_everything_the_tpv_env_needs(): void
    {
        $code = $this->pairing()->issueCode()['code'];

        $response = $this->redeem($code);

        self::assertSame(1, \count(\WebserviceKey::$keys));
        $key = reset(\WebserviceKey::$keys);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{32}$/', $key['key']);
        self::assertTrue($key['active']);
        self::assertSame([
            'api_key' => $key['key'],
            'sso_secret' => str_repeat('s', 64),
            'shop_id' => 1,
            'shop_url' => 'https://shop.example.com',
            'currency_id' => 2,
            'timezone' => 'Europe/Madrid',
            'module_version' => '0.8.0',
        ], $response);
        self::assertSame('https://tpv.example.com', \Configuration::$values['TPVCONNECT_URL']);
        self::assertSame((string) array_key_first(\WebserviceKey::$keys), (string) \Configuration::$values['TPVCONNECT_WS_KEY_ID']);
    }

    public function test_a_code_works_only_once(): void
    {
        $code = $this->pairing()->issueCode()['code'];
        $this->redeem($code);

        self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($code)));
        self::assertArrayNotHasKey(\TpvPairing::CONFIG_CODE, \Configuration::$values);
    }

    public function test_an_expired_code_is_refused_like_a_wrong_one(): void
    {
        $code = $this->pairing()->issueCode()['code'];
        $this->now += 601;

        self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($code)));
        self::assertSame([], \WebserviceKey::$keys);
        self::assertSame('', \Configuration::$values['TPVCONNECT_URL']);
    }

    public function test_no_code_issued_is_refused_with_the_same_error(): void
    {
        self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem('123456')));
    }

    public function test_five_wrong_attempts_invalidate_the_code(): void
    {
        $code = $this->pairing()->issueCode()['code'];
        $wrong = $code === '000000' ? '000001' : '000000';

        for ($i = 1; $i <= 4; ++$i) {
            self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($wrong)));
            self::assertSame($i, (int) \Configuration::$db[\TpvPairing::CONFIG_ATTEMPTS]);
        }
        // A malformed code is a wrong attempt too.
        self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem('abc')));

        self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($code)));
        self::assertSame([], \WebserviceKey::$keys);
        $last = end(\PrestaShopLogger::$logs);
        self::assertStringContainsString('invalidated', $last['message']);
        self::assertSame(2, $last['severity']);
    }

    public function test_spaces_in_the_typed_code_are_ignored(): void
    {
        $code = $this->pairing()->issueCode()['code'];

        self::assertSame('0.8.0', $this->redeem(' ' . substr($code, 0, 3) . ' ' . substr($code, 3) . ' ')['module_version']);
    }

    public function test_an_existing_key_is_reused_not_rotated_and_gets_the_full_permissions(): void
    {
        \WebserviceKey::$keys = [12 => ['key' => 'EXISTINGKEY0000000000000000000AB', 'active' => false, 'description' => 'TPV Connect API Key']];
        \Configuration::$values['TPVCONNECT_WS_KEY_ID'] = \Configuration::$db['TPVCONNECT_WS_KEY_ID'] = '12';
        \Db::$permissionRows = [['resource' => 'products', 'method' => 'GET']];
        $code = $this->pairing()->issueCode()['code'];

        $response = $this->redeem($code);

        self::assertSame('EXISTINGKEY0000000000000000000AB', $response['api_key']);
        self::assertSame([12], array_keys(\WebserviceKey::$keys));
        self::assertTrue(\WebserviceKey::$keys[12]['active']);
        $granted = array_map(static fn (array $row): string => $row['data']['resource'] . ' ' . $row['data']['method'] . ' ' . $row['data']['id_webservice_account'], \Db::$inserted);
        self::assertContains('cart_rules POST 12', $granted);
        self::assertContains('stock_availables PUT 12', $granted);
        self::assertNotContains('products GET 12', $granted);
        self::assertCount(array_sum(array_map('count', \TpvConnectInstaller::WEBSERVICE_PERMISSIONS)) - 1, $granted);
    }

    public function test_a_stale_key_id_gets_a_new_key_with_every_permission(): void
    {
        \Configuration::$values['TPVCONNECT_WS_KEY_ID'] = \Configuration::$db['TPVCONNECT_WS_KEY_ID'] = '99';
        $code = $this->pairing()->issueCode()['code'];

        $response = $this->redeem($code);

        $id = (int) \Configuration::$values['TPVCONNECT_WS_KEY_ID'];
        self::assertNotSame(99, $id);
        self::assertSame(\WebserviceKey::$keys[$id]['key'], $response['api_key']);
        self::assertCount(array_sum(array_map('count', \TpvConnectInstaller::WEBSERVICE_PERMISSIONS)), \Db::$inserted);
    }

    public function test_the_webservice_is_enabled_when_it_was_off(): void
    {
        \Configuration::$values['PS_WEBSERVICE'] = \Configuration::$db['PS_WEBSERVICE'] = '0';
        $code = $this->pairing()->issueCode()['code'];

        $this->redeem($code);

        self::assertSame('1', (string) \Configuration::$values['PS_WEBSERVICE']);
    }

    public function test_the_sso_secret_is_created_when_missing_and_never_rotated(): void
    {
        \Configuration::$values['TPVCONNECT_SSO_SECRET'] = \Configuration::$db['TPVCONNECT_SSO_SECRET'] = '';
        $code = $this->pairing()->issueCode()['code'];
        $secret = $this->redeem($code)['sso_secret'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        self::assertSame($secret, \Configuration::$values['TPVCONNECT_SSO_SECRET']);

        $again = $this->redeem($this->pairing()->issueCode()['code']);
        self::assertSame($secret, $again['sso_secret']);
    }

    public function test_the_tpv_url_must_be_https_except_localhost(): void
    {
        foreach (['http://tpv.example.com', 'ftp://tpv.example.com', 'tpv.example.com', '', 'https://user:pw@tpv.example.com', 'https://tpv.example.com/?a=1', 'https://tpv.example.com/#x', "https://tpv.example.com/\nX",
            "https://tpv.example.com/a'b", 'https://tpv.example.com/a"b', 'https://tpv.example.com/<b>', 'https://tpv.example.com/a>b', 'https://tpv.example.com/a`b',
            "https://tpv.example.com/a\tb", 'https://tpv.example.com/a b', "https://tpv.exam\x01ple.com", "https://tpv.example.com/\x7F", "https://tpv.example.com/a\u{00A0}b", "https://tpv.example.com:443'/"] as $bad) {
            self::assertNull(\TpvPairing::normalizeTpvUrl($bad), $bad);
        }
        self::assertSame('https://tpv.example.com/tpv', \TpvPairing::normalizeTpvUrl('https://tpv.example.com/tpv/'));
        self::assertSame('http://localhost:8080', \TpvPairing::normalizeTpvUrl('http://localhost:8080'));
        self::assertSame('http://127.0.0.1', \TpvPairing::normalizeTpvUrl('http://127.0.0.1/'));
    }

    public function test_an_invalid_tpv_url_is_refused_before_the_code_and_costs_no_attempt(): void
    {
        $code = $this->pairing()->issueCode()['code'];

        self::assertSame([422, 'invalid_tpv_url'], $this->errorOf(fn () => $this->redeem($code, 'http://tpv.example.com')));
        self::assertSame(0, (int) \Configuration::$values[\TpvPairing::CONFIG_ATTEMPTS]);
        self::assertSame('0.8.0', $this->redeem($code)['module_version']);
    }

    public function test_only_https_requests_are_accepted_except_from_this_machine(): void
    {
        $public = 'shop.example.com';
        self::assertTrue(\TpvPairing::isRequestAllowed(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.7'], $public));
        self::assertTrue(\TpvPairing::isRequestAllowed(['HTTPS' => '1', 'REMOTE_ADDR' => '203.0.113.7'], $public));
        self::assertFalse(\TpvPairing::isRequestAllowed(['HTTPS' => 'off', 'REMOTE_ADDR' => '203.0.113.7'], $public));
        self::assertFalse(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '203.0.113.7'], $public));
        // A client on this machine, without a proxy in between.
        self::assertTrue(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '127.0.0.1'], $public));
        self::assertTrue(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '::1'], $public));
    }

    public function test_the_host_header_and_forwarded_headers_of_a_remote_client_are_not_trusted(): void
    {
        $public = 'shop.example.com';
        foreach ([
            ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_HOST' => 'localhost'],
            ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_HOST' => '127.0.0.1:8092'],
            ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_SSL' => 'on'],
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['HTTP_HOST' => 'localhost'],
        ] as $server) {
            self::assertFalse(\TpvPairing::isRequestAllowed($server, $public), json_encode($server));
        }
    }

    public function test_a_reverse_proxy_on_this_machine_must_say_the_client_used_https(): void
    {
        $public = 'shop.example.com';
        self::assertTrue(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https'], $public));
        self::assertFalse(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'http'], $public));
        self::assertFalse(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'], $public));
    }

    public function test_a_local_test_shop_accepts_http_from_its_private_network_only(): void
    {
        // A Docker shop configured as localhost:8092 sees the host as 172.x.0.1.
        self::assertTrue(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '172.26.0.1', 'HTTP_HOST' => 'localhost:8092'], 'localhost:8092'));
        self::assertTrue(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '192.168.1.20'], '127.0.0.1'));
        self::assertFalse(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '203.0.113.7'], 'localhost:8092'));
        self::assertFalse(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '172.26.0.1', 'HTTP_HOST' => 'localhost:8092'], 'shop.example.com'));
        self::assertFalse(\TpvPairing::isRequestAllowed(['REMOTE_ADDR' => '172.26.0.1'], 'localhost.evil.test'));
    }

    public function test_a_code_another_request_used_is_refused_even_from_a_stale_cache(): void
    {
        $code = $this->pairing()->issueCode()['code'];
        // A second request loaded the configuration before the first one used the code.
        $staleCache = \Configuration::$values;

        $this->redeem($code);
        \Configuration::$values = $staleCache;

        self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($code)));
        self::assertSame(1, \count(\PrestaShopLogger::$logs) - 1, 'only one pairing is logged');
        foreach (\Db::$configurationReads as $read) {
            self::assertFalse($read['use_cache'], 'the code is read past the SQL cache');
        }
    }

    public function test_wrong_codes_from_stale_caches_are_checked_five_times_at_most(): void
    {
        $code = $this->pairing()->issueCode()['code'];
        $wrong = $code === '000000' ? '000001' : '000000';
        // Every request loaded the configuration before any of them counted an attempt.
        $staleCache = \Configuration::$values;

        for ($i = 1; $i <= 12; ++$i) {
            \Configuration::$values = $staleCache;
            self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($wrong)));
            if ($i < 5) {
                self::assertSame($i, (int) \Configuration::$db[\TpvPairing::CONFIG_ATTEMPTS]);
            }
        }
        \Configuration::$values = $staleCache;

        self::assertSame([403, 'invalid_code'], $this->errorOf(fn () => $this->redeem($code)), 'the right code no longer works');
        self::assertSame([], \WebserviceKey::$keys);
        $invalidated = array_filter(\PrestaShopLogger::$logs, static fn (array $log): bool => str_contains($log['message'], 'invalidated'));
        self::assertCount(1, $invalidated);
        self::assertStringContainsString('after 5 wrong attempts', (string) reset($invalidated)['message']);
    }

    public function test_issuing_a_code_waits_for_the_pairing_lock(): void
    {
        \CouponTestState::$heldLocks['tpvconnect_pair'] = true;

        self::assertSame([503, 'busy'], $this->errorOf(fn () => $this->pairing()->issueCode()));
        self::assertArrayNotHasKey(\TpvPairing::CONFIG_CODE, \Configuration::$db);
    }

    public function test_re_pairing_logs_the_previous_tpv_address(): void
    {
        \Configuration::$values['TPVCONNECT_URL'] = 'https://old-tpv.example.com';
        \Configuration::$db['TPVCONNECT_URL'] = 'https://old-tpv.example.com';
        $code = $this->pairing()->issueCode()['code'];

        $this->redeem($code, 'https://new-tpv.example.com');

        $last = end(\PrestaShopLogger::$logs);
        self::assertStringContainsString('https://new-tpv.example.com', $last['message']);
        self::assertStringContainsString('previous TPV address https://old-tpv.example.com', $last['message']);
    }

    public function test_a_first_pairing_says_there_was_no_previous_tpv_address(): void
    {
        $this->redeem($this->pairing()->issueCode()['code']);

        self::assertStringContainsString('previous TPV address none', end(\PrestaShopLogger::$logs)['message']);
    }

    public function test_a_busy_lock_refuses_without_touching_the_code(): void
    {
        $code = $this->pairing()->issueCode()['code'];
        \CouponTestState::$heldLocks['tpvconnect_pair'] = true;

        self::assertSame([503, 'busy'], $this->errorOf(fn () => $this->redeem($code)));
        unset(\CouponTestState::$heldLocks['tpvconnect_pair']);
        self::assertSame('0.8.0', $this->redeem($code)['module_version']);
        self::assertSame([], \CouponTestState::$acquiredLocks, 'the lock is released');
    }

    public function test_the_audit_log_names_the_tpv_and_the_ip_but_no_secret(): void
    {
        $code = $this->pairing()->issueCode()['code'];
        $response = $this->redeem($code);

        $last = end(\PrestaShopLogger::$logs);
        self::assertStringContainsString('https://tpv.example.com', $last['message']);
        self::assertStringContainsString('203.0.113.7', $last['message']);
        foreach (\PrestaShopLogger::$logs as $log) {
            self::assertStringNotContainsString($response['api_key'], $log['message']);
            self::assertStringNotContainsString($response['sso_secret'], $log['message']);
            self::assertStringNotContainsString($code, $log['message']);
        }
    }

    public function test_uninstall_removes_the_pairing_state(): void
    {
        $this->pairing()->issueCode();
        (new \TpvConnectInstaller(new \Module()))->uninstallConfiguration();

        self::assertArrayNotHasKey(\TpvPairing::CONFIG_CODE, \Configuration::$values);
        self::assertArrayNotHasKey(\TpvPairing::CONFIG_EXPIRES, \Configuration::$values);
        self::assertArrayNotHasKey(\TpvPairing::CONFIG_ATTEMPTS, \Configuration::$values);
    }

    public function test_every_pairing_class_is_declared_when_the_file_is_loaded(): void
    {
        // A class_exists() include guard sees the compile-time-hoisted TpvPairing
        // and returned before PsTpvPairingShop was declared (500 in a real shop).
        self::assertTrue(class_exists('PsTpvPairingShop', false));
        self::assertTrue(class_exists('TpvPairingException', false));
        self::assertTrue(interface_exists('TpvPairingShop', false));
    }

    public function test_the_pair_front_controller_is_public_https_only_and_no_store(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/pair.php');

        self::assertStringContainsString('class TpvconnectPairModuleFrontController', $source);
        self::assertStringContainsString("'Cache-Control: no-store'", $source);
        self::assertStringContainsString('TpvPairing::isRequestAllowed', $source);
        self::assertStringContainsString("REMOTE_ADDR", $source);
        self::assertStringContainsString('TpvPairing::isRequestAllowed($_SERVER', $source);
        self::assertStringNotContainsString('HTTP_HOST', $source, 'the Host header is the client\'s to choose');
        self::assertStringNotContainsString('usingSecureMode', $source, 'it trusts X-Forwarded-Proto from anyone');
        self::assertStringNotContainsString('HTTP_X_FORWARDED_FOR', $source, 'throttling must not trust a spoofable header');
    }

    public function test_the_configuration_page_offers_the_pairing_button(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/configure.tpl');
        $php = (string) file_get_contents(dirname(__DIR__, 2) . '/tpvconnect.php');

        self::assertStringContainsString('submit_tpvconnect_pair', $tpl);
        self::assertStringContainsString('Emparejar con un TPV', $tpl);
        self::assertStringContainsString("Tools::isSubmit('submit_tpvconnect_pair')", $php);
        self::assertStringContainsString('issuePairingCode', $php);
    }
}
