## Exploration: 08-test-environment-parity-and-cash-edge-coverage

### Current State
- `ProductSearchServiceTest` is a DB-backed "unit" test that bootstraps an in-memory SQLite connection via `pdo_sqlite`. In environments without that extension, the suite fails before assertions run.
- `CatalogSyncServiceTest` is already driver-free (pure `Connection`/API client mocks), so it is stable across runtimes.
- Cash coverage is currently narrow in `CashSessionServiceTest` (only one formula test for `expected_cash`).
- Cash behavior in checkout is implemented in `CheckoutService::resolveCashImpactAmount()`: `cash` uses gross total, `mixed` uses `cart_data['cash_amount']`, non-cash uses `0`.
- Existing integration path (`SalesApiIntegrationTest`) validates end-to-end cash flow for pure cash payment, but does not explicitly validate mixed-payment drawer impact or duplicate open-session rejection.

### Affected Areas
- `tests/Unit/Service/Catalog/ProductSearchServiceTest.php` — hard dependency on `pdo_sqlite`.
- `src/Service/Catalog/ProductSearchService.php` — SQL/query-builder behavior currently validated mainly through SQLite-backed tests.
- `tests/Unit/Service/Cash/CashSessionServiceTest.php` — missing explicit edge tests for duplicate open and mixed-payment semantics.
- `src/Service/Cash/CashSessionService.php` — duplicate open-session guard is in `openSession()` and should be directly asserted.
- `tests/Unit/Service/Checkout/CheckoutServiceTest.php` — best place to assert `mixed` payment only records cash portion.
- `src/Service/Checkout/CheckoutService.php` — contains cash-impact rule; likely no production change needed if tests confirm behavior.

### Approaches
1. **Enable `pdo_sqlite` in CI/runtime and keep current ProductSearch test strategy**
   - Pros: Preserves real query validation for `ProductSearchService`; minimal test rewrite.
   - Cons: Adds environment coupling and drift risk between local/CI/container; does not improve true unit isolation.
   - Effort: Low.

2. **Replace SQLite-backed ProductSearch tests with mocked DBAL/query-builder chain**
   - Pros: Fully driver-free unit tests; no extension dependency.
   - Cons: Brittle tests coupled to query-builder internals; lower confidence in actual SQL behavior.
   - Effort: Medium/High.

3. **Split responsibility: keep DB-driver-free unit tests + move SQL behavior to explicit integration tests (MySQL-gated)**
   - Pros: Clean test taxonomy (unit vs integration), no env-only unit failures, SQL still validated against real production-like engine.
   - Cons: Requires refactor of current ProductSearch test suite and possible new integration test class; integration remains skip-gated when MySQL unavailable.
   - Effort: Medium.

### Recommendation
Adopt **Approach 3**.

Recommended scope:
1. Convert `ProductSearchServiceTest` into driver-free unit tests that validate mapping/business guarantees (type casting, empty/null query handling decisions if mocked result sets are used).
2. Add/extend MySQL-gated integration coverage for `ProductSearchService` query semantics (search by name/reference/ean, category filter, stock join, combinations) similar to existing integration style used in `CatalogSyncEndToEndTest` and `SalesApiIntegrationTest`.
3. Add explicit unit tests for cash edges:
   - `CashSessionService::openSession()` throws when an open session already exists on the resolved terminal.
   - `CheckoutService` with `payment_method = mixed` calls `addMovement(..., 'sale', cash_amount, ...)` using **only** `cash_amount`, not cart total.
   - Optional hardening: mixed payment without `cash_amount` records `0` movement (or no movement), documenting intended rule.

This keeps production behavior stable while fixing parity issues where they belong: test architecture and environment contracts.

### Risks
- If ProductSearch integration coverage is not added after removing SQLite dependency, confidence in SQL correctness can regress.
- Mock-heavy unit tests for query builders can become implementation-coupled and noisy.
- Mixed-payment rules depend on payload shape (`cash_amount`); missing validation could hide upstream API contract issues.

### Ready for Proposal
Yes — proceed to **sdd-propose** with scope focused on:
- test-suite parity refactor (unit/integration split for catalog search), and
- explicit cash edge test coverage (duplicate-open + mixed-payment drawer impact).
