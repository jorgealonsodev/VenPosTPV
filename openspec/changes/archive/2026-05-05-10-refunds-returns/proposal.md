# Proposal: Refunds and Returns (RF-31)

## Intent
Allow cashiers to refund past TPV orders, restoring stock and adjusting cash totals automatically.

## Scope

### In Scope
- Search orders by ID, reference, or date
- Full refund (all lines) and partial refund (select lines/qty)
- PS module endpoint `POST /module/tpvconnect/refunds` via `OrderSlip::create()`
- TPV `RefundService` calling PS via HMAC
- Restore stock in PS and TPV `catalog_stock`
- Cash `refund` movement reducing expected cash
- With-ticket and without-ticket (supervisor PIN) flows
- Frontend `RefundScreen` with search and line selection
- Double-refund prevention

### Out of Scope
- Gift vouchers (RF-32)
- Email notifications
- Online (non-TPV) order refunds
- Refund receipt printing

## Capabilities

### New
- `refund-processing`: Orchestrate PS slip, stock, cash, prevention
- `refund-order-search`: Query `order_meta` by ID/reference/date

### Modified
- `sales-screen`: Add "Devoluciones" button and `RefundScreen`
- `tpvconnect-module`: New HMAC endpoint `POST /module/tpvconnect/refunds`
- `cash-session-management`: Auto-record `refund` movements
- `catalog-sync`: Increment `catalog_stock` on refund

## Approach
1. PS module: `refunds.php` receives HMAC payload, calls `OrderSlip::create()`, returns slip ID.
2. TPV: `RefundService` validates order, calls `PsRefundClient`, increments stock, records `refund` movement, updates `order_meta.refunded_amount`.
3. Frontend: `RefundScreen` lists orders, allows line/qty selection, confirms via `POST /api/refund`.
4. Without-ticket: supervisor PIN gate → manual entry → same pipeline.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `modules/tpvconnect/controllers/front/refunds.php` | New | PS refund endpoint |
| `src/Service/Refund/RefundService.php` | New | Orchestration |
| `src/Service/Checkout/PsRefundClient.php` | New | HMAC refund client |
| `src/Controller/Api/RefundController.php` | New | `POST /api/refund` |
| `public/assets/js/components/RefundScreen.js` | New | Refund UI |
| `public/assets/js/components/SaleScreen.js` | Modified | Add refund button |
| `public/assets/js/api.js` | Modified | Refund API methods |
| `migrations/` | Modified | `refunded_amount` on `order_meta` |
| `config/services.yaml` | Modified | Wire new services |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| PS `OrderSlip::create()` version differences | Med | Adapter + tests on PS 8.1.7 |
| Stock inconsistency PS vs TPV | Med | Saga + audit_log, periodic sync |
| Double refund | Low | Check `movement` + `refunded_amount` |
| Cash drawer mismatch | Med | UI warning on cash impact |

## Rollback Plan
1. Revert services config and delete new files.
2. Rollback migration removing `refunded_amount`.
3. Disable PS module refund route (order states preserved).

## Dependencies
- "Devolución TPV" order state exists (module install).
- `movement` table supports `refund` type (already present).

## Success Criteria
- [ ] Refunds create `ps_order_slip` and restore PS stock
- [ ] `catalog_stock` increments match refunded quantities
- [ ] `refund` movement recorded with correct cash portion
- [ ] `expected_cash` decreases after refund
- [ ] Same order/lines cannot be refunded twice
- [ ] Without-ticket refund requires supervisor PIN
