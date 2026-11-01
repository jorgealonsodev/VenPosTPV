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
