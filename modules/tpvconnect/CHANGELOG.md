# Changelog

## 0.2.0

- Adds native PrestaShop coupon preview and order-time revalidation support.
- Corrects isolated coupon checkout tests for native validation, quantities, locks, and quote lineage.
- Native runtime behavior has not been proven in a live shop.
- Deployment sequence: update the module first, then explicitly enable the TPV coupon flag later.

Existing installations retain their settings, secrets, and order states.
