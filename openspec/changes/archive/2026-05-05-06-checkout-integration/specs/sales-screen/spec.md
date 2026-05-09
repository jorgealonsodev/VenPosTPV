# Delta for sales-screen

## MODIFIED Requirements

### R5: Payment Flow

Modal: cash (numpad, change, presets, "Exacto"), card (placeholder), mixed (split), voucher (code). On confirmation ("Confirmar cobro"), PaymentModal MUST call `POST /api/checkout` with `{payment_method, cash_amount, card_amount, total, cart_lines, transaction_id?}` and await the response. On HTTP 200, emit `complete` event with response data and clear cart. On error (4xx/5xx/network), MUST NOT clear cart, MUST display error message with retry option.

(Previously: R5 payment scenarios only covered local UI behavior — cash change calculation, exact amount, mixed payment — without any server-side order creation)

#### Scenario: Cash change

- GIVEN total €19.95
- WHEN user enters €50
- THEN "Cambio: €30.05" displayed

#### Scenario: Exact payment

- GIVEN total €19.95
- WHEN user taps "Exacto"
- THEN amount fills 19.95, change €0

#### Scenario: Mixed payment

- GIVEN total covered by cash + card
- WHEN user confirms
- THEN processes payment

#### Scenario: Successful checkout

- GIVEN any payment method confirmed
- WHEN POST /api/checkout returns 200
- THEN green check shown, `complete` event emitted with `{ps_order_id, reference, total, change, payment_method}`, cart cleared

#### Scenario: PS server error

- GIVEN any payment method confirmed
- WHEN POST /api/checkout returns 502 or network error
- THEN error message displayed, cart remains intact, retry button available

#### Scenario: Validation error

- GIVEN any payment method confirmed
- WHEN POST /api/checkout returns 422
- THEN error message displayed, cart remains intact

#### Scenario: Preset amounts

- GIVEN Cash Open shown
- WHEN user taps "€100"
- THEN amount=100, confirm enabled