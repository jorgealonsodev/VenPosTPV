# Sales Screen Specification

## Purpose

Primary cashier interface: product search, ticket management, payment, session control. Vue 3 SPA on Twig shell at `/app/pos`. Dark theme (oklch), IBM Plex Sans+Mono, 56px+ touch targets.

## Requirements

### R1: Login & Cash Open

Login: user selector + PIN numpad (4–6 digits). Auto-login on 4th digit; shake on <4 digits. Cash Open: balance input with presets (0,50,100,150,200,300 €).

| Scenario | Given | When | Then |
|---|---|---|---|
| Auto-login | User selected, 3 digits | 4th digit pressed | Auth → Cash Open |
| PIN shake | <4 digits | Confirm pressed | Shake, no submit |
| Preset | Cash Open shown | Tap "€100" | Amount=100, confirm enabled |
| Zero-fund | Cash Open shown | Tap "Sin fondos" | Opens balance=0 → Sales Screen |

### R2: Layout

3-zone CSS Grid: catalog (60%), ticket (40%), action bar (full). Header: cashier, terminal, clock, offline icon.

| Scenario | Given | When | Then |
|---|---|---|---|
| Grid render | ≥1024px viewport | Screen loads | 60/40 split + bottom bar |
| Offline | Server unreachable | Header renders | Offline icon, APIs queued |

### R3: Product Catalog

Category tabs, search bar, 5-col grid. Cards: name, price, stock. Search: name/SKU/EAN13.

| Scenario | Given | When | Then |
|---|---|---|---|
| Category filter | 9 categories synced | Tap tab | Filtered products shown |
| SKU search | Search focused | Type "CAM-001" | Matching products appear |
| No-stock | stock=0 | Card renders | Red badge, tap blocked |
| Low-stock | stock 1–5 | Card renders | Amber indicator |

### R4: Active Ticket

Line items: name, price, qty ±, remove. Subtotal, discount, IVA, total. Duplicate → increment.

| Scenario | Given | When | Then |
|---|---|---|---|
| Add | Empty ticket | Tap product | Line qty=1 |
| Increment | Product in ticket | Tap same | Qty+1 |
| Remove | Items present | Tap trash | Line removed |
| Qty floor | qty=1 | Tap minus | Line removed |

### R5: Payment Flow

Modal: cash (numpad, change, presets, "Exacto"), card (placeholder), mixed (split), voucher (code). On confirmation ("Confirmar cobro"), PaymentModal MUST call `POST /api/checkout` with `{payment_method, cash_amount, card_amount, total, cart_lines, transaction_id?}` and await the response. On HTTP 200, MUST show real print status ("Imprimiendo ticket..." with spinner), then emit `complete` event with response data and clear cart. On print error received via status polling, MUST show warning banner but MUST NOT block checkout completion. On error (4xx/5xx/network), MUST NOT clear cart, MUST display error message with retry option.

| Scenario | Given | When | Then |
|---|---|---|---|
| Cash change | Total €19.95 | Enter €50 | "Cambio: €30.05" |
| Exact | Total €19.95 | Tap "Exacto" | Fills 19.95, change €0 |
| Mixed | Total covered | Confirm | Processes payment |
| Successful checkout | Any payment method confirmed | POST /api/checkout returns 200 | Print status shown ("Imprimiendo ticket..."), complete event emitted, cart cleared |
| Print success notification | Print dispatch succeeds | Status poll returns printed=1 | Status updates to "Ticket impreso ✓" |
| Print failure notification | Printer unreachable or timeout | Status poll returns printed=0 or error | Warning banner "Impresora no disponible", checkout still completes |
| PS server error | Any payment method confirmed | POST /api/checkout returns 502 or network error | Error message displayed, cart remains intact, retry button available |
| Validation error | Any payment method confirmed | POST /api/checkout returns 422 | Error message displayed, cart remains intact |
| Preset amounts | Cash Open shown | Tap "€100" | Amount=100, confirm enabled |

### R6: Barcode Input

USB HID (≤50ms debounce + Enter) + camera (ZXing-js, F6). No match → notification.

| Scenario | Given | When | Then |
|---|---|---|---|
| USB scan | Scan on | Rapid chars + Enter | Product found, added |
| Camera | F6 pressed | Barcode detected | Product added, camera closes |

