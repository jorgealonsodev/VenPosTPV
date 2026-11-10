# TPV Connect native coupon contract

`POST /module/tpvconnect/orders` remains HMAC-authenticated. Authentication is checked before a preview creates its PrestaShop cart or address. Coupon codes and quote tokens are request-body values only; the module does not log either value or put either in a URL.

## Preview

Send the normal order cart fields plus:

```json
{
  "operation": "coupon_preview",
  "id_customer": 12,
  "id_currency": 1,
  "id_shop": 1,
  "payment_method": "TPV Connect",
  "products": [{"id_product": 34, "id_product_attribute": 0, "quantity": 1}],
  "coupon_code": "SAVE10"
}
```

A successful preview is HTTP 200:

```json
{
  "operation": "coupon_preview",
  "cart_id": 123,
  "before_discount_total": 25.00,
  "discount_amount": 10.00,
  "total_paid": 15.00,
  "coupon": {"code": "SAVE10", "expires_at": "2026-12-31 23:59:59"},
  "quote": "base64url-payload.hmac-sha256"
}
```

Amounts are the native PrestaShop order-total currency amounts, rounded to two decimal places. The signed quote binds cart ID, customer, shop, currency, requested product quantities, native cart-rule ID/code/expiry, and all displayed amounts. It expires after five minutes. Preview uses native `CartRule::checkValidity($context, false, true)` and `Cart::addCartRule()`, but never calls `validateOrder`; it therefore does not consume the rule quantity or create an order.

## Create an order with a coupon

Send the same cart fields with the default `operation` (or `"operation":"order"`) and one `coupon` object:

```json
{
  "operation": "order",
  "id_customer": 12,
  "id_currency": 1,
  "id_shop": 1,
  "payment_method": "TPV Connect",
  "products": [{"id_product": 34, "id_product_attribute": 0, "quantity": 1}],
  "coupon": {
    "code": "SAVE10",
    "preview_cart_id": 123,
    "quote": "base64url-payload.hmac-sha256",
    "expected_total_paid": 15.00
  }
}
```

The module reuses that preview cart rather than creating another one. It checks the HMAC quote and expiry, input/cart context and product fingerprint, current product quantities, cart-rule identity/expiry/native eligibility, the attached rule's effective discount, and independently measured native pre-discount (`ONLY_PRODUCTS`) and payable (`BOTH`) totals before `validateOrder`. Any additional or changed cart-rule interaction fails closed. A changed, expired, forged, used, or amount-drifted coupon returns HTTP 422 before an order is created; the cashier must obtain a new preview.

Since 0.8.1 PrestaShop's own e-mails (`order_conf`, the `voucher` mail of a partial-use rest) are not sent while a till order is created: the `actionEmailSendBefore` hook answers false inside `TpvOrderMail::withoutShopMail()` around `validateOrder`. Web orders still send theirs. See [tpv-coupon-checkout.md](tpv-coupon-checkout.md#till-orders-do-not-send-prestashops-e-mails-module-081).

A successful order response includes `order_id`, `reference`, and `total_paid`. Existing non-coupon order requests preserve their previous payload and now also include `total_paid`.

## Retry and concurrency boundary

For coupon creation only, TPV Connect obtains bounded five-second, uncached MySQL `GET_LOCK`s keyed first by native cart-rule ID and then by preview cart ID. Lock acquisition failure returns HTTP 503 and creates no order; every acquired lock is released in `finally`, including an acquisition exception. The rule lock serializes module redemption attempts for one coupon across distinct quoted carts, while the cart lock orders exact retries. While holding both, an existing `Order::getIdByCartId()` is replayed only when its native `Order::getCartRules()` association contains the quoted cart-rule ID and tax-inclusive discount; otherwise it fails closed rather than invoking `validateOrder` again.

This is module-local coupon/cart serialization, not a global web/TPV transaction. Native PrestaShop still owns cart-rule consumption during `validateOrder`, and another checkout channel can race it. The module does not modify PrestaShop core, manually decrement/disable rules, call a cart-rule webservice update, or create a TPV balance. A native coupon remains a discount, not electronic tender or a reusable balance.
