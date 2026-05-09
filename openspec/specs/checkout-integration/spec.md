# Checkout Integration Specification

## Purpose

End-to-end checkout: validate cart, create PrestaShop order via HMAC-signed API, persist order_meta, decrement catalog_stock, handle partial failures via audit log.

## Requirements

### R1: Checkout Service (CH1)

The system MUST provide `CheckoutService::createOrder()` that validates the cart, requires an active cash session, delegates to `PsOrderClient`, persists `order_meta` with real `terminal_id` and `session_id`, records only drawer cash impact in `movement`, decrements `catalog_stock`, and dispatches a fire-and-forget print message via Symfony Messenger. Validation MUST reject empty carts and products with `catalog_stock = 0`. Printing failure MUST NOT block the checkout response.

#### Scenario: Successful checkout

- GIVEN non-empty cart, sufficient stock, and an active cash session
- WHEN CheckoutService processes checkout
- THEN it MUST call PsOrderClient, persist order_meta with real cash context, decrement stock, and return order details

#### Scenario: Empty cart rejected

- GIVEN a cart with zero line items
- WHEN CheckoutService processes checkout
- THEN it MUST return HTTP 422 with validation error, no PS call made

#### Scenario: Out-of-stock line rejected

- GIVEN a cart containing a product with catalog_stock ≤ 0
- WHEN CheckoutService processes checkout
- THEN it MUST reject the item and return HTTP 422 with stock error details

#### Scenario: Missing active cash session

- GIVEN no active cash session exists for the authenticated context
- WHEN CheckoutService processes checkout
- THEN it MUST reject the request before the PS call and MUST NOT persist placeholder ids

#### Scenario: Print dispatch failure does not block checkout

- GIVEN order created successfully but Messenger dispatch fails
- WHEN CheckoutService continues
- THEN the checkout response MUST still return HTTP 200 with order data; print failure logged to audit_log

### R2: PS Order Client (CH2)

The system MUST provide `PsOrderClient` that sends HMAC-signed POST requests to `PS_BASE_URL/module/tpvconnect/orders`. Signature MUST be `hash_hmac('sha256', rawBody, SSO_SHARED_SECRET)` in `Authorization: HMAC-SHA256 {signature}` header. Client MUST sign the exact `json_encode` output — no reformatting or key reordering.

#### Scenario: Successful PS order

- GIVEN valid payload and SSO_SHARED_SECRET
- WHEN PsOrderClient sends the signed request
- THEN it MUST return `{order_id, reference}` on HTTP 201

#### Scenario: PS unreachable

- GIVEN PS base URL is unreachable or response exceeds 10s
- WHEN PsOrderClient attempts the request
- THEN it MUST throw a connection error and set `retry: true` on the response

#### Scenario: PS rejects payload

- GIVEN PS module returns 4xx or 5xx
- WHEN PsOrderClient receives the error
- THEN it MUST propagate the error code and message upstream

### R3: Stock Update (CH4)

After successful PS order creation, the system MUST decrement `catalog_stock.quantity` for each product by the sold quantity. Decrement MUST use an atomic UPDATE query.

#### Scenario: Stock decremented per line

- GIVEN order with product A qty=3 and product B qty=1
- WHEN order is confirmed
- THEN product A stock MUST decrement by 3, product B by 1

#### Scenario: Stock decrement failure

- GIVEN PS order succeeded but catalog_stock UPDATE fails
- WHEN CheckoutService continues
- THEN audit_log entry MUST be created with action `stock_sync_error`, order_id, and affected products

### R4: Error Handling — Saga Pattern (CH5)

If PS order succeeds but `order_meta` INSERT fails, the system MUST log to `audit_log` with action `checkout_orphan` (including ps_order_id and payment payload) and STILL return HTTP 200 to the frontend. Pre-PS errors MUST return error responses with `retry: true` (5xx/network) or `retry: false` (4xx/validation).

#### Scenario: order_meta failure after PS success

- GIVEN PS returned order_id=42 successfully
- WHEN order_meta INSERT fails
- THEN audit_log MUST record `checkout_orphan` with ps_order_id and payment data; frontend MUST receive HTTP 200

#### Scenario: PS call failure returns retryable error

- GIVEN PS is unreachable or returns 5xx
- WHEN CheckoutService processes checkout
- THEN response MUST be HTTP 502 with `{error, retry: true}`; cart MUST remain intact

#### Scenario: Validation failure returns non-retryable error

- GIVEN cart fails validation (empty, zero-stock items)
- WHEN CheckoutService processes checkout
- THEN response MUST be HTTP 422 with `{error, retry: false}`; cart MUST remain intact

### R5: API Endpoint

`POST /api/checkout` MUST accept: `payment_method` (required), `cash_amount`, `card_amount`, `total`, `cart_lines[]` (product_id, name, reference, price, qty, discount), `transaction_id` (optional). Controller MUST delegate to `CheckoutService` and MUST require an active cash session. Response 200: `{ps_order_id, reference, total, change, payment_method}`.

#### Scenario: Successful checkout request

- GIVEN authenticated session, active cash session, and valid cart_lines
- WHEN POST /api/checkout with payment_method=cash, cash_amount=50, total=42.50
- THEN response 200 with ps_order_id, reference, total=42.50, change=7.50

#### Scenario: Unauthenticated request

- GIVEN no active session
- WHEN POST /api/checkout
- THEN response MUST be HTTP 401

#### Scenario: No active cash session

- GIVEN an authenticated user without an active cash session
- WHEN `POST /api/checkout` is called
- THEN the response rejects the request and no order is created

### R6: Print Status Tracking (CH6)

`order_meta` MUST track printing status via `printed` (TINYINT, default 0) and `printed_at` (DATETIME, nullable). The async print handler MUST set `printed=1` and `printed_at` on success; on failure, `printed` MUST remain 0.

| Scenario | Given | When | Then |
|---|---|---|---|
| Print success | ESC/POS ticket sent successfully | Handler completes | order_meta.printed=1, printed_at=now() |
| Print failure | Printer unreachable or timeout | Handler catches exception | order_meta.printed=0, audit_log entry created |
