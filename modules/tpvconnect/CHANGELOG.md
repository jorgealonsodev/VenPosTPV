# Changelog

## 0.6.2

- PrestaShop 9 support: the module declares compatibility up to 9.99.99 (it stopped at 8.99.99, so PrestaShop 9.2 refused to install it).
- The technical customer «Consumidor final» gets its unusable password with `password_hash()`; `Tools::encrypt()` no longer exists in PrestaShop 9 and made the install fail there.
- No upgrade script: nothing to migrate; PrestaShop records the new version.

## 0.6.1

- `POST /module/tpvconnect/orders` for a customer without an address (a customer created at the TPV): the temporary address now gets the placeholder `dni` `TPV` when the shop's default country needs an identification number (Spain does). Before, PrestaShop refused it ("Address->dni is empty") and the endpoint answered 500, which the TPV retried forever. Countries without that requirement get the same address as before; a customer with an address keeps using its first one.
- Order build failures that retrying cannot fix answer 422 `{"error": "Order validation failed", "details": "..."}` instead of 500: an address PrestaShop would refuse (`Temporary address is invalid: ...`) and a product the cart refuses (`Product could not be added to cart: <id_product>`). The TPV turns a 422 into an incident to correct and retry. Other failures stay 500.
- No upgrade script: nothing to migrate; PrestaShop records the new version.

## 0.6.0

- `POST /module/tpvconnect/orders` accepts an optional `reference`: the TPV ticket code, `^[A-Z]\d{8}$` (terminal letter, 2-digit year, 6 digits, e.g. `A26000123`). Any other value, `null` and `""` included, is a 422 (`details.reference`). Without `reference` the endpoint behaves exactly as before.
- The order is created with exactly that reference through the `actionGenerateDocumentReference` hook, which answers only for the order this request is creating; web orders keep PrestaShop's own references.
- The reference is the idempotency key: under a per-reference MySQL lock, if an order with it already exists, that order is returned (HTTP 200, same fields plus `"replayed": true`) and nothing is created; a fresh create answers 201 with `"replayed": false`. A concurrent request with the same reference waits for the lock and then replays, or gets 409 when the first one is still running after 10 s. Also applies to coupon checkouts.
- The upgrade registers the new hook; existing settings, secrets and states are kept.
- Additive contract: deploy before the TPV local-first sales change.

## 0.5.0

- Adds `POST /module/tpvconnect/orderlookup` (HMAC, rate limited) with `{"id_order": 77}` or `{"reference": "XKBKNABJK"}`: returns every matching order with its date, customer, whether the TPV created it, current state, delivery date (first state flagged as delivered, `null` when never delivered) and lines with the refundable quantity left after credit slips and the paid total per line (order discounts prorated by line total to the cent, remainder on the heaviest line, free shipping excluded). 404 when no order matches, 422 for an invalid body.
- Refunds accept an optional `target_state`: `refund` (default, unchanged) or `web_refund_pending`, which sets the new order state "Devuelto en tienda – reembolso pendiente" (`TPV_OS_REFUND_PENDING`) and leaves a private order note with `web_refund_amount` for the office. 422 before any slip when the state is not installed.
- The upgrade creates the new order state once; existing settings, secrets and states are kept.
- Additive contract: deploy before the TPV online-order refunds change.

## 0.4.0

- Adds `POST /module/tpvconnect/restock` (HMAC, rate limited) for TPV refunds without a ticket: adds each returned quantity as a delta to the live stock of the exact product combination, with no order or credit slip.
- Rejects unknown products or combinations (404) before any stock moves; 422 for an invalid body; 409 while the same request is in progress.
- Requests carry a `request_id`; a retried request replays the first result (HTTP 200) instead of restocking twice. An interrupted request replays as an unconfirmed restock, never as a second one.
- Additive contract: deploy before the TPV refunds-without-ticket change.

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
