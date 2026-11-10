<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Coupon;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VenPosTPV\Service\Coupon\CouponRejectionReason;
use VenPosTPV\Service\PrestaShop\CouponStatusLookup;

/**
 * When the module rejects a coupon at «Validar y aplicar», the cashier is told
 * why in Spanish, from the coupon's state in PrestaShop.
 */
final class CouponRejectionReasonTest extends TestCase
{
    private const NOW = '2026-11-10 12:30:00';

    /** @param list<string> $urls */
    private function reason(string|\Throwable $answer, array &$urls = []): CouponRejectionReason
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($answer, &$urls): MockResponse {
            $urls[] = urldecode($url);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return new MockResponse($answer);
        });

        return new CouponRejectionReason(new CouponStatusLookup('https://shop.example.com', 'KEY', $http), 'Europe/Madrid');
    }

    private static function rule(array $over): string
    {
        return (string) json_encode(['cart_rules' => [$over + [
            'id' => 4, 'code' => 'R24GCYH4', 'quantity' => '1', 'active' => '1',
            'date_from' => '2026-11-10 10:50:32', 'date_to' => '2027-11-10 23:59:59',
        ]]]);
    }

    private function explain(string|\Throwable $answer, string $code = 'R24GCYH4'): string
    {
        return $this->reason($answer)->explain($code, new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Madrid')));
    }

    public function testAUsedCouponSaysSo(): void
    {
        $urls = [];
        $message = $this->reason(self::rule(['quantity' => '0']), $urls)->explain('R24GCYH4', new \DateTimeImmutable(self::NOW));

        self::assertSame('Este cupón ya se ha usado.', $message);
        self::assertStringContainsString('/api/cart_rules?', $urls[0]);
        self::assertStringContainsString('filter[code]=[R24GCYH4]', $urls[0]);
    }

    public function testEachStateHasItsSentence(): void
    {
        self::assertSame('Este código no existe en la tienda. Revisa que esté bien escrito.', $this->explain('[]'));
        self::assertSame('Este cupón está desactivado en la tienda.', $this->explain(self::rule(['active' => '0'])));
        self::assertSame('Este cupón caducó el 01/11/2026.', $this->explain(self::rule(['date_to' => '2026-11-01 23:59:59'])));
        self::assertSame('Este cupón todavía no es válido: empieza el 15/11/2026.', $this->explain(self::rule(['date_from' => '2026-11-15 00:00:00'])));
        self::assertSame(CouponRejectionReason::GENERIC, $this->explain(self::rule([])), 'valid in itself: a customer, minimum or product restriction');
    }

    public function testWithoutAnAnswerOrWithAnOddCodeTheGenericSentenceIsUsed(): void
    {
        self::assertSame(CouponRejectionReason::GENERIC, $this->explain(new \RuntimeException('down')));
        $urls = [];
        $message = $this->reason(self::rule(['quantity' => '0']), $urls)->explain('AB|CD]', new \DateTimeImmutable(self::NOW));
        self::assertSame(CouponRejectionReason::GENERIC, $message);
        self::assertSame([], $urls, 'a code that could change the filter is never sent');
    }
}
