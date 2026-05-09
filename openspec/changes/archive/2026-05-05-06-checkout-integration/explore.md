# Exploration: 06-checkout-integration

## Current State

The system has two backends that need to be connected for order creation:

### TPV Backend (Symfony 6.4)
- **Cart**: Session-based (`CartService.php`) with lines, discounts, customer, IVA calculation (21%). Cart lines have: `id`, `product_id`, `name`, `reference`, `price`, `qty`, `discount`.
- **Frontend cart**: `useCart.js` — localStorage-first reactive store with identical structure. Default customer is "Consumidor final".
- **CheckoutController**: Placeholder at `POST /api/checkout` — returns fake `order_id` with `uniqid()`. No PS integration.
- **PaymentModal.js**: Supports cash, card, mixed, voucher payment methods. On "Confirmar cobro", emits `complete` event with method name — **does NOT call any API**.
- **SaleScreen.js**: `handlePaymentComplete()` just clears cart and shows notification. No server-side order creation.
- **Database**: `order_meta` table exists with columns: `ps_order_id` (PK), `terminal_id`, `session_id`, `user_id`, `payment_breakdown` (JSON), `printed`, `synced`, `created_at`.
- **Catalog**: `catalog_stock` table synced from PS via `CatalogSyncService` (uses PS Webservice API with Basic auth).

### PS Module (tpvconnect in PrestaShop 8.1.7)
- **Order endpoint**: `POST /module/tpvconnect/orders` (`orders.php`) — fully implemented.
- **Auth**: `Authorization: HMAC-SHA256 {signature}` where `signature = hash_hmac('sha256', raw_body, secret)`. Secret is `Configuration::get('TPVCONNECT_SSO_SECRET')`.
- **Required input fields**: `id_customer` (int, validated), `id_currency` (int, validated), `id_shop` (int), `payment_method` (string), `products[]` (each with `id_product`, `id_product_attribute`, `quantity`). Optional: `transaction_id`.
- **Order creation flow**: Creates Cart → adds products → calls `validateOrder()` with `TPV_OS_PAID` state → returns `{order_id, reference}`.
- **Stock**: PS module's `validateOrder()` automatically decrements `ps_stock_available`.
- **Customer validation**: The module REQUIRES a valid `id_customer` — it creates a `Customer` object and validates with `Validate::isLoadedObject()`. **id_customer = 0 will fail.**
- **Shared secret**: `SSO_SHARED_SECRET` in `.env` matches `TPVCONNECT_SSO_SECRET` in PS config. Current value: `ab141f2e935520d0d2ccf67fdacf80153a445646fdb10a06ea4f6bb69f876c85`.

### SSO Token (SsoToken.php)
- SSO uses `base64url(json(payload))~hmac_sha256(payload)` — different from order auth which uses `hmac_sha256(raw_body)`.
- Order auth signs the **raw request body**, not a base64-encoded payload.

## Affected Areas

| File | Why Affected |
|------|-------------|
| `src/Controller/Api/CheckoutController.php` | Replace placeholder with real PS order creation + order_meta persistence |
| `src/Service/Cart/CartService.php` | May need `getCartLines()` method to extract raw lines for PS API call |
| `public/assets/js/components/PaymentModal.js` | Needs to call `POST /api/checkout` on confirm, handle async response |
| `public/assets/js/components/SaleScreen.js` | `handlePaymentComplete` needs to handle async checkout result |
| `public/assets/js/stores/useCart.js` | May need `getRawLines()` accessor for API payload |
| `config/services.yaml` | May need to register new checkout service |
| `migrations/` | May need new migration for audit/compensation tracking |
| `modules/tpvconnect/controllers/front/orders.php` | Already implemented — no changes needed for MVP |

## Key Design Decisions

### 1. HMAC Auth for TPV → PS
The signing algorithm is straightforward:
```php
$signature = hash_hmac('sha256', $rawJsonBody, $ssoSecret);
// Header: Authorization: HMAC-SHA256 {signature}
```
The TPV backend already has `SSO_SHARED_SECRET` in `.env`. No new credentials needed.

### 2. Customer ID Mapping
The PS module **requires** a valid `id_customer`. Two approaches:
- **Option A**: Create a "Consumidor final" customer in PS during module install, store its ID in `TPVCONNECT_ANONYMOUS_CUSTOMER_ID`. Use this for anonymous sales.
- **Option B**: Modify the PS module to accept `id_customer = 0` and create a guest customer on the fly.

**Recommendation: Option A** — simpler, no PS module changes, aligns with retail POS conventions.

### 3. Currency and Shop IDs
The PS module requires `id_currency` and `id_shop`. These should be:
- Configured as env vars or Symfony parameters (`PS_DEFAULT_CURRENCY`, `PS_DEFAULT_SHOP`)
- Defaults: currency = 1 (EUR), shop = 1 (default shop)

### 4. Stock Sync Strategy
- **PS side**: `validateOrder()` automatically decrements `ps_stock_available` — no action needed.
- **TPV side**: After order is confirmed, decrement `catalog_stock.quantity` for each product.
- **Sync**: Existing `CatalogSyncService.syncStock()` can run periodically (cron/messenger) to reconcile. For MVP, immediate decrement + periodic sync is sufficient.

### 5. Error Handling (Saga Pattern)
The checkout flow has two critical steps:
1. Create order in PS (irreversible — `validateOrder()` creates `ps_orders` row)
2. Save `order_meta` in TPV DB (can fail independently)

