# Delta for Checkout Integration

## MODIFIED Requirements

### R1: Checkout Service (CH1)

The system MUST provide `CheckoutService::createOrder()` that validates the cart, requires an active cash session, delegates to `PsOrderClient`, persists `order_meta` with real `terminal_id` and `session_id`, records only drawer cash impact in `movement`, decrements `catalog_stock`, and dispatches a fire-and-forget print message via Symfony Messenger. Validation MUST reject empty carts and products with `catalog_stock = 0`. Printing failure MUST NOT block the checkout response.

(Previously: CheckoutService created order, persisted order_meta, decremented stock but did not dispatch any print message.)

#### Scenario: Successful checkout

- GIVEN non-empty cart, sufficient stock, and an active cash session
- WHEN CheckoutService processes checkout
- THEN it MUST call PsOrderClient, persist order_meta with real cash context, decrement stock, dispatch print message, and return order details

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

## ADDED Requirements

### R6: Print Status Tracking

`order_meta` MUST track printing status via `printed` (TINYINT, default 0) and `printed_at` (DATETIME, nullable). The async print handler MUST set `printed=1` and `printed_at` on success; on failure, `printed` MUST remain 0.

| Scenario | Given | When | Then |
|---|---|---|---|
| Print success | ESC/POS ticket sent successfully | Handler completes | order_meta.printed=1, printed_at=now() |
| Print failure | Printer unreachable or timeout | Handler catches exception | order_meta.printed=0, audit_log entry created |