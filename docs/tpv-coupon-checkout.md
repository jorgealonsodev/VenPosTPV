# TPV native coupon checkout

A TPV coupon is a native PrestaShop CartRule discount, not stored value or a tender balance. The POS asks TPV Connect for a signed five-minute preview, displays its authoritative gross, discount, and payable amount, then sends the same bound quote for native order creation.

## Mandatory release order

1. Deploy the compatible TPV Connect module release (`0.2.0` or later) first.
2. Confirm its `coupon_preview` operation works in an isolated test environment.
3. Set `TPV_COUPON_CHECKOUT_ENABLED=1` only in the intended TPV runtime configuration.
4. Restart/clear the application configuration cache as required by that runtime.

The gate defaults to `0`; `env.example` documents `TPV_COUPON_CHECKOUT_ENABLED=0` without enabling it in the running application. Do **not** enable it against an old module: an old `orders` endpoint may ignore `operation: coupon_preview` and create an order. Gate-off returns `coupon_unavailable` and does not contact PrestaShop.

## Cashier behavior

- Enter a coupon code and choose **Validar y aplicar**.
- The returned native discount determines the remaining payable amount; the code's apparent face value is never trusted.
- A coupon is a discount. Do not describe it as available balance/saldo.
- The remaining payable defaults to cash; card is available. A fully covered sale has `0.00€` remaining and creates no drawer movement.
- Changing the code, cart, quantities, prices, customer, discount, or total invalidates the preview. Validate again before confirmation.
- Manual TPV discounts cannot be combined with a native coupon until module support exists; remove the manual discount first.
- If a coupon checkout reports an uncertain result, do not retry. Reconcile the referenced native order before any new charge or preview.

## Accounting and limits

Order metadata records gross, native coupon reduction, payable, and cash/card tender separately. Bearer code and quote are not stored in local metadata or orphan logs. Drawer movement is only net collected cash, never the coupon's face value or change.

TPV Connect serializes module coupon redemption and native PrestaShop revalidates the rule at creation. This does not prove cross-channel atomicity with unrelated web checkout; native PrestaShop remains the authority for consumption.