**Failure scenarios**:
- PS call fails → No order created → Return error to frontend, cart stays intact
- PS succeeds but `order_meta` insert fails → Order exists in PS but not tracked in TPV → **Write to `audit_log`** with action `checkout_orphan`, payload includes PS order_id, payment data. Manual reconciliation needed.
- Stock update fails after order_meta → Log to audit, periodic sync will fix

## Approaches

### Approach 1: Direct Symfony Controller (Recommended)
Implement checkout logic directly in `CheckoutController` with a dedicated `OrderSyncService`.

**Flow**:
1. Frontend: `PaymentModal.handleConfirm()` → `ApiClient.post('/api/checkout', payload)` → wait for response → emit complete
2. TPV Backend `CheckoutController`:
   a. Validate session auth
   b. Get cart from session
   c. Build PS payload (map cart lines to PS format, add customer/currency/shop)
   d. Sign with HMAC, POST to PS `/module/tpvconnect/orders`
   e. On success: insert `order_meta` row, decrement `catalog_stock`, clear cart
   f. On `order_meta` failure: log to `audit_log`, still return success to frontend
   g. Return `{order_id, reference, total, change}` to frontend

**Pros**: Simple, minimal new files, matches existing architecture
**Cons**: Controller gets complex, harder to test in isolation
**Effort**: Low

### Approach 2: Service Layer + Controller
Extract order creation into `OrderSyncService`, keep controller thin.

**Flow**: Same as Approach 1, but `CheckoutController` delegates to `OrderSyncService::createOrder()`.

**Pros**: Better testability, cleaner separation, reusable for offline queue replay
**Cons**: More files, slightly more complex
**Effort**: Medium

### Approach 3: Async via Messenger
Queue order creation as a Symfony Messenger message, return immediately to frontend.

**Pros**: Non-blocking, handles PS downtime gracefully
**Cons**: Frontend can't show order confirmation immediately, complex error recovery, overkill for MVP
**Effort**: High

## Recommendation

**Approach 2 (Service Layer)** — best balance of simplicity and testability. The service can be reused later for offline queue replay.

### MVP Scope
1. `OrderSyncService` with `createOrder(array $cart, string $paymentMethod, float $cashAmount, float $cardAmount)` method
2. Updated `CheckoutController` that calls the service
3. Frontend `PaymentModal` calls `POST /api/checkout` and waits for response
4. `catalog_stock` decrement on success
5. `audit_log` entry on partial failure (PS order created but TPV record failed)
6. Config: `PS_DEFAULT_CURRENCY`, `PS_DEFAULT_SHOP` in `.env`

### Out of Scope (Future)
- Customer creation in PS from TPV (use anonymous customer for MVP)
- Voucher payment validation
- Real-time stock sync webhooks
- Offline queue replay for failed orders
- Receipt printing integration

## Request/Response Format

### Frontend → TPV Backend (`POST /api/checkout`)
**Request**:
```json
{
  "payment_method": "cash|card|mixed|voucher",
  "cash_amount": 50.00,
  "card_amount": 0,
  "total": 42.50,
  "cart_lines": [...],  // optional, falls back to session cart
  "transaction_id": "EXT-123"  // optional, for card terminal reference
}
```

**Response (200)**:
```json
{
  "data": {
    "ps_order_id": 42,
    "reference": "ABCDEF123",
    "total": 42.50,
    "change": 7.50,
    "payment_method": "cash"
  }
}
```

**Response (502 - PS unreachable)**:
```json
{
  "error": "No se pudo conectar con PrestaShop",
  "retry": true
}
```

### TPV Backend → PS Module (`POST /module/tpvconnect/orders`)
**Headers**: `Authorization: HMAC-SHA256 {hash_hmac('sha256', rawBody, secret)}`

**Request**:
```json
{
  "id_customer": 1,
  "id_currency": 1,
  "id_shop": 1,
  "payment_method": "TPV Efectivo",
  "transaction_id": "TPV-20260505-001",
  "products": [
    {"id_product": 5, "id_product_attribute": 0, "quantity": 2},
    {"id_product": 12, "id_product_attribute": 3, "quantity": 1}
  ]
}
```

**Response (201)**:
```json
{
  "order_id": 42,
  "reference": "ABCDEF123"
}
```

## Risks

1. **PS module customer validation**: The current `orders.php` rejects `id_customer = 0`. Need to either create an anonymous customer in PS or modify the module. This is a **blocker** for MVP.
2. **HMAC signature mismatch**: If the TPV backend sends JSON with different formatting (whitespace, key order) than what it signs, HMAC verification will fail. Must sign the exact raw body string.
3. **Race condition on stock**: If two TPV terminals sell the same last item simultaneously, one will succeed in PS but fail locally. The periodic sync will eventually reconcile, but there's a window of inconsistency.
4. **No rollback**: Once `validateOrder()` is called in PS, the order cannot be deleted without side effects (invoices, emails, accounting). Partial failures must be handled via audit log + manual review.
5. **Session cart vs localStorage cart**: The frontend uses `useCart.js` (localStorage) while the backend uses `CartService` (PHP session). They may diverge. The checkout should use the frontend's cart data sent in the request body, not the server session.

## Ready for Proposal

**Yes** — sufficient information to create a proposal, spec, and design. Key decisions needed before implementation:
1. Confirm approach for anonymous customer (create in PS module install vs modify validation)
2. Confirm `id_currency` and `id_shop` defaults
3. Decide if `PaymentModal` should send cart lines in request body or rely on server session
