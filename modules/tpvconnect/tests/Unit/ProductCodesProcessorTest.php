<?php

declare(strict_types=1);

namespace Tests\Unit\Module\TpvConnect;

use PHPUnit\Framework\TestCase;
use ProductCodesGateway;
use ProductCodesProcessor;
use RefundRequestException;

final class FakeProductCodesGateway implements ProductCodesGateway
{
    /** @var array<string, bool> */
    public array $items = ['10:0' => true, '10:55' => true];
    /** @var list<array{int, int, string, string}> */
    public array $saved = [];

    public function itemExists(int $productId, int $attributeId): bool
    {
        return $this->items[$productId . ':' . $attributeId] ?? false;
    }

    public function saveCodes(int $productId, int $attributeId, string $ean13, string $reference): void
    {
        $this->saved[] = [$productId, $attributeId, $ean13, $reference];
    }
}

/**
 * POST /module/tpvconnect/codes: the TPV's «Modo edición» writes the EAN and the
 * reference of a product or a combination in PrestaShop; empty clears them.
 */
final class ProductCodesProcessorTest extends TestCase
{
    private FakeProductCodesGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new FakeProductCodesGateway();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function process(array $data): array
    {
        return (new ProductCodesProcessor($this->gateway))->process($data);
    }

    public function test_a_product_and_a_combination_get_their_ean_and_reference(): void
    {
        $product = $this->process(['id_product' => 10, 'id_product_attribute' => 0, 'ean13' => '4006381333931', 'reference' => 'BAN-22']);
        $this->process(['id_product' => 10, 'id_product_attribute' => 55, 'ean13' => '96385074', 'reference' => 'BAN-22-M']);

        $this->assertSame([[10, 0, '4006381333931', 'BAN-22'], [10, 55, '96385074', 'BAN-22-M']], $this->gateway->saved);
        $this->assertSame(['id_product' => 10, 'id_product_attribute' => 0, 'ean13' => '4006381333931', 'reference' => 'BAN-22'], $product);
    }

    public function test_empty_or_null_clears_the_value_in_the_shop(): void
    {
        $this->process(['id_product' => 10, 'id_product_attribute' => 0, 'ean13' => null, 'reference' => '']);

        $this->assertSame([[10, 0, '', '']], $this->gateway->saved);
    }

    public function test_an_unknown_item_is_not_found(): void
    {
        $this->expectRejection(404, fn () => $this->process(['id_product' => 10, 'id_product_attribute' => 77, 'ean13' => '', 'reference' => '']));
        $this->assertSame([], $this->gateway->saved);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'ean with letters' => [['id_product' => 10, 'id_product_attribute' => 0, 'ean13' => '40063813339AB', 'reference' => '']];
        yield 'ean of 12 digits' => [['id_product' => 10, 'id_product_attribute' => 0, 'ean13' => '400638133393', 'reference' => '']];
        yield 'reference too long' => [['id_product' => 10, 'id_product_attribute' => 0, 'ean13' => '', 'reference' => str_repeat('R', 65)]];
        yield 'reference with forbidden characters' => [['id_product' => 10, 'id_product_attribute' => 0, 'ean13' => '', 'reference' => 'A<B>']];
        yield 'no product' => [['id_product_attribute' => 0, 'ean13' => '', 'reference' => '']];
    }

    /**
     * @dataProvider invalidBodies
     *
     * @param array<string, mixed> $body
     */
    public function test_an_invalid_body_is_422(array $body): void
    {
        $this->expectRejection(422, fn () => $this->process($body));
        $this->assertSame([], $this->gateway->saved);
    }

    private function expectRejection(int $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected rejection ' . $code);
        } catch (RefundRequestException $e) {
            $this->assertSame($code, $e->getHttpCode());
        }
    }
}
