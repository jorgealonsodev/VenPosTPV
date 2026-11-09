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
- A coupon is a discount. Its only «saldo» is the rest of an amount voucher worth more than the sale (below), which is a new voucher, never a tender balance.
- The remaining payable defaults to cash; card is available. A fully covered sale has `0.00€` remaining and creates no drawer movement.
- Changing the code, cart, quantities, prices, customer, discount, or total invalidates the preview. Validate again before confirmation.
- Manual TPV discounts cannot be combined with a native coupon until module support exists; remove the manual discount first.
- If a coupon checkout reports an uncertain result, do not retry. Reconcile the referenced native order before any new charge or preview.
- **PrestaShop not answering (PRD V5, `coupon_offline`).** A coupon is only accepted while PrestaShop answers. When the preview cannot reach PrestaShop (network error, timeout, HTTP 502/503/504), or the coupon order never reached it (DNS failure, connection refused or unreachable, connect timeout), the TPV answers HTTP 503 `{"code": "coupon_offline", "error": "Sin conexión con PrestaShop: no se puede aplicar el cupón. Puedes cobrar sin él."}` and books nothing. The payment screen shows that message and **Cobrar sin cupón**: it removes the coupon and charges the same cart through the normal local-first sale (card at once; cash goes back to the keypad when the amount handed no longer covers the full total). A coupon order that may have reached PrestaShop (timeout after connecting, 5xx, incomplete answer) is still `coupon_reconcile_required` and locks the screen, because the order could exist.
- A coupon checkout carries the payment attempt's `request_id` like any other sale: a repeated request returns the stored sale (`replayed: true`) without asking PrestaShop again.
- A customer created at the till that is not in PrestaShop yet cannot get a coupon (422): charge without it or wait until it is uploaded.

## Voucher worth more than the sale (TPV Connect 0.7.1)

«Y si se le genera otro vale igual que el anterior con el importe que falta, y se le deja usar solo 1 vez?»

- When an amount voucher with partial use is worth more than the sale, PrestaShop's `validateOrder` keeps the rest as a new cart rule: code `<code>-2` (then `-3`…, PrestaShop's own rule), the remaining amount, the same expiry, quantity 1 and quantity per user 1. Percentage vouchers, single-use (no partial use) vouchers and fully spent vouchers leave nothing.
- TPV Connect 0.7.1 finds exactly that rule (its code, created for this order after the original, for the order's customer, with the remaining amount), sets `id_customer = 0` so **anyone** can use it at the till or on the web, keeps the original's `date_to`, and answers `"leftover_voucher": {"id", "code", "amount", "expires_at"}` (`null` when there is no rest). A replayed request returns the same voucher and changes nothing. The e-mail PrestaShop sends the order's customer with the new code is unchanged.
- The TPV stores it as an issued coupon (`issued_coupon`: PrestaShop cart rule id, code, type `amount`, value, expiry, quantity 1, quantity per user 1, till and cashier) in the same transaction as the sale, and in the sale's `payment_breakdown.leftover_voucher` (`issued_coupon_id`, code, amount, expiry). The checkout response carries `leftover_voucher` (`id` is the local issued coupon id, `null` when the local copy failed), also on a replayed `request_id`.
- The ticket (ESC/POS and PDF, also reprints) prints «Saldo pendiente del vale: ABCD1234-2 — 14,00 €» (ESC/POS writes «-»: the printer code page has no long dash). Tickets without a rest are byte-for-byte unchanged.
- The payment done screen shows the same line with **Imprimir vale**, **PDF**, **Email** (when the till has a mail account) and **WhatsApp**, the coupon slip of «Crear cupón» (`/api/coupons/{id}/print|pdf|email|whatsapp`). The screen waits for the cashier (**Terminar** / **Nueva venta**) instead of closing on its own.
- With a module older than 0.7.1 PrestaShop still creates the rest, bound to the order's customer (anonymous sales: the technical customer), and the TPV shows nothing.
- No TPV provisioning step and no module upgrade script: nothing must exist before use; deploying the module files is enough (PrestaShop records 0.7.1).

## «Crear cupón» amount coupons are balance vouchers

An amount (€) coupon created with **Crear cupón** is always single use (quantity 1, quantity per user 1) with partial use, so its unspent value is never lost: the modal hides «Usos totales» and «Usos por cliente» for **Importe €** and says «Vale de un solo uso. Si la compra es menor, el saldo que sobra queda en un vale nuevo.», and `CouponCreationService::createIssued()` overrides any other quantity sent to 1/1 with `partial_use` true. Percentage coupons keep the uses chosen and no partial use. Refund vouchers already were single use with partial use.

## Accounting and limits

Order metadata records gross, native coupon reduction, payable, and cash/card tender separately. Bearer code and quote are not stored in local metadata or orphan logs. Drawer movement is only net collected cash, never the coupon's face value or change.

TPV Connect serializes module coupon redemption and native PrestaShop revalidates the rule at creation. This does not prove cross-channel atomicity with unrelated web checkout; native PrestaShop remains the authority for consumption.
