<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Fiscal\Journal;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Fiscal\Journal\CanonicalJson;

/**
 * D2: line_hash = SHA-256 hex of the canonical JSON of the line without line_hash.
 * The shop module (TPV Connect) checks the same hash, so both sides must agree
 * byte for byte on the vector the module publishes.
 */
final class CanonicalJsonTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../../modules/tpvconnect/tests/fixtures/';

    /** @return array<string, mixed> */
    private function vector(string $name = 'journal-line-vector.json'): array
    {
        $this->assertFileExists(self::FIXTURES . $name, 'the module publishes the canonical-JSON vectors');

        return json_decode((string) file_get_contents(self::FIXTURES . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheModuleVectorEncodesAndHashesByteForByte(): void
    {
        $vector = $this->vector();

        $this->assertSame($vector['canonical_json'], CanonicalJson::encode($vector['line_without_hash']));
        $this->assertSame('5d49cad2aaab27a70353937303d5091dce72dc74f24f501096938f55c8a9b74f', CanonicalJson::lineHash($vector['line_without_hash']));
        $this->assertSame($vector['line_hash'], CanonicalJson::lineHash($vector['line_without_hash']));
    }

    public function testTheModuleCouponVectorKeepsItsNullCounter(): void
    {
        $vector = $this->vector('journal-line-vector-coupon.json');

        $this->assertNull($vector['line_without_hash']['counter']);
        $this->assertSame('PS', $vector['line_without_hash']['series']);
        $this->assertStringContainsString('"counter":null', CanonicalJson::encode($vector['line_without_hash']));
        if (isset($vector['canonical_json'])) {
            $this->assertSame($vector['canonical_json'], CanonicalJson::encode($vector['line_without_hash']));
        }
        $this->assertSame('6a1ef5b321a4f188e6f89d57bd02ec45cb1bca63b06e7aa8bbe69f8b309742f9', CanonicalJson::lineHash($vector['line_without_hash']));
        $this->assertSame($vector['line_hash'], CanonicalJson::lineHash($vector['line_without_hash']));
    }

    public function testTheLineHashIgnoresAnExistingLineHashKey(): void
    {
        $vector = $this->vector();
        $line = $vector['line_without_hash'] + ['line_hash' => 'whatever'];

        $this->assertSame($vector['line_hash'], CanonicalJson::lineHash($line));
    }

    public function testKeysAreSortedAtEveryDepthAndListsKeepTheirOrder(): void
    {
        $this->assertSame(
            '{"a":[{"x":1,"y":2},3,1],"b":{"c":null,"d":"é/€"},"z":1.0}',
            CanonicalJson::encode(['z' => 1.0, 'b' => ['d' => 'é/€', 'c' => null], 'a' => [['y' => 2, 'x' => 1], 3, 1]])
        );
    }

    public function testKeysSortByByteOrder(): void
    {
        $this->assertSame('{"10":1,"9":2,"B":3,"a":4}', CanonicalJson::encode(['a' => 4, 'B' => 3, '9' => 2, '10' => 1]));
    }
}
