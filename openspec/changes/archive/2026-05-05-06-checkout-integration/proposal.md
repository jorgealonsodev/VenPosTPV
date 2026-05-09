# Proposal: Checkout Integration

## Intent

Connect TPV "Cobrar" button to real PrestaShop order creation. `CheckoutController` returns fake IDs; `PaymentModal` never calls the API.

## Scope

### In Scope
- `CheckoutService`: validate cart → HMAC-sign → call PS module → persist `order_meta` → decrement `catalog_stock`
- `PsOrderClient`: HMAC-SHA256 over raw JSON body
- Frontend: `PaymentModal` calls `POST /api/checkout`, shows result, clears cart on success
- PS module: handle anonymous customer
- Saga pattern: `audit_log` for partial failures
- Env vars: `PS_DEFAULT_CURRENCY`, `PS_DEFAULT_SHOP`

### Out of Scope
- Card payment integration (datáfonos), receipt printing, multi-method payment recording, returns/refunds, offline queue replay

## Capabilities

### New Capabilities
- `checkout-integration`: TPV creates PS orders via HMAC API, persists metadata, updates stock, handles partial failures via audit log.

### Modified Capabilities
- `sales-screen`: R5 payment flow now calls `POST /api/checkout` before `complete`; on error, keep cart intact.
- `tpvconnect-module`: M5 order endpoint must accept anonymous customer (create on install or accept `id_customer=0`).

## Approach

Thin controller delegates to `CheckoutService::createOrder()`. Service builds PS payload from frontend cart lines, signs with `hash_hmac('sha256', $rawBody, SSO_SHARED_SECRET)`, calls PS module. PS 201: insert `order_meta`, decrement `catalog_stock`, clear cart. On `order_meta` failure: log `audit_log` `checkout_orphan` and still return success. Pre-PS errors return 4xx/5xx with `retry` flag.

## Affected Areas

| Area | Impact |
|------|--------|
| `src/Service/Checkout/CheckoutService.php` | New |
| `src/Service/Checkout/PsOrderClient.php` | New |
| `src/Controller/Api/CheckoutController.php` | Modified |
| `public/assets/js/components/PaymentModal.js` | Modified |
| `public/assets/js/components/SaleScreen.js` | Modified |
| `modules/tpvconnect/controllers/front/orders.php` | Modified |
| `modules/tpvconnect/tpvconnect.php` | Modified |
| `.env` / `.env.example` | Modified |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|-------------|
| PS rejects `id_customer=0` | High | Create anonymous customer on install |
| HMAC signature mismatch | Med | Sign exact `json_encode`; no pretty-print |
| Concurrent stock race | Low | Optimistic decrement; periodic sync reconciles |
| No rollback after `validateOrder()` | Med | Saga audit log + manual reconciliation |
| Session vs localStorage divergence | Med | Use frontend-sent `cart_lines` |

## Rollback Plan

1. Revert `CheckoutController.php` to placeholder
2. Revert frontend JS to emit `complete` without API call
3. Remove new service files
4. PS module: restore original customer validation
5. No schema migration required

## Dependencies

- `SSO_SHARED_SECRET` already configured
- `order_meta` and `audit_log` tables already exist
- PS module endpoint already implemented (needs anonymous fix only)

## Success Criteria

- [ ] Cashier taps "Cobrar" → real PS order with `TPV_OS_PAID`; response has `ps_order_id` and `reference`
- [ ] `order_meta` inserted with correct session, terminal, payment breakdown; `catalog_stock` decremented per product
- [ ] `order_meta` failure → `audit_log` `checkout_orphan`; cart cleared only after 200
- [ ] Pre-PS errors keep cart intact with retryable message