### R7: API Endpoints

Session-protected. No session → 401. Endpoints: `GET /api/catalog/search` (q+category), `GET /api/catalog/categories`, `GET /api/catalog/product/{id}`, `GET /api/cart`, `POST /api/cart/items`, `DELETE /api/cart/items/{lineId}`, `POST /api/cart/clear`, `POST /api/cart/apply-discount`, `POST /api/cart/checkout`.

| Scenario | Given | When | Then |
|---|---|---|---|
| Search | Auth session | GET search?q=cam&category=3 | Matching products returned |
| Unauthorized | No session | GET any /api/ endpoint | HTTP 401 |

### R8: Quick Actions

Park (F4), void (F10), discount (F2), customer (F3), refunds (F7).

| Scenario | Given | When | Then |
|---|---|---|---|
| Park | Items in ticket | F4 | Save localStorage, clear |
| Void | Items in ticket | F10 | Confirm → clear, red notification |
| Discount | Items present | F2 | Modal (% or €) |
| Customer | Any | F3 | Search name/email/phone, assign |
| Refunds | Active cash session | F7 | RefundScreen opens |

### R9: Cash Session State Wiring

The sales shell MUST use backend cash APIs as the source of truth: `GET /api/cash/current` for hydration, `POST /api/cash/open` for opening, `POST /api/cash/close` for closing, and `GET /api/cash/report-x` for close totals.

#### Scenario: Existing session hydrates the shell

- GIVEN the cashier enters the POS shell with an already open cash session
- WHEN the frontend loads
- THEN it calls `GET /api/cash/current` and renders the real session ids and totals instead of local placeholders

#### Scenario: Cash open uses backend API

- GIVEN no active cash session exists
- WHEN the cashier confirms opening cash
- THEN the frontend calls `POST /api/cash/open` and only enters the sales screen after the backend returns the real session data

#### Scenario: Cash close uses backend totals

- GIVEN an active cash session exists
- WHEN the cashier closes cash
- THEN the frontend loads current/report totals from backend responses and submits `counted_cash` through `POST /api/cash/close`

### R10: Reprint Action

SaleScreen MUST provide a "Reimprimir" button in the post-checkout confirmation view. Tapping it MUST call `POST /api/ticket/reprint` with `{ps_order_id}`. On success, show brief confirmation. On failure, show error with retry.

| Scenario | Given | When | Then |
|---|---|---|---|
| Reprint success | Checkout completed, order #42 | Tap "Reimprimir" | POST /api/ticket/reprint called, confirmation toast shown |
| Reprint failure | Printer unreachable | Tap "Reimprimir" | Error banner "No se pudo reimprimir", retry button available |
| Reprint no printer | No printer configured | Tap "Reimprimir" | PDF downloaded to browser |

### R11: Refund Screen Entry

SaleScreen MUST display a "Devoluciones" button in the header area, visible only when a cash session is active. Tapping it MUST open `RefundScreen` as a modal overlay. The button MUST be disabled when no cash session is active.

#### Scenario: Refund button with active session

- GIVEN an active cash session
- WHEN the sales screen header renders
- THEN "Devoluciones" button is visible and enabled

#### Scenario: Refund button without session

- GIVEN no active cash session
- WHEN the sales screen header renders
- THEN "Devoluciones" button is visible but disabled

### R12: Refund Screen

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
- THEN total refund amount recalculates, request sends `{refund_type: "partial", lines: [{id_product: ..., quantity: 1}]}`

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

### R13: Refund Without Ticket Flow

`RefundScreen` MUST offer a "Sin ticket" option triggering supervisor PIN input. On valid PIN, a manual entry form appears (barcode scan or product search) showing name, price, quantity. Confirmation uses the standard refund pipeline with `without_ticket: true` and `supervisor_id`.

#### Scenario: Without-ticket PIN gate

- GIVEN user taps "Sin ticket" in RefundScreen
- WHEN PIN modal appears
- THEN user must enter valid supervisor PIN before manual entry form is shown

#### Scenario: Without-ticket manual entry

- GIVEN valid supervisor PIN entered
- WHEN user scans barcode "CAM-001" and confirms 2 units
- THEN refund request sent with manually entered products, `without_ticket: true`, `supervisor_id` in payload
