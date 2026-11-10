# Changelog

## 0.8.4

- New endpoint `POST /module/tpvconnect/codes` (HMAC) `{"id_product", "id_product_attribute", "ean13", "reference"}` for the TPV's «Modo edición»: writes the EAN-13 (empty, 8 or 13 digits) and the reference (up to 64 characters, none of `< > ; = { }`) of a product or a combination; empty or null clears it. Only those columns are written (`product` or `product_attribute`), then `actionProductUpdate` runs. Answers `200 {"id_product", "id_product_attribute", "ean13", "reference"}`, `404` for an unknown product or combination, `422` for an invalid body.
- Upgrade script `upgrade-0.8.4.php`: nothing to change in the shop.

## 0.8.3

- New endpoint `POST /module/tpvconnect/price` (HMAC like the others) `{"id_product", "id_product_attribute", "price", "vat_rate"}` for the TPV's «Modo edición». `price` is the price **with VAT** (0 to 99999.99) and `vat_rate` one of 21, 10, 4 or 0. For a product it stores `Product->price` = price / (1 + rate) and the tax rules group of that rate in the shop's default country (none for 0 %). For a combination it sets the product's tax rules group and the combination impact so that the combination's price with VAT is the one sent. Answers `200 {"id_product", "id_product_attribute", "price", "price_net", "vat_rate"}`, `404` for an unknown product or combination, `422` for an invalid body or a rate the shop has no tax rule for. Prices are absolute, so repeating a request is harmless. Specific prices (reductions) are not touched.
- Only the price columns are written (`product`/`product_shop` price and tax rules group, `product_attribute`/`product_attribute_shop` price), not the whole Product: PrestaShop validates every field on a full save, and a product whose short description is longer than its limit could otherwise never change price. Then the price cache is cleared and `actionProductUpdate` runs (a failure there is only logged: the price is saved).
- Upgrade script `upgrade-0.8.3.php`: nothing to change in the shop.

## 0.8.0

