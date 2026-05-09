# Exploration: 07-cash-session-management

## Current State

The backend authenticates users with PHP native sessions, but it does not persist a real cash drawer lifecycle yet.

- `SsoController.php` stores `user_id`, `ps_employee_id`, `shop_id`, and `authenticated_at` in `$_SESSION`; it does **not** store `terminal_id` or `cash_session_id`.
- `PosController.php` injects `window.__SESSION__`, but only with `user_id`, `cashier_name`, `store_name`, and `shop_id`. `cashier_name`/`store_name` are fallback values unless something else populated them.
- `useSession.js` is UI-only state. `login()`, `openCash()`, and `closeCash()` only mutate localStorage/reactive state. `session.sessionId` is currently set to `user_id`, not to a real cash session id.
- `CashOpenScreen.js` and `CashCloseScreen.js` emit local events only; no backend API exists for open/close.
- `SaleScreen.js` shows cash close using hardcoded totals (`sales: 843.20`, `refunds: 45.00`).
- `CheckoutService.php` persists `order_meta` with hardcoded `terminal_id = 1` and `session_id = 1`, and it does not insert `movement` rows.
- The database already has the needed tables: `terminal`, `session`, `movement`, `order_meta`, `z_report`.

## Affected Areas

- `src/Controller/App/SsoController.php` — enrich server session with cashier display data and terminal context source.
- `src/Controller/App/PosController.php` — expose real cash session data to the frontend shell.
- `src/Controller/Api/CheckoutController.php` — reject checkout without active cash session or resolve it explicitly.
- `src/Service/Checkout/CheckoutService.php` — replace placeholder ids and record sale movement cash impact.
- `src/Service/Cart/CartService.php` — unchanged in core behavior, but checkout still depends on its clear flow.
- `public/assets/js/stores/useSession.js` — stop treating local state as source of truth for cash session lifecycle.
- `public/assets/js/components/CashOpenScreen.js` — call backend open API.
- `public/assets/js/components/CashCloseScreen.js` — load current totals and submit counted cash.
- `public/assets/js/components/SaleScreen.js` — consume real current-session summary instead of hardcoded totals.
- `migrations/install.sql` — existing schema is sufficient for MVP; no mandatory schema change found.

## Approaches

1. **Single default terminal + PHP session cash context** — Resolve one active terminal for the current shop, persist cash session rows in DB, and mirror `{terminal_id, cash_session_id}` in `$_SESSION`.
   - Pros: Smallest MVP, fixes checkout placeholders immediately, aligns with current SSO/PHP-session architecture.
   - Cons: Terminal identity is implicit; multi-terminal assignment remains deferred.
   - Effort: Medium

2. **Multi-terminal-first selection flow** — Add explicit terminal selection/resolution before cash open and use that as the anchor for all cash operations.
   - Pros: Better long-term model, avoids later UX changes for multi-terminal stores.
   - Cons: Bigger scope now; requires new UI/admin flow before closing the current architectural gap.
   - Effort: High

## Recommendation

Use **Approach 1** for MVP.

### Recommended MVP Scope

1. **Open cash session**
   - `POST /api/cash/open` inserts one row in `session` with `terminal_id`, `user_id`, `opened_at`, `opening_cash`.
   - Persist `cash_session_id`, `terminal_id`, `cash_opened_at`, and `opening_cash` in `$_SESSION`.

2. **Read current cash session**
   - `GET /api/cash/current` returns the active open session for the authenticated user/session context, plus computed totals for UI hydration.
   - Frontend `useSession.js` should mirror backend state, not invent it locally.

3. **Close cash session**
   - `POST /api/cash/close` requires `counted_cash`.
   - Backend computes `expected_cash`, `difference`, `closed_at` and updates the existing `session` row.
   - MVP MAY also insert `z_report` with `totals` JSON and a simple SHA-256 placeholder signature; PDF/chained signing stays deferred.

4. **Manual cash movements**
   - `POST /api/cash/movement` is for `cash_in` / `cash_out` only and requires `{type, amount, concept}`.
   - Sales/refunds SHOULD be recorded automatically by domain flows, not manually by the UI.

5. **Checkout integration**
   - `CheckoutService` must resolve the active cash session and replace hardcoded `terminal_id` / `session_id` in `order_meta`.
   - On successful checkout, insert a `movement` row **only for the drawer cash impact**:
     - cash sale → `type = sale`, `amount = cash received net of change`
     - mixed payment → `type = sale`, `amount = cash portion only`
     - card/voucher-only sale → no `movement` row needed for expected cash
   - Refund flow is not implemented yet, but the same rule should apply later with `type = refund` and cash impact amount.

6. **Report X**
   - `GET /api/cash/report-x` should be a read model over the active session: opening cash, sales cash, refunds cash, cash_in, cash_out, expected cash, movement counts.
   - For MVP it can return JSON; PDF generation is deferred.

### API Surface

- `POST /api/cash/open`
  - request: `{ opening_cash: number }`
  - response: `{ session_id, terminal_id, opened_at, opening_cash }`
- `GET /api/cash/current`
  - response: `{ session_id, terminal_id, opened_at, opening_cash, expected_cash, sales_cash, refunds_cash, cash_in_total, cash_out_total }`
- `POST /api/cash/close`
  - request: `{ counted_cash: number }`
  - response: `{ session_id, closed_at, expected_cash, counted_cash, difference, z_report_id? }`
- `POST /api/cash/movement`
  - request: `{ type: 'cash_in'|'cash_out', amount: number, concept: string }`
  - response: `{ movement_id, session_id, created_at }`
- `GET /api/cash/report-x`
  - response: current-session aggregate summary

### Schema Usage Decisions

- **`terminal`**: For MVP, resolve a single active terminal for `shop_id`. If none exists, create/bootstrap one default terminal for that shop (`Terminal 1`) instead of hardcoding id `1` blindly.
- **`session`**: Source of truth for cashier lifecycle. One open row per terminal at a time.
- **`movement`**: Represents **cash drawer impact**, not necessarily full order amount. This is the critical modeling decision that keeps `expected_cash` correct for card and mixed payments.
- **`order_meta`**: Always persist real `terminal_id`, `session_id`, `user_id`; keep payment breakdown JSON as the detailed payment audit.
- **`z_report`**: Optional-but-useful MVP persistence on close with JSON totals; real PDF/signature chain can be deferred.

## Risks

- **Open-session uniqueness is not enforced by DB**: MySQL does not provide a simple partial unique index for `closed_at IS NULL`; enforce in service logic with transaction/locking.
- **Terminal resolution is currently missing**: there is no existing terminal bootstrap or selector, so default-terminal resolution must be explicit.
- **Frontend state lies today**: `useSession.js` currently treats localStorage as truth, and `sessionId` is actually `user_id`.
- **Login UI is not real authentication**: the cashier PIN screen is cosmetic/local today; backend identity still comes from SSO session.
- **Cash totals can be wrong if sale movements store gross order totals**: mixed/card payments require cash-impact modeling, not ticket-total modeling.
- **Refund flow is not built yet**: the session model should anticipate it, but MVP will only fully wire sale + manual cash operations.

## Ready for Proposal

**Yes** — enough information is available to move to proposal/spec/design. The next change should focus on a single-default-terminal MVP, real DB-backed cash session lifecycle, checkout wiring to active session, and JSON report X / simplified Z persistence while deferring full multi-terminal UX, refund wiring, and PDF generation.
