# Tasks: Cash Session Management MVP

## Phase 1: Foundation (Terminal + Cash Core)

- [x] 1.1 Create `src/Service/Cash/TerminalService.php` to resolve active terminal by `shop_id` and bootstrap one default terminal when none exists.
- [x] 1.2 Create `src/Service/Cash/CashSessionService.php` with `open/current/requireOpenSession/registerManualMovement/close/reportX` contracts and shared DTO/context mapping.
- [x] 1.3 Implement `CashSessionService` aggregate logic over `movement` (`sale/refund/cash_in/cash_out`) and formula `expected_cash = opening + cash_in - cash_out + sales - refunds`.
- [x] 1.4 Implement transactional open/close guards in `CashSessionService` to enforce one open session per terminal and reject duplicate open/close.
- [x] 1.5 Implement PHP session cache helpers in `CashSessionService` for `terminal_id`, `cash_session_id`, `cash_opened_at`, `opening_cash` (set on open, clear on close).

## Phase 2: API Endpoints and Session Integration

- [x] 2.1 Create `src/Controller/Api/CashOpenController.php` (`POST /api/cash/open`) with auth check, `opening_cash >= 0` validation, and service delegation.
- [x] 2.2 Create `src/Controller/Api/CashCurrentController.php` (`GET /api/cash/current`) returning active state/totals or explicit “no open session”.
- [x] 2.3 Create `src/Controller/Api/CashMovementController.php` (`POST /api/cash/movement`) accepting only `cash_in|cash_out`, requiring `amount > 0`, `concept`, and active session.
- [x] 2.4 Create `src/Controller/Api/CashCloseController.php` (`POST /api/cash/close`) validating `counted_cash`, recomputing totals server-side, and closing once.
- [x] 2.5 Create `src/Controller/Api/CashReportXController.php` (`GET /api/cash/report-x`) returning report JSON with totals and movement counts.
- [x] 2.6 Update `src/Controller/App/SsoController.php` to initialize cash keys (`terminal_id`, `cash_session_id`) and clear stale values on fresh login.
- [x] 2.7 Update `src/Controller/App/PosController.php` to expose cash context payload in `window.__SESSION__` for initial frontend hydration.

## Phase 3: Checkout + Frontend Wiring

- [x] 3.1 Update `src/Controller/Api/CheckoutController.php` to require active cash session before `POST /api/checkout` service execution.
- [x] 3.2 Update `src/Service/Checkout/CheckoutService.php` to persist real `terminal_id/session_id` in `order_meta` and record `movement` only for drawer cash impact (cash/mixed).
- [x] 3.3 Update `public/assets/js/api.js` to add clients for `/api/cash/open`, `/api/cash/current`, `/api/cash/movement`, `/api/cash/close`, `/api/cash/report-x`.
- [x] 3.4 Update `public/assets/js/stores/useSession.js` to hydrate from `GET /api/cash/current` and remove placeholder session/terminal generation.
- [x] 3.5 Update `public/assets/js/components/CashOpenScreen.js` to open cash via backend API and transition only on successful response.
- [x] 3.6 Update `public/assets/js/components/CashCloseScreen.js` to load current/report totals, submit `counted_cash`, and show backend close result.
- [x] 3.7 Update `public/assets/js/components/SaleScreen.js` to consume real session totals/ids from store and keep UI aligned with backend source of truth.

## Phase 4: Tests and Verification

- [x] 4.1 Add unit tests for `TerminalService` bootstrap/reuse and `CashSessionService` formulas/open-close/session-key lifecycle.
- [x] 4.2 Add controller tests for cash endpoints covering auth, validations, duplicate-open, missing-session, and close/report responses.
- [x] 4.3 Add checkout tests for active-session requirement, mixed payment cash-impact movement amount, and no placeholder ids persisted.
- [x] 4.4 Add integration test for end-to-end flow: open → checkout sale movement → manual movement → report-x/current totals → close with expected `difference`.
