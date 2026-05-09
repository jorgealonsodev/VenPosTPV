# Tasks: 06-checkout-integration

## Phase 1: Foundation — Exception & HTTP Client

- [x] 1.1 Create `src/Service/Checkout/Exception/CheckoutException.php` with named constructors: `psUnavailable()`, `psValidationFailed()`, `stockUpdateFailed()`, `orderMetaFailed()`
- [x] 1.2 Create `src/Service/Checkout/PsOrderClient.php` — constructor takes `shopUrl`, `sharedSecret`, `HttpClientInterface`; `createOrder()` method: `json_encode` payload → `hash_hmac('sha256', $jsonBody, $secret)` → `Authorization: HMAC-SHA256 {hex}` header → POST to `{shopUrl}/module/tpvconnect/orders`; timeout 10s connect, 30s total
- [x] 1.3 Add `PS_DEFAULT_CURRENCY=1` and `PS_DEFAULT_SHOP=1` to `.env`

## Phase 2: Core — CheckoutService

- [x] 2.1 Create `src/Service/Checkout/CheckoutService.php` — constructor takes `CartService`, `PsOrderClient`, `Connection`
- [x] 2.2 Implement `createOrder()`: validate cart data (reject empty cart, zero-stock items) → resolve anonymous customer → build PS payload → call `PsOrderClient::createOrder()`
- [x] 2.3 Add `order_meta` INSERT after PS success with `payment_breakdown` JSON containing reference, total, payment_method
- [x] 2.4 Add `catalog_stock` atomic UPDATE per cart line (decrement by `qty_sold`) — use `UPDATE ... WHERE quantity >= ?`
- [x] 2.5 Implement saga: if `order_meta` or stock UPDATE fails after PS success → INSERT `audit_log` with `action=checkout_orphan`, `ps_order_id`, payment payload; still return HTTP 200
- [x] 2.6 Add `clearCart()` call after successful order — clear session cart and return success response

## Phase 3: Wiring — Controller & Services

- [x] 3.1 Modify `src/Controller/Api/CheckoutController.php` — replace placeholder; validate auth (return 401 if no session); parse request body: `payment_method`, `amount_tendered`, `cart_data`; delegate to `CheckoutService::createOrder()`; return 200 `{ps_order_id, reference, total, change_due, payment_method}`
- [x] 3.2 Modify `config/services.yaml` — wire `CheckoutService` and `PsOrderClient` with env vars: `PS_SHOP_URL`, `SSO_SHARED_SECRET`, `PS_DEFAULT_CURRENCY`, `PS_DEFAULT_SHOP`

## Phase 4: PrestaShop Module — Anonymous Customer & Orders

- [x] 4.1 Modify `modules/tpvconnect/install/install.php` — in `TpvConnectInstaller::run()`, create Customer "Consumidor final" (firstname="Consumidor", lastname="final", email="tpv@localhost", active=1, deleted=0); store ID via `Configuration::updateValue('TPVCONNECT_ANONYMOUS_CUSTOMER_ID', $id)`; on reinstall reuse existing value if Customer still exists
- [x] 4.2 Modify `modules/tpvconnect/controllers/front/orders.php` — in `validateInput()`, if `id_customer=0` or omitted, replace with `Configuration::get('TPVCONNECT_ANONYMOUS_CUSTOMER_ID')`; return 422 if anonymous ID not configured

## Phase 5: Frontend — PaymentModal & SaleScreen

- [x] 5.1 Modify `public/assets/js/api.js` — add `ApiClient.checkout(payload)` method: POST `/api/checkout` with `{payment_method, amount_tendered, cart_data}`
- [x] 5.2 Modify `public/assets/js/components/PaymentModal.js` — update `handleConfirm()` to call `ApiClient.checkout()` async; on 200: show success animation, emit `complete` event with order data, clear cart; on 4xx/5xx/network error: show error message with retry button, do NOT clear cart
- [x] 5.3 Modify `public/assets/js/components/SaleScreen.js` — update `handlePaymentComplete()` to receive order data (`ps_order_id`, `reference`), increment ticket counter, display reference to user

## Phase 6: Testing

- [x] 6.1 Write unit tests for `PsOrderClient::createOrder()` — mock HttpClient; assert correct Authorization header format, correct JSON body sent, proper exception on 401/422/500
- [x] 6.2 Write unit tests for `CheckoutService::createOrder()` — mock `PsOrderClient`, `CartService`, `Connection`; verify: cart validation (empty cart → 422, zero-stock → 422), step execution order, saga logging on partial failure
- [x] 6.3 Write unit tests for `CheckoutException` — assert each named constructor produces correct message and previous exception chain
- [x] 6.4 Integration test: full checkout flow — POST `/api/checkout` with test cart, verify order_meta INSERT, catalog_stock UPDATE, cart cleared
