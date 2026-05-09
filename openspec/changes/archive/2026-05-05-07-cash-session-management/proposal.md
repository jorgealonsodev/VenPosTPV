# Proposal: Cash Session Management MVP

## Intent

Replace fake checkout session/terminal placeholders with a DB-backed cash drawer lifecycle so sales, manual cash movements, and close totals use real persistence.

## Scope

### In Scope
- Open a cash session in `session`, resolving or bootstrapping one default `terminal` per `shop_id`.
- Persist `cash_session_id` and `terminal_id` in PHP session and expose current state to the POS shell.
- Attach checkout `order_meta` and cash-impact `movement` rows to the real session/terminal.
- Close the session with `counted_cash`, computed `expected_cash`, `difference`, and JSON report X endpoints.

### Out of Scope
- Fiscal Z PDF/signature chain.
- Refund workflow, multi-terminal assignment UI, offline reconciliation.

## Capabilities

### New Capabilities
- `cash-session-management`: Open/current/close/report-x lifecycle, default terminal bootstrap, and manual `cash_in`/`cash_out` movements.

### Modified Capabilities
- `checkout-integration`: Checkout MUST require an active cash session, persist real `terminal_id`/`session_id`, and record only drawer cash impact.
- `sso-reception`: Authenticated PHP sessions MUST carry terminal/cash-session context for downstream APIs.
- `sales-screen`: Cash open/close UI MUST use backend APIs and render real session totals instead of local placeholders.

## Approach

Adopt the single-default-terminal MVP. Add a cash session service + API layer around `terminal`, `session`, `movement`, and `z_report`; use PHP session as the transport cache for active ids while the database remains the source of truth.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Controller/App/SsoController.php` | Modified | Persist terminal/session context in PHP session |
| `src/Controller/App/PosController.php` | Modified | Hydrate frontend with real cash session state |
| `src/Controller/Api/*Cash*.php` | New | Open/current/close/movement/report-x endpoints |
| `src/Controller/Api/CheckoutController.php` | Modified | Reject or resolve checkout without active cash session |
| `src/Service/Checkout/CheckoutService.php` | Modified | Persist real ids and sale movements |
| `public/assets/js/stores/useSession.js` | Modified | Mirror backend cash state |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Two open sessions for one terminal | Med | Enforce in transactional service logic |
| Wrong expected cash on mixed/card sales | Med | Persist movement amount as cash impact only |
| Frontend/local session drift | Med | `GET /api/cash/current` becomes source of truth |

## Rollback Plan

Disable new cash endpoints and restore checkout/session placeholder behavior while keeping inserted cash data untouched for audit.

## Dependencies

- Existing tables: `terminal`, `session`, `movement`, `order_meta`, `z_report`.

## Success Criteria

- [ ] Cash open/current/close/movement/report-x work against persisted DB state.
- [ ] Checkout stores real `terminal_id`/`session_id` and sale cash movements only when cash impacts the drawer.
- [ ] Closing a session returns `expected_cash`, `counted_cash`, and `difference` from real aggregates.
