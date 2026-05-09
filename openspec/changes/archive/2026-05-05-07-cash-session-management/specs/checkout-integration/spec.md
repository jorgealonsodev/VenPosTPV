# Delta for Checkout Integration

## MODIFIED Requirements

### R1: Checkout Service (CH1)

The system MUST provide `CheckoutService::createOrder()` that validates the cart, requires an active cash session, delegates to `PsOrderClient`, persists `order_meta` with real `terminal_id` and `session_id`, records only drawer cash impact in `movement`, and decrements `catalog_stock`. Validation MUST reject empty carts and products with `catalog_stock = 0`.

(Previously: checkout persisted placeholder `terminal_id`/`session_id` and did not require active cash context or sale movements.)

#### Scenario: Successful checkout

- GIVEN non-empty cart, sufficient stock, and an active cash session
- WHEN CheckoutService processes checkout
- THEN it MUST call PsOrderClient, persist order_meta with real cash context, decrement stock, and return order details

#### Scenario: Empty cart rejected

- GIVEN a cart with zero line items
- WHEN CheckoutService processes checkout
- THEN it MUST return HTTP 422 with validation error, no PS call made

#### Scenario: Out-of-stock line rejected

- GIVEN a cart containing a product with `catalog_stock ≤ 0`
- WHEN CheckoutService processes checkout
- THEN it MUST reject the item and return HTTP 422 with stock error details

#### Scenario: Missing active cash session

- GIVEN no active cash session exists for the authenticated context
- WHEN CheckoutService processes checkout
- THEN it MUST reject the request before the PS call and MUST NOT persist placeholder ids

### R5: API Endpoint

`POST /api/checkout` MUST accept: `payment_method` (required), `cash_amount`, `card_amount`, `total`, `cart_lines[]` (product_id, name, reference, price, qty, discount), `transaction_id` (optional). Controller MUST delegate to `CheckoutService` and MUST require an active cash session. Response 200: `{ps_order_id, reference, total, change, payment_method}`.

(Previously: checkout only required authenticated session and did not explicitly require active cash context.)

#### Scenario: Successful checkout request

- GIVEN authenticated session, active cash session, and valid cart_lines
- WHEN `POST /api/checkout` is called with `payment_method=cash`, `cash_amount=50`, `total=42.50`
- THEN response 200 returns order details and checkout uses the active session context

#### Scenario: Unauthenticated request

- GIVEN no active session
- WHEN `POST /api/checkout` is called
- THEN response MUST be HTTP 401

#### Scenario: No active cash session

- GIVEN an authenticated user without an active cash session
- WHEN `POST /api/checkout` is called
- THEN the response rejects the request and no order is created
