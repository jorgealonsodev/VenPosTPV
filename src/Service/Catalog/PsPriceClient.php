<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Catalog;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use VenPosTPV\Service\Catalog\Exception\CatalogEditException;

/**
 * Writes what «Modo edición» changes in PrestaShop through the TPV Connect module,
 * signed like the other module calls: a product's or combination's price (with
 * VAT) and the product's VAT rate (POST /module/tpvconnect/price, module 0.8.3),
 * and its EAN and reference (POST /module/tpvconnect/codes, module 0.8.4).
 * Nothing changes locally when this fails.
 */
class PsPriceClient
{
    private readonly string $shopUrl;

    public function __construct(
        string $shopUrl,
        private readonly string $sharedSecret,
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->shopUrl = rtrim($shopUrl, '/');
    }

    /**
     * @return array{price_net: float}
     *
     * @throws CatalogEditException
     */
    public function setPrice(int $productId, int $attributeId, float $price, float $vatRate): array
    {
        $body = json_encode(['id_product' => $productId, 'id_product_attribute' => $attributeId, 'price' => $price, 'vat_rate' => $vatRate], JSON_THROW_ON_ERROR);
        try {
            $response = $this->httpClient->request('POST', $this->shopUrl . '/module/tpvconnect/price', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'HMAC-SHA256 ' . hash_hmac('sha256', $body, $this->sharedSecret),
                ],
                'body' => $body,
                'timeout' => 30,
                'max_duration' => 30,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new CatalogEditException('No se ha podido conectar con PrestaShop; no se ha cambiado nada', 502, 'prestashop_price_failed', $e);
        }

        $data = json_decode($content, true);
        if ($status === 200 && \is_array($data) && is_numeric($data['price_net'] ?? null)) {
            return ['price_net' => (float) $data['price_net']];
        }
        if ($status === 404 && \is_array($data)) {
            // The module's own answer; a module without this endpoint answers PrestaShop's HTML 404.
            throw new CatalogEditException('PrestaShop no tiene este artículo: sincroniza el catálogo', 404, 'prestashop_not_found');
        }
        if ($status === 422 && \is_array($data) && \is_array($data['details'] ?? null) && isset($data['details']['vat_rate'])) {
            throw CatalogEditException::invalid('La tienda no tiene configurado ese tipo de IVA', 'prestashop_no_tax_rule');
        }

        throw new CatalogEditException(
            $status === 404
                ? 'El módulo de PrestaShop no admite cambiar precios: actualízalo a la versión 0.8.3'
                : 'PrestaShop no ha aceptado el cambio de precio; no se ha cambiado nada',
            502,
            'prestashop_price_failed'
        );
    }

    /**
     * Writes the EAN and reference of a product or combination; null clears them.
     *
     * @throws CatalogEditException
     */
    public function setCodes(int $productId, int $attributeId, ?string $ean13, ?string $reference): void
    {
        $body = json_encode(['id_product' => $productId, 'id_product_attribute' => $attributeId, 'ean13' => $ean13 ?? '', 'reference' => $reference ?? ''], JSON_THROW_ON_ERROR);
        try {
            $response = $this->httpClient->request('POST', $this->shopUrl . '/module/tpvconnect/codes', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'HMAC-SHA256 ' . hash_hmac('sha256', $body, $this->sharedSecret),
                ],
                'body' => $body,
                'timeout' => 30,
                'max_duration' => 30,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            throw new CatalogEditException('No se ha podido conectar con PrestaShop; no se ha cambiado nada', 502, 'prestashop_codes_failed', $e);
        }

        if ($status === 200 && \is_array($data)) {
            return;
        }
        if ($status === 404 && \is_array($data)) {
            throw new CatalogEditException('PrestaShop no tiene este artículo: sincroniza el catálogo', 404, 'prestashop_not_found');
        }
        if ($status === 422 && \is_array($data) && \is_array($data['details'] ?? null)) {
            throw CatalogEditException::invalid(isset($data['details']['ean13']) ? 'PrestaShop no acepta ese EAN' : 'PrestaShop no acepta esa referencia', 'prestashop_codes_invalid');
        }

        throw new CatalogEditException(
            $status === 404
                ? 'El módulo de PrestaShop no admite cambiar el EAN y la referencia: actualízalo a la versión 0.8.4'
                : 'PrestaShop no ha aceptado el cambio de EAN o referencia; no se ha cambiado nada',
            502,
            'prestashop_codes_failed'
        );
    }
}
