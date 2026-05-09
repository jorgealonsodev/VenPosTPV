# Cash Session Management Specification

## Purpose

Persist the cash drawer lifecycle so the POS uses real terminal, session, movement, and report data.

## Requirements

### Requirement: Open Session

The system MUST expose `POST /api/cash/open` for authenticated users with `opening_cash >= 0`, resolve the terminal for the current `shop_id`, and reject opening when that terminal already has an active session.

#### Scenario: Session opens successfully

- GIVEN an authenticated user, no open session for the resolved terminal, and `opening_cash = 100`
- WHEN `POST /api/cash/open` is called
- THEN the system creates one open `session` row and returns `session_id`, `terminal_id`, `opened_at`, and `opening_cash`

#### Scenario: Duplicate open blocked

- GIVEN the resolved terminal already has an open session
- WHEN `POST /api/cash/open` is called
- THEN the system rejects the request without creating a second open session

### Requirement: Current Session State

The system MUST expose `GET /api/cash/current` as the source of truth for the active session state and computed totals for the authenticated cash context. The response MUST include `refunds_cash` (sum of `refund` movement amounts) and `refund_count` (number of `refund` movements).

#### Scenario: Current state returned

- GIVEN an active cash session with completed refunds
- WHEN `GET /api/cash/current` is called
- THEN the response includes `session_id`, `terminal_id`, `opened_at`, `opening_cash`, `expected_cash`, `sales_cash`, `refunds_cash`, `refund_count`, `cash_in_total`, and `cash_out_total`

#### Scenario: No active session

- GIVEN no active cash session exists
- WHEN `GET /api/cash/current` is called
- THEN the response indicates there is no open session and does not invent placeholder identifiers

### Requirement: Manual Cash Movement

The system MUST expose `POST /api/cash/movement` for `cash_in` and `cash_out` only, and it MUST require `amount > 0`, `concept`, and an active session.

#### Scenario: Manual movement recorded

- GIVEN an active session and `{type: cash_out, amount: 20, concept: Petty cash}`
- WHEN `POST /api/cash/movement` is called
- THEN the system creates one `movement` row linked to the active `session_id`

#### Scenario: Invalid manual movement rejected

- GIVEN the request uses `type = sale` or no active session exists
- WHEN `POST /api/cash/movement` is called
- THEN the system rejects the request and records no manual movement

### Requirement: Close Session

The system MUST expose `POST /api/cash/close`, require `counted_cash`, compute `expected_cash` and `difference` from persisted data, and close the active session exactly once.

#### Scenario: Session closes successfully

- GIVEN an active session with persisted movements
- WHEN `POST /api/cash/close` is called with `counted_cash`
- THEN the response returns `session_id`, `closed_at`, `expected_cash`, `counted_cash`, and `difference`

### Requirement: X Report

The system MUST expose `GET /api/cash/report-x` as a JSON read model over the active session totals.

#### Scenario: Report X summarizes session

- GIVEN an active session with opening cash and movements
- WHEN `GET /api/cash/report-x` is called
- THEN the response includes opening cash, sales cash, refunds cash, cash in/out totals, expected cash, and movement counts

### Requirement: Checkout Cash Context

The system MUST provide real `session_id` and `terminal_id` to checkout flows, and sale movements MUST store drawer cash impact only.

#### Scenario: Mixed payment stores cash impact only

- GIVEN an active session and a mixed payment sale
- WHEN checkout is completed
- THEN `order_meta` stores the real `session_id` and `terminal_id`
- AND the recorded sale movement amount equals the cash portion only

### Requirement: Default Terminal Bootstrap

The system MUST create or reuse one default terminal per `shop_id` before opening or resolving cash context when no terminal exists yet.

#### Scenario: Default terminal bootstrapped

- GIVEN the authenticated `shop_id` has no terminal row
- WHEN cash context is resolved
- THEN the system creates one default terminal for that shop and uses its real `terminal_id`

### Requirement: Refund Movement Recording

`RefundService` MUST record a `refund` movement via `CashSessionService::addMovement()` for every completed refund. The movement MUST include: `type = 'refund'`, `amount` equal to the cash portion of the original payment (0 for card-only refunds), `concept` with format "Devolución pedido #{ps_order_id}", `ps_order_id`, and `ps_order_slip_id` linking to the created PS order slip.

The `addMovement()` method MUST accept `refund` as a valid movement type. Existing `aggregateTotals()` already computes `refunds_cash` as the sum of all `refund` movements' amounts and subtracts it from `expected_cash`: `opening_cash + cash_in - cash_out + sales - refunds`.

#### Scenario: Cash refund movement recorded

- GIVEN order #42 was paid €30 cash + €20 card, full refund processed
- WHEN `RefundService` records the movement
- THEN one `movement` row created with `type='refund'`, `amount=30` (cash portion only), `ps_order_id=42`, `ps_order_slip_id` populated, `concept="Devolución pedido #42"`

#### Scenario: Card-only refund movement recorded

- GIVEN order #43 was paid entirely by card (€50), full refund processed
- WHEN `RefundService` records the movement
- THEN one `movement` row created with `type='refund'`, `amount=0` (no cash drawer impact), `ps_order_id=43`, `ps_order_slip_id` populated, `concept="Devolución pedido #43"`

#### Scenario: Refund reduces expected cash

- GIVEN active session with opening €100, sales €50, no refunds yet → expected_cash = €150
- WHEN a cash refund of €30 is recorded
- THEN `expected_cash` becomes €120 (100 + 0 + 0 + 50 - 30)

#### Scenario: Refund movement without active session

- GIVEN no active cash session
- WHEN `RefundService` attempts to record a refund movement
- THEN movement recording MUST fail and the refund process MUST NOT proceed

### Requirement: Refund Visibility in Session Reports

`GET /api/cash/current` and `GET /api/cash/report-x` MUST include `refunds_cash` and `refund_count` in their responses. `CashCloseScreen` already displays refunds in session totals — the backend MUST provide these computed values.

#### Scenario: Current session includes refund totals

- GIVEN active session with 2 completed refunds (€30 + €20 cash portions)
- WHEN `GET /api/cash/current` is called
- THEN response includes `refunds_cash: 50` and `refund_count: 2`

#### Scenario: Report X includes refund summary

- GIVEN active session with refunds
- WHEN `GET /api/cash/report-x` is called
- THEN response includes `refunds_cash`, `refund_count`, and breakdown reflected in `expected_cash`
