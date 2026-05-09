# Delta for sales-screen

## ADDED Requirements

### Requirement: Refund Screen Entry

SaleScreen MUST display a "Devoluciones" button in the header area, visible only when a cash session is active. Tapping it MUST open `RefundScreen` as a modal overlay. The button MUST be disabled when no cash session is active.

#### Scenario: Refund button with active session

- GIVEN an active cash session
- WHEN the sales screen header renders
- THEN "Devoluciones" button is visible and enabled

#### Scenario: Refund button without session

- GIVEN no active cash session
- WHEN the sales screen header renders
- THEN "Devoluciones" button is visible but disabled

### Requirement: Refund Screen

`RefundScreen` MUST provide order search, order detail, refund confirmation, and cash impact warning.

- **Search**: Search bar accepts order ID or reference; date-range optional. Results table: reference, date, total, payment method, refund status.
- **Order Detail**: Line items with name, quantity, unit price, subtotal. Partial refunds: quantity selector (0–original qty). Total recalculates on change.
- **Cash Impact**: Warning banner before confirmation showing drawer impact ("Caja: -€XX"). Card-only refunds: "Sin impacto en caja (pago con tarjeta)".
- **Confirmation**: "Confirmar devolución" calls `POST /api/refund`. Success: confirmation with OrderSlip ID. Error: message with retry.

#### Scenario: Refund with ticket — search and confirm

- GIVEN active cash session and the user taps "Devoluciones"
- WHEN user searches by order ID "42" and confirms full refund
- THEN `POST /api/refund` called with `{ps_order_id: 42, refund_type: "full"}`, order removed from results, confirmation shown with slip ID

#### Scenario: Partial refund — line selection

- GIVEN order #42 displayed with 3 lines
- WHEN user adjusts quantity on line 1 from 2 to 1
- THEN total refund amount recalculates, request sends `{refund_type: "partial", lines: [{id_order_detail: 10, quantity: 1}]}`

#### Scenario: Cash impact warning for mixed payment

- GIVEN order #50 was paid €30 cash + €20 card
- WHEN user selects full refund
- THEN warning displays "Caja: -€30" indicating cash drawer reduction

#### Scenario: Cash impact warning for card payment

- GIVEN order #51 was paid entirely by card
- WHEN user selects full refund
- THEN warning displays "Sin impacto en caja (pago con tarjeta)"

#### Scenario: Already refunded order shown

- GIVEN order #42 was fully refunded (`refunded_amount = total`)
- WHEN order #42 appears in search results
- THEN it shows status "Devuelto" with disabled selection

#### Scenario: Refund error with retry

- GIVEN `POST /api/refund` returns HTTP 500
- WHEN error response arrives
- THEN error message displayed with retry button, order remains selected

### Requirement: Refund Without Ticket Flow

`RefundScreen` MUST offer a "Sin ticket" option triggering supervisor PIN input. On valid PIN, a manual entry form appears (barcode scan or product search) showing name, price, quantity. Confirmation uses the standard refund pipeline with `without_ticket: true` and `supervisor_id`.

#### Scenario: Without-ticket PIN gate

- GIVEN user taps "Sin ticket" in RefundScreen
- WHEN PIN modal appears
- THEN user must enter valid supervisor PIN before manual entry form is shown

#### Scenario: Without-ticket manual entry

- GIVEN valid supervisor PIN entered
- WHEN user scans barcode "CAM-001" and confirms 2 units
- THEN refund request sent with manually entered products, `without_ticket: true`, `supervisor_id` in payload

## MODIFIED Requirements

### Requirement: R8: Quick Actions

Park (F4), void (F10), discount (F2), customer (F3), refunds (F7).
(Previously: Park (F4), void (F10), discount (F2), customer (F3).)

#### Scenario: Park
- GIVEN items in ticket
- WHEN F4 pressed
- THEN save to localStorage, clear ticket

#### Scenario: Void
- GIVEN items in ticket
- WHEN F10 pressed
- THEN confirm → clear, red notification

#### Scenario: Discount
- GIVEN items present
- WHEN F2 pressed
- THEN modal (% or €)

#### Scenario: Customer
- GIVEN any state
- WHEN F3 pressed
- THEN search name/email/phone, assign

#### Scenario: Refunds shortcut

- GIVEN active cash session
- WHEN F7 pressed
- THEN RefundScreen opens