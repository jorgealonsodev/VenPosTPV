# Design: Cash Session Management MVP

## Technical Approach

Implement a DB-backed cash lifecycle with two new services: `TerminalService` resolves or bootstraps one active terminal per `shop_id`, and `CashSessionService` owns open/current/movement/close/report-x flows over `session`, `movement`, `order_meta`, and optional `z_report`. PHP `$_SESSION` becomes a transport cache (`user_id`, `shop_id`, `terminal_id`, `cash_session_id`, `cash_opened_at`, `opening_cash`) while database rows remain the source of truth.

## Architecture Decisions

| Decision | Options | Choice | Rationale |
|---|---|---|---|
| Terminal assignment | Hardcode `1` / explicit selector / shop default | Shop default bootstrap | Matches MVP scope and removes fake ids without adding UI flow. |
| Open-session control | DB constraint / service transaction | Service transaction with row lookup + lock | Schema has no partial unique index for `closed_at IS NULL`; logic must enforce one open session per terminal. |
| Expected cash model | Gross order total / cash-impact movements | Cash-impact movements | Keeps mixed/card sales correct; only drawer-impact amounts enter `movement`. |
| Report persistence | JSON only / JSON + `z_report` | JSON API + optional `z_report` on close | Meets MVP now and leaves fiscal/PDF chain deferred. |

## Data Flow

    SSO/Pos shell → TerminalService.resolve(shop_id)
                   → CashSessionService.current/open()
                   → $_SESSION cache + session row

    CheckoutController → CashSessionService.requireOpenSession()
                       → CheckoutService.createOrder()
                       → order_meta + optional sale movement

    Cash close/report → CashSessionService.aggregate(session_id)
                      → expected_cash / difference / report-x
                      → optional z_report insert

Open: `POST /api/cash/open` validates `opening_cash >= 0`, resolves terminal, rejects if same terminal already has `closed_at IS NULL`, inserts `session`, then mirrors ids in `$_SESSION`.

Current/report-x: aggregate `movement` by type. `expected_cash = opening_cash + cash_in - cash_out + sales_cash - refunds_cash` where `sales_cash = SUM(type='sale')` and `refunds_cash = SUM(type='refund')`.

Checkout: controller rejects when `cash_session_id` is missing or stale. `CheckoutService` receives resolved session context, writes real `terminal_id`/`session_id` to `order_meta`, and inserts a `movement` row only when payment has cash drawer impact: cash sale = tendered minus change, mixed = cash portion only, card/voucher-only = no row.

Close: `POST /api/cash/close` recomputes aggregates server-side, updates `session.counted_cash`, `expected_cash`, `difference`, `closed_at`, clears cash keys from `$_SESSION`, and MAY insert one `z_report` row with JSON totals + SHA-256 placeholder signature.

## File Changes

| File | Action | Description |
|---|---|---|
| `src/Service/Cash/CashSessionService.php` | Create | Transactional cash lifecycle, aggregation, session cache helpers. |
| `src/Service/Cash/TerminalService.php` | Create | Resolve/bootstrap default terminal for current shop. |
| `src/Controller/Api/CashOpenController.php` | Create | `POST /api/cash/open`. |
| `src/Controller/Api/CashCurrentController.php` | Create | `GET /api/cash/current`. |
| `src/Controller/Api/CashMovementController.php` | Create | `POST /api/cash/movement`. |
| `src/Controller/Api/CashCloseController.php` | Create | `POST /api/cash/close`. |
| `src/Controller/Api/CashReportXController.php` | Create | `GET /api/cash/report-x`. |
| `src/Controller/App/SsoController.php` | Modify | Initialize cashier/shop context and clear stale cash keys on fresh login. |
| `src/Controller/App/PosController.php` | Modify | Expose cash session payload in `window.__SESSION__`. |
| `src/Controller/Api/CheckoutController.php` | Modify | Require active cash session before checkout. |
| `src/Service/Checkout/CheckoutService.php` | Modify | Persist real ids and cash-impact sale movement. |
| `public/assets/js/stores/useSession.js` | Modify | Hydrate from backend cash state; stop inventing `sessionId`. |
| `public/assets/js/components/CashOpenScreen.js` | Modify | Call open API. |
| `public/assets/js/components/CashCloseScreen.js` | Modify | Load aggregates, submit counted cash, request report-x. |
| `public/assets/js/components/SaleScreen.js` | Modify | Consume real session summary instead of hardcoded totals. |
| `public/assets/js/api.js` | Modify | Add cash endpoints. |

## Interfaces / Contracts

```php
final class CashSessionContext {
    public int $sessionId;
    public int $terminalId;
    public int $shopId;
    public int $userId;
}
```

`GET /api/cash/report-x` response:

```json
{
  "data": {
    "session_id": 12,
    "terminal_id": 3,
    "opened_at": "2026-05-05 09:00:00",
    "totals": {
      "opening_cash": 150.00,
      "sales_cash": 420.50,
      "refunds_cash": 20.00,
      "cash_in_total": 50.00,
      "cash_out_total": 10.00,
      "expected_cash": 590.50
    },
    "movements": {"sale": 8, "refund": 0, "cash_in": 2, "cash_out": 1}
  }
}
```

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit | Terminal bootstrap, expected cash formula, close clearing session keys | PHPUnit service tests with DBAL mocks/stubs. |
| Unit | API auth/validation/status codes | Controller tests following current session-based pattern. |
| Integration | Open → checkout → movement → close aggregate flow | DB-backed integration test against TPV schema tables. |
| E2E | Not in MVP | Deferred; no frontend E2E infra exists yet. |

## Migration / Rollout

No migration required. Existing schema is sufficient; rollout is code-only behind the new cash endpoints.

## Open Questions

- [ ] Should mixed payment payload explicitly send `cash_amount` instead of inferring it from current UI field naming?
