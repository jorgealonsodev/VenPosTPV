<?php
/**
 * Copyright (c) VenPosTPV. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/RefundProcessor.php';

/**
 * The PrestaShop operations the TPV's EAN and reference changes need, so the rules
 * can be tested without a shop. PsProductCodesGateway is the production one.
 */
interface ProductCodesGateway
{
    /** True when the product exists and, for $attributeId > 0, the combination belongs to it. */
    public function itemExists(int $productId, int $attributeId): bool;

    /** Writes the EAN-13 and reference of the product ($attributeId 0) or the combination. */
    public function saveCodes(int $productId, int $attributeId, string $ean13, string $reference): void;
}

/**
 * «Modo edición» of the TPV: the EAN and the reference of a product or of one of
 * its combinations, written in PrestaShop. Empty (or null) clears the value. The
 * values are absolute: repeating a request leaves the same codes.
 */
class ProductCodesProcessor
{
    public function __construct(private readonly ProductCodesGateway $gateway)
    {
    }

    /**
     * @param array<string, mixed> $data {id_product, id_product_attribute, ean13, reference}
     *
     * @return array{id_product: int, id_product_attribute: int, ean13: string, reference: string}
     *
     * @throws RefundRequestException
     */
    public function process(array $data): array
    {
        $errors = [];
        $productId = $data['id_product'] ?? null;
        if (!is_int($productId) || $productId <= 0) {
            $errors['id_product'] = 'Required positive integer';
        }
        $attributeId = $data['id_product_attribute'] ?? 0;
        if (!is_int($attributeId) || $attributeId < 0) {
            $errors['id_product_attribute'] = 'Must be a non-negative integer';
        }
        $ean13 = trim((string) ($data['ean13'] ?? ''));
        if ($ean13 !== '' && !preg_match('/^(\d{8}|\d{13})$/', $ean13)) {
            $errors['ean13'] = 'Empty, or 8 or 13 digits';
        }
        $reference = trim((string) ($data['reference'] ?? ''));
        // PrestaShop's Validate::isReference: up to 64 characters, none of <>;={}.
        if (mb_strlen($reference) > 64 || preg_match('/[<>;={}]/u', $reference)) {
            $errors['reference'] = 'Up to 64 characters, without < > ; = { }';
        }
        if ($errors !== []) {
            throw new RefundRequestException(422, 'Validation failed', $errors);
        }
        if (!$this->gateway->itemExists((int) $productId, (int) $attributeId)) {
            throw new RefundRequestException(404, 'Product not found', ['id_product' => 'Product or combination not found']);
        }

        $this->gateway->saveCodes((int) $productId, (int) $attributeId, $ean13, $reference);

        return ['id_product' => (int) $productId, 'id_product_attribute' => (int) $attributeId, 'ean13' => $ean13, 'reference' => $reference];
    }
}

/**
 * Production ProductCodesGateway. Only these columns are written, like the price:
 * a full Product save validates every other field and can refuse the change.
 */
class PsProductCodesGateway implements ProductCodesGateway
{
    public function itemExists(int $productId, int $attributeId): bool
    {
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }
        if ($attributeId === 0) {
            return true;
        }
        $combination = new Combination($attributeId);

        return Validate::isLoadedObject($combination) && (int) $combination->id_product === $productId;
    }

    public function saveCodes(int $productId, int $attributeId, string $ean13, string $reference): void
    {
        $db = Db::getInstance();
        $fields = ['ean13' => pSQL($ean13), 'reference' => pSQL($reference)];
        $saved = $attributeId === 0
            ? $db->update('product', $fields + ['date_upd' => date('Y-m-d H:i:s')], '`id_product` = ' . $productId)
            : $db->update('product_attribute', $fields, '`id_product_attribute` = ' . $attributeId . ' AND `id_product` = ' . $productId);
        if (!$saved) {
            throw new RuntimeException('The EAN and reference could not be saved');
        }
        try {
            Hook::exec('actionProductUpdate', ['id_product' => $productId, 'product' => new Product($productId)]);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('tpvconnect: codes saved for product ' . $productId . ', but refreshing the shop failed: ' . $e->getMessage(), 2);
        }
    }
}
