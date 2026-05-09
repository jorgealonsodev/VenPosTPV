# Delta for Sales Screen

## MODIFIED Requirements

### R5: Payment Flow

Modal: cash (numpad, change, presets, "Exacto"), card (placeholder), mixed (split), voucher (code). On confirmation ("Confirmar cobro"), PaymentModal MUST call `POST /api/checkout` with `{payment_method, cash_amount, card_amount, total, cart_lines, transaction_id?}` and await the response. On HTTP 200, MUST show real print status ("Imprimiendo ticket..." with spinner), then emit `complete` event with response data and clear cart. On print error received via status polling, MUST show warning banner but MUST NOT block checkout completion. On error (4xx/5xx/network), MUST NOT clear cart, MUST display error message with retry option.

(Previously: PaymentModal showed a static "Imprimiendo ticket..." placeholder for 1.4s then emitted complete, with no real print status.)

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

## ADDED Requirements

### R10: Reprint Action

SaleScreen MUST provide a "Reimprimir" button in the post-checkout confirmation view. Tapping it MUST call `POST /api/ticket/reprint` with `{ps_order_id}`. On success, show brief confirmation. On failure, show error with retry.

| Scenario | Given | When | Then |
|---|---|---|---|
| Reprint success | Checkout completed, order #42 | Tap "Reimprimir" | POST /api/ticket/reprint called, confirmation toast shown |
| Reprint failure | Printer unreachable | Tap "Reimprimir" | Error banner "No se pudo reimprimir", retry button available |
| Reprint no printer | No printer configured | Tap "Reimprimir" | PDF downloaded to browser |