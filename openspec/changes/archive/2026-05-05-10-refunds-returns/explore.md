# Exploration: 10-refunds-returns

## Current State

### PRD Requirements

- **RF-31**: Devoluciones totales y parciales con `ps_order_slip` automático.
- **RF-32**: Vale regalo con código único como alternativa al reembolso.
- Devoluciones y abonos are planned for **Fase 2** (meses 4–6) in the roadmap.
- Supervisor PIN required for high discounts, voids, and returns without ticket (Section 12.1).
- `ps_order_slip` is a PrestaShop core table — INSERT via core classes, not direct SQL (Section 8.2, 8.3).
- Order state "Devolución TPV" (gray) should be created in `ps_order_state` during module install (Section 8.4).

### TPV Backend (what exists today)

**Database (`tpv` schema):**
- `movement` table already has `type ENUM('sale','refund','cash_in','cash_out')` — `refund` is ready.
- `movement` already has `ps_order_slip_id INT UNSIGNED NULL` column — FK placeholder for PS order slip reference.
- `order_meta` stores `payment_breakdown` JSON with lines, totals, payment methods — all data needed to reconstruct a refund.
- `catalog_stock` has `(id_product, id_product_attribute)` composite PK with `quantity` — stock to restore on refund.

**Services:**
- `CheckoutService::createOrder()` — creates orders, decrements stock, records `sale` movements. No refund logic exists.
- `CashSessionService::addMovement()` — already supports any type including `refund`. The `aggregateTotals()` method already computes `refunds_cash` and subtracts it from expected cash: `opening_cash + cash_in - cash_out + sales - refunds`.
- `PsOrderClient` — only has `createOrder()`. No refund endpoint exists on the PS module side.

**PS Module (`tpvconnect`):**
- `orders.php` — only handles `POST` for order creation via `validateOrder()`.
- No refund/slip endpoint exists. The module would need a new controller (e.g., `refunds.php`) to create `OrderSlip` via PrestaShop core.

**Frontend:**
- `SaleScreen.js` — has `handleReprint()`, `handlePark()`, `handleVoid()`. No refund UI exists.
- `CashCloseScreen` already displays `refunds` in its session totals (line 131: `refunds: (session.cashTotals && session.cashTotals.refunds) || 0`).
- `PaymentModal.js` already has a voucher/gift card input field (line 235: "Introduce el código del vale regalo") — placeholder for RF-32.

### PrestaShop Core (what we can leverage)

- `OrderSlip` class: PrestaShop core provides `OrderSlip::create()` which creates `ps_order_slip` entries, handles stock restoration via `StockAvailable`, and creates order history entries.
- `OrderSlip` creates credit slips that can be used as store credit or trigger actual refunds depending on configuration.
- `CartRule` class: Can be used to generate gift voucher codes (RF-32) — create a cart rule with the refund amount as a voucher.
- `OrderHistory`: Need to change order state to "Devolución TPV" after creating the slip.

## Affected Areas

| File | Why Affected |
|------|-------------|
| `modules/tpvconnect/controllers/front/refunds.php` | **New** — PS module endpoint to create OrderSlip via core classes |
| `src/Service/Refund/RefundService.php` | **New** — orchestrates refund: call PS, restore stock, record movement |
| `src/Service/Checkout/PsRefundClient.php` | **New** — HTTP client for PS refund endpoint (HMAC auth like PsOrderClient) |
| `src/Controller/Api/RefundController.php` | **New** — API endpoint `POST /api/refund` |
| `public/assets/js/components/RefundScreen.js` | **New** — refund UI: search orders, select lines, confirm |
| `public/assets/js/components/SaleScreen.js` | Add "Devoluciones" button to header, open RefundScreen |
| `public/assets/js/api.js` | Add refund API methods |
| `src/Service/Cash/CashSessionService.php` | Already supports `refund` movement type — no changes needed |
| `migrations/` | May need migration for `refunded_amount` column on `order_meta` (track partial refunds) |
| `config/services.yaml` | Register new services |

