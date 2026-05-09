# Delta for tpvconnect-module

## ADDED Requirements

### Requirement: Refund Endpoint (M7)

Front controller `TpvConnectRefundsModuleFrontController` at `POST /module/tpvconnect/refunds` MUST accept HMAC-authenticated requests, create a PrestaShop `OrderSlip` via `OrderSlip::create()`, change order state to "Devolución TPV" (`TPV_OS_REFUND`), and return JSON `{order_slip_id, order_state_id, restored_stock: bool}`.

Request MUST include: `ps_order_id` (int), `refund_type` ("full"|"partial"), `lines` array (each with `id_order_detail` and `quantity`; required for partial, all order lines for full), `refund_shipping` (bool, default false), `reason` (string, optional).

Auth MUST use `Authorization: HMAC-SHA256 {signature}` header over request body, validated against `TPVCONNECT_SSO_SECRET`, identical to the orders endpoint pattern (R5).

The endpoint MUST use PrestaShop core `OrderSlip` class — direct SQL insertion into `ps_order_slip` is prohibited (NFR-6). Stock restoration via `StockAvailable::updateQuantity()` is handled by `OrderSlip::create()` internally and MUST NOT be duplicated.

Responses: HTTP 201 on success, 401 on bad auth, 422 on invalid data (non-existent order, zero lines, already refunded), 405 on non-POST.

#### Scenario: Successful full refund slip

- GIVEN valid HMAC-signed POST with `{ps_order_id: 42, refund_type: "full"}`
- WHEN endpoint processes the request
- THEN `OrderSlip::create()` called for all order lines, order state set to `TPV_OS_REFUND`, response HTTP 201 with order_slip_id and `restored_stock: true`

#### Scenario: Successful partial refund slip

- GIVEN valid HMAC-signed POST with `{ps_order_id: 42, refund_type: "partial", lines: [{id_order_detail: 10, quantity: 1}]}`
- WHEN endpoint processes the request
- THEN `OrderSlip::create()` called for specified lines only, response HTTP 201

#### Scenario: Invalid HMAC signature

- GIVEN POST with incorrect or missing `Authorization` header
- WHEN endpoint receives it
- THEN response MUST be HTTP 401 with `{error: "Unauthorized"}`

#### Scenario: Non-existent order

- GIVEN valid HMAC-signed POST with `ps_order_id` that does not exist in PrestaShop
- WHEN endpoint processes the request
- THEN response MUST be HTTP 422 with error details indicating order not found

#### Scenario: Already refunded order

- GIVEN order #42 already has an `OrderSlip` for all its lines
- WHEN a new full refund request arrives
- THEN response MUST be HTTP 422 with `{error: "Order already fully refunded"}`

#### Scenario: Non-POST method

- GIVEN a GET request to `/module/tpvconnect/refunds`
- WHEN endpoint receives it
- THEN response MUST be HTTP 405 with `{error: "Method not allowed"}`