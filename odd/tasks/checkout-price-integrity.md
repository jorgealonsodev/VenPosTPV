# Checkout price and total integrity
Objective: stop trusting client-supplied line prices and order totals so a manipulated browser cannot book a sale below its catalog price. Branch: `fix/checkout-price-integrity` (on top of `feat/parked-tickets`).
Delivery: feature-branch-chain inherited; runtime `.runtime/php-8.5.12/bin/php`.

## Specs
S1. "haz lo que consideres mas optimo" / "continua" (parent-identified gap: `POST /api/checkout` trusts `cart_data.total` and per-line `price`).

## Tasks
T1 | S1 | inline | done | `CheckoutService` now resolves unit prices from the synced catalog (+ combination impact), validates the global discount, recomputes subtotal/total and rejects mismatches with 422 before any cash or PrestaShop side effect; 7 new unit tests, checkout suite 22 tests/57 assertions GREEN; commit: 50420dc.
T2 | S1 | inline | done | Full PHP 8.5 suite 298 tests/729 assertions OK plus Node 7+4+4; UI contract checked against `CatalogPanel`; fallback documented; closure in this record.

## Log
L1. User: "haz lo que consideres mas optimo" then "continua", after the parked-tickets audit surfaced that checkout accepts client price/total.
L2. Current behavior: `CheckoutService::createOrder()` takes each line `price` and `cart_data.total` verbatim, sends them to PrestaShop, stores them in `order_meta.payment_breakdown` and derives the cash movement from them. A manipulated page could book any amount.
L3. Decision (bounded): resolve expected unit price from `catalog_product.price` (+ `catalog_combination.price_impact` when a combination is used); reject with 422 `Price mismatch...` when a known catalog price differs from the submitted price beyond 0.02; recompute subtotal/total from authoritative prices plus validated discounts (pct 0..100, amt clamped to subtotal); reject with 422 `Total mismatch...` when the submitted total differs beyond 0.02; use the authoritative values for the PS payload, `order_meta`, cash impact and the returned total.
L4. Documented trade-off: when the product is absent from the local catalog (sync pending), keep the previous behaviour of accepting the submitted price instead of blocking the sale, mirroring the existing stock fallback. This is recorded as a known limitation, not as full integrity, and remains a follow-up.
L5. Risk: item 3 (money/contract), item 1 (stored order data). No PS API calls, real sales or live DB writes during tests; mocks only. Existing admin cash session 10 and 16 orders must stay untouched.
L6. RED observed first: 4 new tests failed because the current code accepted manipulated price/total and reached `PsOrderClient::createOrder`. Implementation then added `resolveAuthoritativePricing()` (catalog unit price + `price_impact`, per-line discount clamp, validated global discount, tolerance 0.02) and `resolveCatalogUnitPrice()`. Checkout suite GREEN 22 tests/57 assertions.
L7. Verified the UI contract matches the new validation: `CatalogPanel.onCombinationSelect` sends `price = product.price + combo.price_impact`, exactly what the server now expects, so real sales are not blocked. Live HTTP probe returned 409 `cash_session_closed` because `CheckoutController` checks the cash session first; no PrestaShop order was attempted and no cash session was opened. Service-level proof remains the unit suite (controller 409 order is already covered by its own test).
L8. Trade-off kept explicit: when `catalog_product` has no row for the product (sync pending) the submitted price is still accepted, mirroring the existing stock fallback. Closing that gap needs a decision on blocking unsynced products and is left as a follow-up, not claimed as solved.
