# Tasks: Test Environment Parity and Cash Edge Coverage

## Phase 1: ProductSearch Unit/Integration Split Foundation

- [x] 1.1 Refactor `tests/Unit/Service/Catalog/ProductSearchServiceTest.php` to remove SQLite bootstrap/DDL/seed setup and replace DB usage with test doubles for `Connection`/results.
- [x] 1.2 Re-scope unit assertions in `ProductSearchServiceTest.php` to behavior contract only (mapping, null/type handling, empty results, combination mapping), avoiding query-builder call-order coupling.
- [x] 1.3 Add/update helper fixtures inside `ProductSearchServiceTest.php` so tests run without any DB driver extension (`pdo_sqlite` absent scenario).

## Phase 2: MySQL-Gated SQL Semantics Integration Coverage

- [x] 2.1 Create `tests/Integration/Service/Catalog/ProductSearchServiceMySqlIntegrationTest.php` with runtime MySQL gate and explicit skip reason: `MySQL database not available for integration testing`.
- [x] 2.2 Add seeded integration scenarios for `search()` matching by name, reference, and EAN in `ProductSearchServiceMySqlIntegrationTest.php`.
- [x] 2.3 Add integration scenarios for category filtering, stock joins, and combination stock behavior in `ProductSearchServiceMySqlIntegrationTest.php`.
- [x] 2.4 Add integration scenarios for `findById()` and `getActiveCategories()` real SQL behavior, failing when MySQL is available but assertions break.

## Phase 3: Cash Edge Test Coverage

- [x] 3.1 Extend `tests/Unit/Service/Cash/CashSessionServiceTest.php` with duplicate-open scenario: existing open session for resolved terminal must reject `openSession()` and prevent second open insert.
- [x] 3.2 Extend `tests/Unit/Service/Checkout/CheckoutServiceTest.php` with mixed-payment scenario (`total=50`, `cash_amount=20`) asserting `addMovement('sale', 20, ...)` and not `50`.
- [x] 3.3 Add missing/invalid `cash_amount` mixed-payment scenario in `CheckoutServiceTest.php`, asserting explicit behavior aligned with current contract (reject OR zero cash impact).

## Phase 4: Minimal Production Validation (Only if RED Test Proves Gap)

- [x] 4.1 If task 3.3 exposes ambiguous behavior, apply minimal guardrails in `src/Service/Checkout/CheckoutService.php::resolveCashImpactAmount()` without changing non-`mixed` methods.
- [x] 4.2 Add/adjust targeted unit assertions in `CheckoutServiceTest.php` to lock the chosen mixed-payment edge behavior and prevent regressions.

## Phase 5: Final Suite Verification

- [x] 5.1 Run unit tests for `ProductSearchServiceTest`, `CashSessionServiceTest`, and `CheckoutServiceTest` in an environment without SQLite driver and confirm parity-stable pass/fail outcomes.
- [x] 5.2 Run MySQL integration tests for `ProductSearchServiceMySqlIntegrationTest.php` in MySQL-enabled environment; verify SQL semantics assertions execute (not skipped).
- [x] 5.3 Run full project test suite with CI-equivalent split (unit always, integration with MySQL) and confirm no regressions or dependency-related false failures.
