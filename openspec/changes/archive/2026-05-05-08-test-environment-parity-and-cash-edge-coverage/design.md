# Design: Test Environment Parity and Cash Edge Coverage

## Technical Approach

Split responsibilities by test layer: keep `ProductSearchService` unit tests DB-driver-free (behavioral mapping and branching only), and move SQL semantics to MySQL-gated integration tests. Add focused cash-edge tests in unit checkout/cash services, then allow minimal production hardening only if a RED test proves a contract gap.

## Architecture Decisions

| Decision | Options | Tradeoff | Choice |
|---|---|---|---|
| Product search test strategy | (A) keep sqlite unit DB, (B) full query-builder mocks, (C) unit+integration split | A keeps env fragility; B risks brittle internals; C balances parity and SQL confidence | **C** |
| Integration gating behavior | (A) fail when MySQL unavailable, (B) skip with explicit message | A blocks local/unit workflows; B can hide misconfigured CI if overused | **B**, with strict skip criteria |
| Mixed-payment validation | (A) keep current `max(0, cash_amount)` behavior, (B) enforce stricter input validation now | B may be breaking without evidence | **A initially**, move to B only if failing edge test proves ambiguity |

## Data Flow

```
Unit suite
  ProductSearchServiceTest (mocked Connection/Result)
      └─ verifies mapping + branching only

Integration suite (MySQL)
  ProductSearchServiceMySqlIntegrationTest
      └─ real DBAL queries validate LIKE/category/stock/combinations

Checkout cash flow
  CheckoutService::createOrder
      └─ resolveCashImpactAmount()
          └─ CashSessionService::addMovement('sale', amount)
```

## File Changes

| File | Action | Description |
|---|---|---|
| `tests/Unit/Service/Catalog/ProductSearchServiceTest.php` | Modify | Remove sqlite bootstrap/DDL/seed usage; re-scope tests to service-level behavior (type casting, null handling, empty results, combinations mapping). |
| `tests/Integration/Service/Catalog/ProductSearchServiceMySqlIntegrationTest.php` | Create | MySQL-gated SQL semantics tests for `search()`, `findById()`, `getActiveCategories()` with seeded fixtures. |
| `tests/Integration/Controller/Api/SalesApiIntegrationTest.php` | Optional Modify | Keep as-is unless a small shared MySQL bootstrap helper is extracted to reduce duplication. |
| `tests/Unit/Service/Cash/CashSessionServiceTest.php` | Modify | Add duplicate-open-session rejection test for `openSession()`. |
| `tests/Unit/Service/Checkout/CheckoutServiceTest.php` | Modify | Add mixed-payment cash movement assertions (`cash_amount` only; optional missing `cash_amount` case). |
| `src/Service/Checkout/CheckoutService.php` | Conditional Modify | Only if new tests show invalid mixed payloads should fail/normalize differently. |

## Interfaces / Contracts

No public API contract changes are required by default.

Behavioral contract to codify in tests:

```php
// For payment_method === 'mixed'
cashImpact = round(max(0.0, (float)($cartData['cash_amount'] ?? 0.0)), 2);
```

If a RED test shows missing validation (e.g., non-numeric or contradictory values), add minimal guardrails in `resolveCashImpactAmount()` without changing other payment methods.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit | `ProductSearchService` mapping/branch behavior without DB driver | Mock `Connection` and query results; avoid asserting SQL strings/query-builder internals. |
| Integration | Real MySQL query semantics for catalog search | Seed minimal fixture rows; assert name/reference/EAN matches, category filter, stock joins, combination stock, active-category filtering. |
| Unit | Cash duplicate-open guard | Mock transactional closure + `fetchOne` active session; assert `RuntimeException` and no insert. |
| Unit | Checkout mixed cash impact | Assert `addMovement('sale', cash_amount, ...)` receives cash portion only; add missing `cash_amount` scenario expecting zero movement. |

## Skip vs Fail Criteria

- **Skip** only when external dependency is unavailable at runtime (cannot connect to MySQL or not `MySQLPlatform`) in tests under `tests/Integration/**`, with message: `MySQL database not available for integration testing`.
- **Fail** when MySQL is available but schema/fixtures/assertions fail.
- **Fail** for all unit tests on any unmet assertion; unit tests MUST NOT skip for DB-driver reasons.
- CI recommendation: run unit suite always; run integration suite in MySQL-enabled job to convert potential skips into enforced pass/fail.

## Migration / Rollout

No migration required.

Rollout order:
1. Refactor unit ProductSearch tests.
2. Add MySQL integration ProductSearch coverage.
3. Add cash-edge unit tests.
4. Only then evaluate whether production validation patch is required.

## Open Questions

- [ ] Should we introduce a shared `MySqlIntegrationTestCase` now, or defer to keep this change minimal?
- [ ] For `mixed` without `cash_amount`, should behavior remain silent-zero or become explicit validation error?
