# Proposal: Test Environment Parity and Cash Edge Coverage

## Intent

Stabilize the testing baseline by removing environment-specific failures from unit tests while preserving confidence in SQL behavior and cash-drawer rules.

## Scope

### In Scope
- Refactor `ProductSearchServiceTest` to be driver-free (no hard `pdo_sqlite` dependency).
- Add MySQL-gated integration tests for catalog search SQL semantics (name/reference/EAN, category filtering, stock joins, combinations).
- Add explicit cash edge tests for duplicate open-session rejection and mixed-payment cash impact amount.
- Touch production code only if tests prove missing validation in mixed-payment input handling.

### Out of Scope
- New checkout/cash business features.
- Mandatory CI installation of SQLite extensions as the primary fix.
- Broad rewrite of existing integration infrastructure.

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `sales-screen`: Clarify/verify search behavior contract via parity-safe test architecture.
- `cash-session-management`: Enforce spec-level duplicate-open rejection and mixed-payment cash-impact assertions.
- `checkout-integration`: Tighten behavioral guarantees around `mixed` payment cash movement amount.

## Approach

Adopt a split-test strategy: keep unit tests deterministic and driver-free, and move SQL-specific confidence to explicit MySQL-gated integration tests. Add targeted edge tests in cash/checkout services first; only introduce production validation if failing tests expose a real contract gap.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `tests/Unit/Service/Catalog/ProductSearchServiceTest.php` | Modified | Remove SQLite bootstrap and assert mapping/business guarantees using mocks/stubs. |
| `tests/Integration/**` (catalog search) | New/Modified | Add MySQL-gated scenarios for real SQL semantics. |
| `tests/Unit/Service/Cash/CashSessionServiceTest.php` | Modified | Add duplicate open-session rejection test. |
| `tests/Unit/Service/Checkout/CheckoutServiceTest.php` | Modified | Assert `mixed` uses only `cash_amount` for drawer movement. |
| `src/Service/Checkout/CheckoutService.php` | Conditional Modified | Add stricter mixed-payment validation only if tests demonstrate gap. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| SQL confidence drops after unit refactor | Med | Require MySQL-gated integration coverage before considering change complete. |
| Over-mocked unit tests become brittle | Med | Assert externally visible behavior, not query-builder internals. |
| Ambiguous `cash_amount` contract for mixed payments | Low/Med | Add explicit tests first; patch production only when failing scenario proves need. |

## Rollback Plan

Revert the proposal’s implementation commits: restore prior `ProductSearchServiceTest`, remove new integration/edge tests, and revert any conditional production validation changes in `CheckoutService`.

## Dependencies

- MySQL test environment available for gated integration suite execution.

## Success Criteria

- [ ] Unit suite no longer fails when `pdo_sqlite` is unavailable.
- [ ] MySQL-gated integration tests cover catalog search SQL semantics and pass when MySQL is present.
- [ ] Cash tests explicitly verify duplicate open-session rejection and `mixed` cash-impact amount rules.
- [ ] No production behavior changes unless backed by failing edge-case tests.
