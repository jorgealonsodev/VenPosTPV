# Tasks: Refunds and Returns (RF-31)

## Phase 1: Foundation / Migration

- [x] 1.1 Create `migrations/Version00000005AddRefundedAmountToOrderMeta.php` — add `refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0` column to `order_meta` table after `synced` column
- [x] 1.2 Add `ps_order_slip_id` index on `movement` table (required for refund movement lookups)

## Phase 2: Core Backend Services

- [x] 2.1 Create `src/Service/Refund/Exception/RefundException.php` — exception class with factory methods: `doubleRefundAttempt()`, `orderNotFound()`, `psUnavailable()`, `validationFailed()` — mirrors `CheckoutException` pattern
- [x] 2.2 Create `src/Service/Refund/OrderSearchService.php` — `search(query)`, `getOrder(psOrderId)`, `getOrderByReference(reference)` — queries `order_meta` table; supports ID, reference, date-range search modes
- [x] 2.3 Create `src/Service/Refund/PsRefundClient.php` — HMAC-signed HTTP client for `POST /module/tpvconnect/refunds`; mirrors `PsOrderClient` pattern; handle 201/401/422/500 responses; returns `{order_slip_id, refunded_amount, refunded_lines}`
- [x] 2.4 Create `src/Service/Refund/RefundService.php` — `processRefund(userId, psOrderId, lines, reason)` orchestrator: validate order → check double-refund guard (refunded_amount < total) → call PsRefundClient → CashSessionService.addMovement('refund') → increment catalog_stock → UPDATE order_meta.refunded_amount → on failure INSERT audit_log with `refund_orphan` action
- [x] 2.5 Create `src/Controller/Api/RefundController.php` — `POST /api/refund` (delegate to RefundService) and `GET /api/orders/search` (delegate to OrderSearchService); mirrors CheckoutController pattern

## Phase 3: PrestaShop Module Endpoint

- [x] 3.1 Create `modules/tpvconnect/controllers/front/refunds.php` — `TpvConnectRefundsModuleFrontController`; HMAC auth (same as orders.php); rate limiting; `OrderSlip::create()`; set order state to `TPV_OS_REFUND` ("Devolución TPV"); return 201 `{order_slip_id, refunded_amount, refunded_lines}`

## Phase 4: Service Wiring

- [x] 4.1 Modify `config/services.yaml` — wire `RefundService`, `OrderSearchService`, `PsRefundClient` with constructor args: shopUrl, sharedSecret, HttpClientInterface

## Phase 5: Frontend

- [x] 5.1 Modify `public/assets/js/api.js` — add `refundOrder(psOrderId, lines, reason)` and `searchOrders(query)` methods to ApiClient
- [x] 5.2 Create `public/assets/js/components/RefundScreen.js` — Vue component: order search bar, results list, order detail with line selection checkboxes, partial refund qty inputs, cash impact warning, confirm button
- [x] 5.3 Modify `public/assets/js/components/SaleScreen.js` — add "Devoluciones" button in header next to existing actions; toggle RefundScreen visibility; disable when no open cash session

## Phase 6: Testing

- [x] 6.1 Write `tests/Unit/Service/Refund/RefundExceptionTest.php` — test factory methods and exception hierarchy
- [x] 6.2 Write `tests/Unit/Service/Refund/OrderSearchServiceTest.php` — mock DBAL `Connection`; test query building for ps_order_id, reference, date-range search modes
- [x] 6.3 Write `tests/Unit/Service/Refund/PsRefundClientTest.php` — mock `HttpClientInterface`; test HMAC header generation, 201/401/422/500 response parsing
- [x] 6.4 Write `tests/Unit/Service/Refund/RefundServiceTest.php` — mock `PsRefundClient`, `CashSessionService`, DBAL `Connection`; test double-refund guard (HTTP 409 on fully refunded), cash amount calculation, stock increment call, order_meta update
- [x] 6.5 Write `tests/Unit/Service/Refund/RefundControllerTest.php` — test POST /api/refund returns 422 on double-refund, 404 on missing order, 201 on success; test GET /api/orders/search query passthrough

## Phase 7: Integration Verification

- [x] 7.1 Run migration against dev DB — verify `refunded_amount` column exists and defaults to 0
- [x] 7.2 Verify full refund flow end-to-end: search order → partial refund → check movement recorded, stock restored, refunded_amount updated
- [x] 7.3 Verify double-refund returns HTTP 409 and does not call PS module