- Pairing with a code: nobody copies keys by hand any more. The configuration page has a button «Emparejar con un TPV» that shows a new 6-digit code (CSPRNG) and the shop address to type in the TPV installer (or in Ajustes → Acerca de → «Volver a emparejar con la tienda» of an installed TPV). The code lasts 10 minutes, works once and is stored only as a salted SHA-256 (`TPVCONNECT_PAIR_CODE`) with its expiry (`TPVCONNECT_PAIR_EXPIRES`) and an attempt counter (`TPVCONNECT_PAIR_ATTEMPTS`); 5 wrong codes invalidate it. Pressing the button again replaces the previous code.
- New public endpoint `POST /module/tpvconnect/pair` `{"code": "123456", "tpv_url": "https://tpv.tutienda.com"}` (HTTPS only: TLS the web server terminated, a client on the shop's own machine (`REMOTE_ADDR` `127.0.0.1`/`::1`; a reverse proxy there must send `X-Forwarded-Proto: https`), or a test shop whose own domain is `localhost` reached from a private network; the `Host` header and `X-Forwarded-Proto` of a remote client are not trusted). With a valid code it turns the webservice on if it was off, reuses the TPV's webservice key (`TPVCONNECT_WS_KEY_ID`: same value, made active, every permission of `WEBSERVICE_PERMISSIONS` it lacks added) or creates one when there is none, creates the SSO secret only if it is empty (it is never rotated), stores `TPVCONNECT_URL` (an `https` URL, or `http` on localhost; no user, query, fragment, whitespace, control character, quote, `<`, `>` or backtick), consumes the code and answers `200 {"api_key", "sso_secret", "shop_id", "shop_url", "currency_id", "timezone", "module_version"}`. A wrong, expired, used or missing code always answers the same `403 {"error": "invalid_code"}`; an invalid `tpv_url` answers `422 {"error": "invalid_tpv_url"}` without spending an attempt; plain HTTP answers `403 {"error": "https_required"}`. Codes are compared in constant time under a MySQL lock (`GET_LOCK('tpvconnect_pair')`, `503 busy` when held) that also covers issuing a code; under it the code, its expiry and its attempt counter are read straight from the `configuration` table (PrestaShop's static cache keeps values another request already deleted), so two requests never use the same code and a code is checked at most 5 times. Requests are limited per IP (`REMOTE_ADDR`, 60 per minute). Every answer is `Cache-Control: no-store`.
- PrestaShop's log (Parámetros avanzados → Registros) records each code issued (with the employee), each pairing (TPV address, the previous TPV address, IP, key id created or reused) and each code invalidated by wrong attempts. It never records the code, the key or the secret.
- The message after the first install no longer shows the SSO secret: it tells to pair instead. The manual fields (TPV URL, SSO secret, «Generar clave API») stay under «Configuración manual».
- Uninstalling removes the pairing configuration too.
- No upgrade script: nothing to register or migrate; the configuration values are created when a code is issued.

## 0.7.1

- Vouchers worth more than the sale (`POST /module/tpvconnect/orders` with `coupon`): when PrestaShop's `validateOrder` keeps the rest of a partial-use amount voucher as a new cart rule (code `<code>-2`, then `-3`…, the remaining amount, quantity 1, quantity per user 1, bound to the order's customer), the module finds exactly that rule (its code, created for this order after the original, for the order's customer, with the remaining amount; with free shipping, the rest minus the shipping), sets `id_customer = 0` so anyone can use it at the till or on the web, keeps the original's `date_to`, and answers it as `"leftover_voucher": {"id": 41, "code": "ABCD1234-2", "amount": 14.0, "expires_at": "2027-06-30 23:59:59"}`. Without a rest (fully spent, percentage or single-use voucher) it is `"leftover_voucher": null`. A replayed request returns the same voucher and changes nothing. A failure while publishing it never fails the order (`null`). The e-mail PrestaShop sends the customer with the new code is unchanged.
- Orders without `coupon` are unchanged.
- No upgrade script: nothing to migrate; PrestaShop records the new version.

## 0.7.0

- Free items (docs/prd/articulo-libre.md, A5): new signed endpoint `POST /module/tpvconnect/freeitem` `{"vat_rates": [21, 10]}` (HMAC like `orders`). For each rate it creates or checks a hidden generic product «Artículo libre TPV 21 %» (reference `TPV-LIBRE-21`; the shop's active tax rules group with that rate for its default country; `visibility = none`, active, available for order, out-of-stock orders allowed, not in the search index, base price 99 999 € so nobody can buy it online for nothing) with 20 combinations of the attribute «Línea TPV» 1..20, and answers `200 {"products": {"21.00": {"id_product": 21, "slots": {"1": 43, ...}}}}`. Running it again returns the same ids. A rate without a tax rules group in the shop answers 422 and creates nothing. The ids are stored in `TPVCONNECT_FREE_ITEM_PRODUCTS`.
- `POST /module/tpvconnect/orders` accepts free item lines `{"free_item": true, "id_product", "id_product_attribute", "quantity", "price" (with VAT), "vat_rate", "concept"}` on a slot of the generic product of that rate: the cart gets a specific price for that cart only (net price with 6 decimals, no reduction), so the order line costs exactly the TPV's price with VAT and carries the rate's tax. After `validateOrder` the order line is named after the concept and the cart's specific prices are deleted; the order confirmation e-mail shows the concept too (`actionPaymentModuleProductVarTplAfter`). A free line on a wrong slot, an unknown rate, a repeated slot, a missing price or concept, a coupon with a free line and a catalog line using a generic product answer 422. Orders without free lines are unchanged.
- The TPV's webservice key also gets `customers` POST (customers created at the till) and `cart_rules` GET, POST, HEAD (coupons created at the till; without them PrestaShop refused `GET /api/cart_rules?schema=blank` and the TPV showed «La creación de cupones no está disponible en este momento.»). A newly generated key gets the full list.
- Upgrade script `upgrade-0.7.0.php`: registers the e-mail hook and adds the missing permissions to the existing key (`TPVCONNECT_WS_KEY_ID`) without changing it. The generic products are not created by the upgrade: the TPV creates them (`bin/console tpv:free-item:setup`).

## 0.6.2

- PrestaShop 9 support: the module declares compatibility up to 9.99.99 (it stopped at 8.99.99, so PrestaShop 9.2 refused to install it).
- The technical customer «Consumidor final» gets its unusable password with `password_hash()`; `Tools::encrypt()` no longer exists in PrestaShop 9 and made the install fail there.
- The technical customer's address gets the placeholder `dni` `TPV` when the shop's default country needs an identification number (Spain does); before, the install failed with "Address->dni is empty".
- Reinstalling after an uninstall (the technical customer already exists) failed with "Non-static method CustomerCore::getByEmail() cannot be called statically"; the existing customer is now found by `customerExists()` and reused.
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
