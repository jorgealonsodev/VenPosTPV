<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Share;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Share\ShareLinkSigner;

/**
 * The expiring links sent by WhatsApp: an HMAC of the document type, its id and
 * the expiry, so nobody can guess, change or extend one.
 */
final class ShareLinkSignerTest extends TestCase
{
    private const SECRET = 'a-64-char-installer-secret-0123456789abcdef0123456789abcdef0123';
    private const NOW = 1_794_000_000;

    private function signer(int $now = self::NOW, string $secret = self::SECRET): ShareLinkSigner
    {
        return new ShareLinkSigner($secret, 'https://tpv.example.com/tpv/', static fn (): int => $now);
    }

    public function testAValidTokenOfEachTypeGivesBackItsDocument(): void
    {
        foreach (['ticket', 'invoice', 'coupon'] as $type) {
            $token = $this->signer()->sign($type, 42);

            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $token);
            self::assertSame(['type' => $type, 'id' => 42], $this->signer()->verify($token), $type);
        }
    }

    public function testTheLinkIsThePublicTpvUrlWithTheToken(): void
    {
        $url = $this->signer()->url('ticket', 7);

        self::assertStringStartsWith('https://tpv.example.com/tpv/d/', $url);
        self::assertSame(['type' => 'ticket', 'id' => 7], $this->signer()->verify(substr($url, \strlen('https://tpv.example.com/tpv/d/'))));
    }

    public function testATamperedTokenIsRefused(): void
    {
        $token = $this->signer()->sign('ticket', 42);
        [$payload, $mac] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode('ticket:43:' . (self::NOW + 30 * 86400)), '+/', '-_'), '=');

        self::assertNull($this->signer()->verify($forged . '.' . $mac), 'another id with the old signature');
        self::assertNull($this->signer()->verify($payload . '.' . strrev($mac)), 'a changed signature');
        self::assertNull($this->signer()->verify($payload), 'no signature');
        self::assertNull($this->signer()->verify(''), 'empty');
        self::assertNull($this->signer()->verify($token . '.x'), 'extra part');
        self::assertNull($this->signer(secret: 'another-secret-another-secret-another-secret')->verify($token), 'another installation');
    }

    public function testAnExpiredTokenIsRefusedAndAFreshOneLasts30Days(): void
    {
        $token = $this->signer()->sign('invoice', 9);

        self::assertNotNull($this->signer(self::NOW + 30 * 86400)->verify($token));
        self::assertNull($this->signer(self::NOW + 30 * 86400 + 1)->verify($token));
    }

    public function testAnUnknownTypeIsNeitherSignedNorAccepted(): void
    {
        $signer = $this->signer();
        try {
            $signer->sign('cash-report', 1);
            self::fail('an unknown type must not be signed');
        } catch (\InvalidArgumentException) {
        }

        // A correctly signed payload of another type (or a non-positive id) is still refused.
        $mac = static fn (string $payload): string => rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, hash_hmac('sha256', ShareLinkSigner::KEY_CONTEXT, self::SECRET, true), true)), '+/', '-_'), '=');
        $b64 = static fn (string $payload): string => rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        foreach (['cash-report:1:' . (self::NOW + 60), 'ticket:0:' . (self::NOW + 60), 'ticket:-1:' . (self::NOW + 60)] as $payload) {
            self::assertNull($signer->verify($b64($payload) . '.' . $mac($payload)), $payload);
        }
        self::assertSame(['type' => 'coupon', 'id' => 3], $signer->verify($b64('coupon:3:' . (self::NOW + 60)) . '.' . $mac('coupon:3:' . (self::NOW + 60))), 'the forging helper matches the scheme');
    }

    public function testWithoutASecretNoLinkIsSigned(): void
    {
        $this->expectException(\LogicException::class);

        $this->signer(secret: '')->sign('ticket', 1);
    }
}
