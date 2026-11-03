<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Label;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Catalog\ProductSearchService;

/**
 * What a label says, from the synced catalog (docs/prd/agente-impresion.md L3, L4).
 * The price is the one the sale screen sells (ProductSearchService: product price
 * plus the combination's impact, VAT included, current offer applied by the sync),
 * so the label always matches the till. Products are read once per request.
 */
class LabelCatalog
{
    /** @var array<int, array<string, mixed>|null> */
    private array $products = [];

    public function __construct(
        private readonly ProductSearchService $productSearch,
        private readonly ?Connection $connection = null,
        private readonly BarcodeSelector $barcodes = new BarcodeSelector(),
    ) {
    }

    /**
     * A scanned or typed code on the Etiquetas screen (L2): the exact EAN or reference
     * of a combination, else of a product.
     *
     * @return array{product_id: int, id_product_attribute: int}|null
     */
    public function findByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || $this->connection === null) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT c.id_product, c.id FROM catalog_combination c JOIN catalog_product p ON p.id = c.id_product
             WHERE p.active = 1 AND (c.ean13 = ? OR c.reference = ?) ORDER BY c.ean13 = ? DESC, c.id LIMIT 1',
            [$code, $code, $code]
        );
        if ($row !== false) {
            return ['product_id' => (int) $row['id_product'], 'id_product_attribute' => (int) $row['id']];
        }
        $id = $this->connection->fetchOne(
            'SELECT id FROM catalog_product WHERE active = 1 AND (ean13 = ? OR reference = ?) ORDER BY ean13 = ? DESC, id LIMIT 1',
            [$code, $code, $code]
        );

        return $id !== false ? ['product_id' => (int) $id, 'id_product_attribute' => 0] : null;
    }

    /**
     * The product as the sale screen shows it, with its combinations and stock.
     *
     * @return array<string, mixed>|null
     */
    public function productData(int $productId): ?array
    {
        return $this->product($productId);
    }

    /**
     * @throws LabelException with the reason when the label cannot be printed
     */
    public function label(int $productId, int $attributeId): Label
    {
        $product = $this->product($productId);
        if ($product === null) {
            throw new LabelException('Producto no encontrado');
        }

        $price = (float) $product['price'];
        $combination = null;
        $text = null;
        if ($attributeId > 0) {
            foreach ((array) ($product['combinations'] ?? []) as $candidate) {
                if (\is_array($candidate) && (int) $candidate['id'] === $attributeId) {
                    $combination = $candidate;
                    break;
                }
            }
            if ($combination === null) {
                throw new LabelException('Combinación no encontrada');
            }
            $price = round($price + (float) ($combination['price_impact'] ?? 0), 2);
            $text = self::attributes((array) ($combination['attributes'] ?? []));
        }

        $reference = $combination !== null && trim((string) ($combination['reference'] ?? '')) !== ''
            ? (string) $combination['reference']
            : (string) ($product['reference'] ?? '');
        $barcode = $this->barcodes->select(
            $combination !== null ? (string) ($combination['ean13'] ?? '') : null,
            (string) ($product['ean13'] ?? ''),
            $reference
        );

        return new Label((string) $product['name'], $text, round($price, 2), $barcode);
    }

    /**
     * "M · Azul": the size first, then the other attributes in catalog order.
     *
     * @param array<mixed> $attributes
     */
    public static function attributes(array $attributes): ?string
    {
        $sizes = [];
        $others = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute) || trim((string) ($attribute['attribute_name'] ?? '')) === '') {
                continue;
            }
            $value = trim((string) $attribute['attribute_name']);
            if (preg_match('/talla|size|tama|n[uú]mero/iu', (string) ($attribute['group_name'] ?? '')) === 1) {
                $sizes[] = $value;
            } else {
                $others[] = $value;
            }
        }
        $values = [...$sizes, ...$others];

        return $values === [] ? null : implode(' · ', $values);
    }

    /** @return array<string, mixed>|null */
    private function product(int $productId): ?array
    {
        if (!\array_key_exists($productId, $this->products)) {
            $this->products[$productId] = $productId > 0 ? $this->productSearch->findById($productId) : null;
        }

        return $this->products[$productId];
    }
}
