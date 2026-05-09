# Refund Processing Specification

## Purpose

Orchestrate order refunds: search past orders, validate eligibility, create PS OrderSlip, restore stock, record cash impact, and prevent double refunds.

## Requirements

### Requirement: Refund Order Search

The system MUST expose `GET /api/refund/search` accepting query parameters `ps_order_id`, `reference`, or `date_from`/`date_to` to search `order_meta` and return matching orders with lines, totals, and `payment_breakdown`.

#### Scenario: Search by order ID

- GIVEN `order_meta` exists with `ps_order_id = 42`
- WHEN `GET /api/refund/search?ps_order_id=42`
- THEN returns order data: lines, total, `payment_breakdown`, `refunded_amount`

#### Scenario: Search by date range

- GIVEN multiple orders exist in date range
- WHEN `GET /api/refund/search?date_from=2026-01-01&date_to=2026-01-31`
- THEN returns all matching orders within that range for the current terminal

#### Scenario: No matching orders

- GIVEN no `order_meta` matches criteria
- WHEN any search is called
- THEN returns empty array with HTTP 200

### Requirement: Full Refund

The system MUST accept `POST /api/refund` with `{ps_order_id, refund_type: "full"}`. `RefundService` MUST call PS refund endpoint to create an `OrderSlip`, increment `catalog_stock` for each line, record a `refund` movement, and set `order_meta.refunded_amount` to the order total. Refund requires an active `refund` type in `movement` and `auth` session.

#### Scenario: Successful full refund

- GIVEN order #42 exists with total €50, `refunded_amount = 0`, active cash session
- WHEN `POST /api/refund` with `{ps_order_id: 42, refund_type: "full"}`
- THEN PS OrderSlip created, stock restored for all lines, `refund` movement recorded with cash portion amount, `refunded_amount = 50`

#### Scenario: Refund of card-only order

- GIVEN order #43 paid entirely by card (cash portion = 0)
- WHEN full refund is processed
- THEN `refund` movement recorded with amount = 0 (no cash drawer impact), `refunded_amount` updated to order total

#### Scenario: Refund without active session

- GIVEN no active cash session
- WHEN `POST /api/refund` is called
- THEN returns HTTP 409: "No active cash session"

### Requirement: Partial Refund

The system MUST accept `POST /api/refund` with `{ps_order_id, refund_type: "partial", lines: [{id_order_detail, quantity}]}`. Only specified lines and quantities are refunded. `refunded_amount` is incremented by the partial total.

#### Scenario: Successful partial refund

- GIVEN order #42 has 3 lines, `refunded_amount = 0`
- WHEN `POST /api/refund` with `{ps_order_id: 42, refund_type: "partial", lines: [{id_order_detail: 10, quantity: 1}]}`
- THEN PS OrderSlip created for selected lines, stock incremented for refunded quantities only, `refunded_amount` updated by partial total

#### Scenario: Partial refund exceeds remaining

- GIVEN order #42 total is €50, `refunded_amount = €30`
- WHEN partial refund lines total €30
- THEN returns HTTP 409: "Refund amount exceeds remaining refundable amount"

### Requirement: Refund Without Ticket

Refund without original ticket MUST require supervisor PIN (`auth/verify-pin`). After PIN validation, the cashier enters products manually (scan or search). The system MUST log `audit` metadata including `supervisor_id` and `without_ticket: true`.

#### Scenario: Supervisor PIN required

- GIVEN user initiates refund without ticket
- WHEN no PIN or invalid PIN provided
- THEN returns HTTP 403: "Supervisor PIN required"

#### Scenario: Successful without-ticket refund

- GIVEN valid supervisor PIN and manually entered products
- WHEN refund confirmed
- THEN standard refund pipeline executes with audit metadata `supervisor_id` and `without_ticket` flag

### Requirement: Double-Refund Prevention

The system MUST prevent refunding more than the original order total. `order_meta.refunded_amount` MUST be checked before any refund and updated atomically. A `refund` movement with the same `ps_order_id` MUST be validated as a secondary guard.

#### Scenario: Already fully refunded

- GIVEN order #42 `refunded_amount` equals order total
- WHEN `POST /api/refund` with `{ps_order_id: 42}`
- THEN returns HTTP 409: "Order already fully refunded"

#### Scenario: Concurrent refund race

- GIVEN two simultaneous refund requests for order #42
- WHEN both attempt to create movements
- THEN only one succeeds; second receives HTTP 409