## Key Design Decisions

### 1. How to Find Past Orders?

**Recommendation**: Search by order ID, reference, or date range via `order_meta` table.

- `order_meta` has `ps_order_id` (PK), `payment_breakdown` JSON with all line details, `created_at`.
- For orders created via TPV, `order_meta` exists and contains everything needed.
- For orders NOT created via TPV (e.g., online orders), we'd need to query PS via Webservice API — out of MVP scope.
- **MVP**: Search `order_meta` by `ps_order_id` or by date range + terminal. Display recent orders from current cash session.

### 2. Full vs Partial Refund?

**Recommendation**: Support both, but MVP = full refund only.

- **Full refund**: All lines, all quantities. Simple — reverse the entire order.
- **Partial refund**: Select specific lines and quantities. More complex — need to track `refunded_amount` on `order_meta` to prevent double-refunding.
- **MVP**: Full refund of an order. Partial refund in a follow-up change.

### 3. How to Create Return in PrestaShop?

**Recommendation**: New endpoint in PS module (`refunds.php`) that uses `OrderSlip::create()`.

- Create `TpvConnectRefundsModuleFrontController` at `modules/tpvconnect/controllers/front/refunds.php`.
- Accept: `{ps_order_id, lines: [{id_order_detail, quantity}], refund_type: "full"|"partial", reason}`.
- Use PrestaShop core `OrderSlip::create($order, $productList, $shipping, $amount, $reason)` to create the slip.
- This automatically handles: `ps_order_slip` INSERT, stock restoration via `StockAvailable`, order history update.
- HMAC auth (same as orders endpoint).

### 4. How to Handle Stock Restoration?

**Recommendation**: Let PrestaShop core handle it via `OrderSlip::create()`.

- `OrderSlip::create()` in PS core already calls `StockAvailable::updateQuantity()` to restore stock.
- TPV side: After PS confirms, call `incrementStock()` on `catalog_stock` (inverse of `decrementStock()` in checkout).
- This is a **saga**: PS order slip created → TPV stock incremented → TPV movement recorded. If any step fails, log to `audit_log`.

### 5. How to Handle Cash Impact?

**Recommendation**: Record `refund` movement with negative amount in `movement` table.

- `CashSessionService::addMovement($userId, 'refund', $amount, 'Refund order #X', $psOrderId)` — already works.
- The `aggregateTotals()` method already subtracts refunds from expected cash.
- For cash refunds: movement amount = original cash payment amount.
- For card refunds: movement amount = 0 (no cash drawer impact, but still record for audit).
- For mixed refunds: movement amount = cash portion only.

### 6. Refund With or Without Original Ticket?

**Recommendation**: Both, with different flows.

- **With ticket**: Search by `ps_order_id` or reference → verify order exists in `order_meta` → proceed with refund.
- **Without ticket**: Requires supervisor PIN authentication → manual product entry (scan or search) → create "blind" refund. This is higher risk — needs audit logging and potentially a different PS order state.
- **MVP**: Refund WITH original ticket only. Without-ticket refunds in a follow-up change.

### 7. Gift Voucher (RF-32) as Refund Alternative?

**Recommendation**: Use PrestaShop `CartRule` to generate a voucher.

- Instead of cash refund, create a `CartRule` with:
  - `reduction_amount` = refund total
  - `reduction_tax` = true
  - `quantity` = 1, `quantity_per_user` = 1
  - `code` = unique code (e.g., `VALE-{random}`)
  - `description` = "Vale regalo - Devolución pedido #{ps_order_id}"
- Return the code to display/print for the customer.
- This is a separate flow from cash refund — customer chooses: cash back OR gift voucher.

## Approaches

### Approach 1: PS Module Endpoint + TPV Service (Recommended)

Create a new `refunds.php` controller in the PS module that uses `OrderSlip::create()`, called from a new `RefundService` in the TPV backend.

