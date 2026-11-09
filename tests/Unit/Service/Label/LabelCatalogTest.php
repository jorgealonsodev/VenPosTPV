<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Label;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Catalog\ProductSearchService;
use VenPosTPV\Service\Label\LabelBarcode;
use VenPosTPV\Service\Label\LabelCatalog;
use VenPosTPV\Service\Label\LabelException;

/**
 * docs/prd/agente-impresion.md L3, L4: what a label says, from the synced catalog:
 * the name, the combination ("M · Azul", size first), the current price with VAT
 * as the sale screen sells it (product price + combination impact) and the code.
 */
final class LabelCatalogTest extends TestCase
{
    private function catalog(): LabelCatalog
    {
        $search = $this->createMock(ProductSearchService::class);
        $search->method('findById')->willReturnCallback(static fn (int $id): ?array => match ($id) {
            7 => [
                'id' => 7, 'name' => 'Camiseta básica', 'reference' => 'CAM', 'ean13' => '4006381333931', 'price' => 20.0, 'stock' => 3,
                'combinations' => [
                    ['id' => 71, 'reference' => 'CAM-M-AZ', 'ean13' => '8412345678905', 'price_impact' => 3.14, 'stock' => 4,
                        'attributes' => [['group_name' => 'Color', 'attribute_name' => 'Azul'], ['group_name' => 'Talla', 'attribute_name' => 'M']]],
                    ['id' => 72, 'reference' => 'CAM-L-AZ', 'ean13' => '', 'price_impact' => 0.0, 'stock' => 0,
                        'attributes' => [['group_name' => 'Color', 'attribute_name' => 'Azul'], ['group_name' => 'Talla', 'attribute_name' => 'L']]],
                ],
            ],
            8 => ['id' => 8, 'name' => 'Gorra', 'reference' => '', 'ean13' => '', 'price' => 9.5, 'stock' => 1, 'combinations' => []],
            default => null,
        });

        return new LabelCatalog($search);
    }

    public function testACombinationLabelHasSizeFirstItsPriceAndItsEan(): void
    {
        $label = $this->catalog()->label(7, 71);

        self::assertSame('Camiseta básica', $label->name);
        self::assertSame('M · Azul', $label->combination);
        self::assertSame(23.14, $label->price);
        self::assertSame('23,14 €', $label->priceText());
        self::assertSame(LabelBarcode::EAN13, $label->barcode->type);
        self::assertSame('8412345678905', $label->barcode->data);
    }

    public function testACombinationWithoutEanUsesTheProductEan(): void
    {
        $label = $this->catalog()->label(7, 72);

        self::assertSame('L · Azul', $label->combination);
        self::assertSame('4006381333931', $label->barcode->data);
    }

    public function testAProductWithoutAnyCodeIsRefusedWithTheReason(): void
    {
        $this->expectException(LabelException::class);
        $this->expectExceptionMessage('Sin EAN ni referencia: no se puede imprimir la etiqueta');

        $this->catalog()->label(8, 0);
    }

    public function testAnUnknownProductOrCombinationIsRefused(): void
    {
        try {
            $this->catalog()->label(99, 0);
            self::fail('unknown product');
        } catch (LabelException $e) {
            self::assertSame('Producto no encontrado', $e->getMessage());
        }

        $this->expectExceptionMessage('Combinación no encontrada');
        $this->catalog()->label(7, 999);
    }

    /** Before Version00000050 (no catalog_override table) a code finds its item by the synced codes. */
    public function testWithoutTheOverrideTableACodeIsFoundByTheSyncedCodes(): void
    {
        $missing = new TableNotFoundException($this->createMock(DriverException::class), null);
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturnCallback(static function (string $sql) use ($missing): array|false {
            if (str_contains($sql, 'catalog_override')) {
                throw $missing;
            }

            return ['id_product' => 7, 'id' => 71];
        });

        $catalog = new LabelCatalog($this->createMock(ProductSearchService::class), $conn);

        self::assertSame(['product_id' => 7, 'id_product_attribute' => 71], $catalog->findByCode('8412345678905'));
    }
}
