# Changelog

## 0.3.0

- Fixes refunds: credit slips are created through the real `OrderSlip::create` contract for the amount the customer actually paid.
- Caps each refunded quantity at what is still refundable and each amount at the line price.
- Restocks the exact product combination per line, as a delta on the live quantity, when the TPV asks for it.
- Refund requests carry a `request_id`; a retried request replays the first result (HTTP 200) instead of refunding twice. 409 means another refund of the same order is in progress.
- Breaking contract: deploy together with the TPV refund changes (`feat/tpv-refunds`).

## 0.2.0

- Adds native PrestaShop coupon preview and order-time revalidation support.
- Corrects isolated coupon checkout tests for native validation, quantities, locks, and quote lineage.
- Native runtime behavior has not been proven in a live shop.
- Deployment sequence: update the module first, then explicitly enable the TPV coupon flag later.

Existing installations retain their settings, secrets, and order states.
