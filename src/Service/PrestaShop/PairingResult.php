<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrestaShop;

/** What the shop answered to a pairing code, already validated by PairingClient. */
final class PairingResult
{
    public function __construct(
        public readonly string $shopUrl,
        #[\SensitiveParameter] public readonly string $apiKey,
        #[\SensitiveParameter] public readonly string $ssoSecret,
        public readonly int $shopId,
        public readonly int $currencyId,
        public readonly string $timezone,
        public readonly string $moduleVersion,
    ) {
    }

    /**
     * The .env values the shop decides: the webservice key, the SSO secret (also
     * the HMAC key of the signed module endpoints) and the shop's ids. Pairing
     * only succeeds with a module that knows coupon_preview (PairingClient checks
     * MIN_MODULE_VERSION), so it also turns coupon checkout on.
     *
     * @return array<string, string>
     */
    public function envValues(): array
    {
        return [
            'PS_SHOP_URL' => $this->shopUrl,
            'PS_API_KEY' => $this->apiKey,
            'SSO_SHARED_SECRET' => $this->ssoSecret,
            'PS_DEFAULT_SHOP' => (string) $this->shopId,
            'PS_DEFAULT_CURRENCY' => (string) $this->currencyId,
            'PS_SHOP_TIMEZONE' => $this->timezone,
            'TPV_COUPON_CHECKOUT_ENABLED' => '1',
        ];
    }
}
