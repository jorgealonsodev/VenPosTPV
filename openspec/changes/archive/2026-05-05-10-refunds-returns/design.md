# Design: Refunds and Returns (RF-31)

## Technical Approach

Refund processing follows the established saga pattern from `CheckoutService`: validate → call PS module → update local state, with `audit_log` for partial failures. A new PS module endpoint (`refunds.php`) uses `OrderSlip::create()` via PrestaShop core — identical HMAC auth pattern to `orders.php`. The TPV `RefundService` orchestrates the full flow, and `OrderSearchService` queries `order_meta` for order lookup. Cash impact flows through the existing `CashSessionService::addMovement('refund', ...)` which already subtracts refunds from expected cash.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|---|---|---|---|
| PS refund mechanism | New `refunds.php` module endpoint using `OrderSlip::create()` | PS Webservice API; Direct SQL | Follows existing HMAC pattern in `orders.php`; PS core handles stock + history + slip; same proven architecture |
| Double-refund prevention | `refunded_amount` column on `order_meta` + check existing movement | Movement-only check; DB unique constraint | Payment breakdown has mixed amounts — need to track partial refund totals per order, not just existence |
| Refund client placement | `src/Service/Refund/PsRefundClient.php` | Add method to `PsOrderClient` | Separate namespace for separate capability; different endpoint, different error domain |
| Order search service | New `OrderSearchService` in `src/Service/Refund/` | Query in controller; query in RefundService | Separation of concerns: search is read-only, refund is write orchestration |
| Migration strategy | Doctrine migration adding `refunded_amount DECIMAL(10,2) DEFAULT 0` to `order_meta` | New table; JSON field in payment_breakdown | Column is queried in WHERE clauses for double-refund checks; indexed column is faster than JSON path |

## Data Flow

```
Frontend (RefundScreen)
    │ POST /api/refund {ps_order_id, lines[]}
    ▼
RefundController
    │ Auth + validate request
    ▼
RefundService.processRefund()
    ├─→ OrderSearchService.getOrder() ──→ order_meta (read)
    ├─→ Check refunded_amount < total (double-refund guard)
    ├─→ PsRefundClient.createRefund() ──→ PS /module/tpvconnect/refunds
    │       └─→ TpvConnectRefundsModuleFrontController
    │           └─→ OrderSlip::create() + OrderHistory change
    │               ← {order_slip_id, refunded_amount, refunded_lines}
    ├─→ CashSessionService.addMovement('refund', cashAmount, ..., psOrderId)
    ├─→ Increment catalog_stock per refunded line
    ├─→ UPDATE order_meta SET refunded_amount = refunded_amount + amount
    └─→ On partial failure: INSERT audit_log {action: 'refund_orphan'}
    ◄─ Return result to frontend

Frontend (RefundScreen)
    │ GET /api/orders/search?q=123
    ▼
OrderSearchService.search() ──→ order_meta + movement tables
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `modules/tpvconnect/controllers/front/refunds.php` | Create | PS module endpoint: HMAC auth, `OrderSlip::create()`, order state change to "Devolución TPV" |
| `src/Service/Refund/RefundService.php` | Create | Orchestrator: validate order → call PS → add movement → increment stock → update order_meta |
| `src/Service/Refund/OrderSearchService.php` | Create | Search `order_meta` by ID, reference, or date range |
| `src/Service/Refund/PsRefundClient.php` | Create | HTTP client for PS refund endpoint — mirrors `PsOrderClient` HMAC pattern |
| `src/Service/Refund/Exception/RefundException.php` | Create | Exception class — mirrors `CheckoutException` with factory methods |
| `src/Controller/Api/RefundController.php` | Create | `POST /api/refund` + `GET /api/orders/search` — thin controller, mirrors `CheckoutController` |
| `migrations/Version00000005AddRefundedAmountToOrderMeta.php` | Create | Add `refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0` to `order_meta` |
| `config/services.yaml` | Modify | Wire `RefundService`, `OrderSearchService`, `PsRefundClient` |
| `public/assets/js/components/RefundScreen.js` | Create | Vue component: order search, line selection, refund confirmation |
| `public/assets/js/components/SaleScreen.js` | Modify | Add "Devoluciones" button in header, toggle `RefundScreen` |
| `public/assets/js/api.js` | Modify | Add `refundOrder()`, `searchOrders()` methods to `ApiClient` |

## Interfaces / Contracts

### POST /api/refund
```json
// Request
{ "ps_order_id": 42, "lines": [{ "id_product": 5, "quantity": 2 }], "reason": "Defective" }
// lines=null → full refund (all original quantities)

// Response 200
{ "data": { "order_slip_id": 7, "refunded_amount": 29.90, "movement_id": 312 } }
// Response 422 — order already fully refunded
// Response 404 — order not found
```

### GET /api/orders/search?q=ABC-123
```json
{ "data": [{ "ps_order_id": 42, "reference": "ABC-123", "total": 29.90, "payment_method": "cash", "lines": [...], "created_at": "..." }] }
```

### POST /module/tpvconnect/refunds (PS module)
```json
// Request (HMAC-signed)
{ "ps_order_id": 42, "lines": [{ "id_order_detail": 55, "quantity": 2 }], "refund_type": "full", "reason": "..." }
// Response 201
{ "order_slip_id": 7, "refunded_amount": 29.90, "refunded_lines": [{ "id_order_detail": 55, "quantity": 2 }] }
```

### RefundService::processRefund
```php
public function processRefund(int $userId, int $psOrderId, ?array $lines, string $reason = ''): array
```

### PsRefundClient::createRefund
```php
public function createRefund(array $payload): array
// payload: {ps_order_id, lines, refund_type, reason}
// returns: {order_slip_id, refunded_amount, refunded_lines}
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `RefundService` orchestration logic: double-refund guard, cash amount calc, stock increment | Mock `PsRefundClient`, `CashSessionService`, DBAL `Connection` |
| Unit | `OrderSearchService` query building | Mock DBAL, verify SQL fragments for each search mode |
| Unit | `PsRefundClient` HMAC signing and response parsing | Mock `HttpClientInterface`, verify HMAC header, test 201/401/422/500 responses |
| Unit | PS `refunds.php` input validation and `OrderSlip` creation | Mock PrestaShop `Order`, `OrderSlip`, `Configuration` |
| Integration | Full refund flow: controller → service → PS endpoint | Test container with real DB, PS module fixture |
| E2E | Search order → select → confirm refund → verify stock and cash | Playwright against full stack (future) |

## Migration / Rollout

1. **Migration** `Version00000005`: `ALTER TABLE order_meta ADD refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER synced`. Also add `ps_order_slip_id` index on `movement` if not present.
2. **PS module**: Deploy `refunds.php` alongside existing `orders.php`. The "Devolución TPV" order state must exist — add to module install hook if not present.
3. **Feature activation**: No feature flag needed; refund button appears for all authenticated cashiers with open session.
4. **Rollback**: Remove `RefundScreen` component reference from `SaleScreen`, revert migration, disable PS module refund route.

## Open Questions

- [ ] Confirm PS `OrderSlip::create()` signature and behavior on PS 8.1.7 — needs manual testing
- [ ] Should `OrderSearchService` also expose order lines from PS Webservice for orders not created via TPV? (deferred to post-MVP)