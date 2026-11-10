# TPV native coupon checkout

A TPV coupon is a native PrestaShop CartRule discount, not stored value or a tender balance. The POS asks TPV Connect for a signed five-minute preview, displays its authoritative gross, discount, and payable amount, then sends the same bound quote for native order creation.

## Enablement

Coupon checkout needs TPV Connect `0.2.0` or later (`coupon_preview`); the bundled module is compatible, so the gate is **on by default** and needs no manual step:

- `config/services.yaml` defaults `TPV_COUPON_CHECKOUT_ENABLED` to `1`, and `env.example` documents `1`.
- The web installer writes `TPV_COUPON_CHECKOUT_ENABLED=1`, and pairing (installer or Ajustes) writes it too, since pairing only succeeds with a module `>= PairingClient::MIN_MODULE_VERSION`.
- Installs made while the installer wrote `0` are switched on once by the provisioning step `coupon-checkout` (docs/provisioning.md). An administrator who sets it back to `0` afterwards keeps it off.

Set `TPV_COUPON_CHECKOUT_ENABLED=0` only against an old module: an old `orders` endpoint may ignore `operation: coupon_preview` and create an order. Gate-off returns `coupon_unavailable` and does not contact PrestaShop.

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

## Till orders do not send PrestaShop's e-mails (module 0.8.1)

`PaymentModule::validateOrder` sends PrestaShop's own e-mails while it creates the order (`order_conf`, the `voucher` mail of a partial-use rest). On a hosting that disables `proc_open`, the sendmail transport dies with `Call to undefined function proc_open()` half way through the order: no order state, the coupon not consumed, its rest left bound to the till's customer, and the till gets an error page instead of the order. Since tpvconnect 0.8.1 the module's `actionEmailSendBefore` hook answers false while a till order is created (`TpvOrderMail::withoutShopMail()` around `validateOrder`), so `Mail::send()` skips the message and reports it as sent; the till hands the ticket and the voucher over itself. Web orders still send their e-mails, so a shop on such a hosting needs SMTP (Advanced Parameters → E-mail) for them. `upgrade-0.8.1.php` registers the hook.

When the outcome of a coupon order is still uncertain (409 `coupon_reconcile_required`), the till says «No se ha podido confirmar el cobro con cupón en la tienda: el pedido puede haberse creado. No vuelvas a cobrar esta venta; revisa el pedido en PrestaShop.» with the server's short detail; an error page from the shop is named («página de error de la tienda (N bytes)»), never pasted.

## Why a coupon is rejected

The module answers a rejected preview with 422 and no reason the TPV can show. `CheckoutService::previewCoupon()` then asks PrestaShop for the coupon by its code (`CouponRejectionReason`, `CouponStatusLookup::findByCode()`: `GET /api/cart_rules?filter[code]=[…]`) and the cashier reads, under the code:

| PrestaShop | Message |
|---|---|
| no cart rule with that code | «Este código no existe en la tienda. Revisa que esté bien escrito.» |
| inactive | «Este cupón está desactivado en la tienda.» |
| no uses left | «Este cupón ya se ha usado.» |
| past its end date | «Este cupón caducó el DD/MM/AAAA.» |
| before its start date | «Este cupón todavía no es válido: empieza el DD/MM/AAAA.» |
| valid in itself, or the shop did not answer | «La tienda no acepta este cupón para esta venta: puede estar reservado a un cliente o necesitar un importe mínimo o unos productos concretos.» |

Only codes of letters, digits, `-` and `_` are looked up. The payment modal shows the server's sentence, never the raw `HTTP 422: {…}`.

## «Cupones»: the till's coupons and their state

The sale screen header has **Cupones** next to **Crear cupón** (administrators and cashiers). It lists the coupons created from the till (`issued_coupon`: «Crear cupón» and the leftover vouchers), newest first, 25 per page with **Ver más**, and searches by part of the code. Each coupon shows its code (**Copiar**), value (`20,00 €` / `10 %`), expiry date, when, by whom (the employee's full name, else the user name) and on which till it was created, and its state with **Imprimir** and **PDF** for its slip.

`GET /api/coupons?q=&page=` (`CouponController::list`, `CouponListService`) answers `{data: [{id, ps_cart_rule_id, code, type, value, expires_at, created_at, cashier, terminal, status, uses_left}], has_more, status_checked}`. The state comes from PrestaShop in one webservice request per page (`CouponStatusLookup`: `GET /api/cart_rules?display=[id,quantity,active,date_to]&filter[id]=[…]`):

| `status` | Shown as | When |
|---|---|---|
| `available` | Disponible (with «N usos» when more than one is left) | active, uses left, not past its end date |
| `used` | Usado | no uses left |
| `expired` | Caducado | past its end date (shop timezone, `PS_SHOP_TIMEZONE`) |
| `disabled` | Desactivado | deactivated in PrestaShop |
| `deleted` | Borrado en la tienda | PrestaShop no longer has the cart rule |
| `unknown` | Sin comprobar | the shop did not answer (`status_checked` false: the list warns that the state may be out of date; a past local expiry still shows Caducado) |

The list never fails because of the shop: without it the coupons still show from the till's copy.
