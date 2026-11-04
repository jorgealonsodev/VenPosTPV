<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Session;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Session\SessionValue;

final class SessionValueTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function setUp(): void
    {
        $this->saved = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->saved === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->saved;
        }
    }

    public function testTheSymfonySessionWinsOverTheRawKey(): void
    {
        // SSO writes only the Symfony session; a raw key may be left from an earlier login.
        $_SESSION = ['_sf2_attributes' => ['shop_id' => 3, 'user_id' => '7'], 'shop_id' => 1, 'user_id' => 2];

        self::assertSame(3, SessionValue::int('shop_id'));
        self::assertSame(7, SessionValue::int('user_id'));
    }

    public function testTheRawKeyIsTheFallback(): void
    {
        $_SESSION = ['shop_id' => '2'];

        self::assertSame(2, SessionValue::int('shop_id'));
    }

    public function testMissingOrNonNumericValuesAreNull(): void
    {
        $_SESSION = ['_sf2_attributes' => ['user_id' => 'x']];

        self::assertNull(SessionValue::int('user_id'));
        self::assertNull(SessionValue::int('shop_id'));
        unset($_SESSION);
        self::assertNull(SessionValue::int('shop_id'));
    }
}