**Flow**:
1. Frontend: `RefundScreen` searches `order_meta` → user selects order → confirms refund
2. `POST /api/refund` → `RefundController` validates auth + order exists
3. `RefundService` builds PS payload, calls `PsRefundClient` → `POST /module/tpvconnect/refunds`
4. PS module creates `OrderSlip` via core → returns `{order_slip_id, restored_stock: true}`
5. TPV: increment `catalog_stock`, record `refund` movement, update `order_meta`
6. Return success to frontend

**Pros**: Uses PS core for slip creation (stock, history, accounting all handled), clean separation, HMAC auth already established pattern.
**Cons**: Requires new PS module controller, new TPV service.
**Effort**: Medium

### Approach 2: PrestaShop Webservice API for OrderSlip

Use PS Webservice API (Basic auth) to create OrderSlip directly, bypassing the module.

**Pros**: No new PS module code needed.
**Cons**: Webservice API for OrderSlip is limited/complex, harder to handle stock restoration correctly, less control over order state changes.
**Effort**: Medium-High

### Approach 3: Direct DB Access (NOT Recommended)

Insert directly into `ps_order_slip` and update `ps_stock_available` via SQL.

**Pros**: Fastest to implement.
**Cons**: Violates PRD rule "never touch core tables with direct SQL if core class exists", bypasses order history, no accounting trail, high risk of data inconsistency.
**Effort**: Low (but high risk)

## Recommendation

**Approach 1** — PS Module Endpoint + TPV Service. This follows the established pattern (orders endpoint), uses PS core classes correctly, and maintains the HMAC auth contract.

### MVP Scope (Phase 1)

1. **PS Module**: New `refunds.php` controller — accepts HMAC-signed POST, creates `OrderSlip` via core, returns slip ID.
2. **TPV Backend**: 
   - `PsRefundClient` — HTTP client for PS refund endpoint.
   - `RefundService` — orchestrates: validate order → call PS → restore stock → record movement.
   - `RefundController` — `POST /api/refund` endpoint.
3. **Frontend**:
   - `RefundScreen.js` — search orders by ID/reference, display order details, confirm full refund.
   - Add "Devoluciones" button to `SaleScreen.js` header.
   - `api.js` — add `refund(orderId)` method.
4. **Database**: No new tables needed. `movement` already has `refund` type and `ps_order_slip_id` column.

### Out of Scope (Future Changes)

- Partial refunds (select specific lines/quantities)
- Refund without original ticket (requires supervisor PIN, manual entry)
- Gift voucher generation (RF-32, `CartRule` creation)
- Refund of online orders (not created via TPV)
- Refund reason tracking
- Refund receipt printing

## Risks

1. **PrestaShop OrderSlip API complexity**: `OrderSlip::create()` has many parameters and side effects. Need to test thoroughly with the specific PS version (8.1.7). The method signature and behavior may vary between PS versions.
2. **Stock restoration race condition**: If PS restores stock but TPV increment fails, stock will be inconsistent between `ps_stock_available` and `catalog_stock`. Periodic sync will reconcile, but there's a window.
3. **Double refund prevention**: Without tracking refunded amounts on `order_meta`, a user could refund the same order twice. MVP prevents this by checking if a `movement` with type `refund` and same `ps_order_id` already exists.
4. **Cash drawer reconciliation**: Refunds reduce expected cash. If a cashier refunds a cash order but doesn't actually give cash back, the close-of-day count will be short. Need clear UI indication of cash impact.
5. **PS module order state**: After creating OrderSlip, the order state should change to "Devolución TPV". This requires creating the state during module install and updating order history after slip creation.
6. **Tax implications**: Partial refunds need to handle tax recalculation. Full refunds are simpler — reverse the entire amount including tax.

## Ready for Proposal

**Yes** — sufficient information to create a proposal, spec, and design. Key decisions needed before implementation:

1. Confirm MVP scope: full refund only vs. include partial refunds
2. Confirm PS module install should create "Devolución TPV" order state (if not already done)
3. Decide if gift voucher (RF-32) is included in this change or deferred
4. Confirm double-refund prevention strategy (check existing movement vs. add `refunded_amount` column to `order_meta`)
