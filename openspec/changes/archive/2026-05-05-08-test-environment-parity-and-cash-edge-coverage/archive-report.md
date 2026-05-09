# Archive Report

**Change**: `08-test-environment-parity-and-cash-edge-coverage`  
**Date**: `2026-05-05`

## Preconditions

- Verification report reviewed: `openspec/changes/archive/2026-05-05-08-test-environment-parity-and-cash-edge-coverage/verify-report.md`
- Verification verdict: **PASS WITH WARNINGS** — 0 CRITICAL, 2 WARNING, 2 SUGGESTION
- Critical code issues: **None**

## Delta Sync to Main Specs

| Domain | Action | Details |
|--------|--------|---------|
| `test-parity-and-cash-edge-coverage` | Created | New main spec created from delta spec — 5 requirements, 9 scenarios covering driver-free unit coverage, MySQL-gated integration, duplicate-open rejection, mixed-payment cash impact, and environment-aware execution. |

### No Changes to Existing Specs

The linked capability deltas (`sales-screen`, `cash-session-management`, `checkout-integration`) are **informational cross-references** indicating which pre-existing behavioral requirements now have automated test coverage. All three main specs already contained the behavioral requirements:
- `cash-session-management/spec.md` — already had "Duplicate open blocked" (Requirement: Open Session) and "Mixed payment stores cash impact only" (Requirement: Checkout Cash Context)
- `checkout-integration/spec.md` — already had "records only drawer cash impact in movement" (R1)
- `sales-screen/spec.md` — testing strategy is a separate concern from screen behavior

## Implementation Summary

| Metric | Value |
|--------|-------|
| Tasks total | 15 |
| Tasks complete | 15 |
| Tests (full suite) | 143 passed, 0 failed, 13 skipped |
| Assertions | 301 |
| PHPStan | Clean |
| PHP CS Fixer | Clean |
| Production code changes | None needed (current behavior matched spec) |

### Files Changed
- `tests/Unit/Service/Catalog/ProductSearchServiceTest.php` — Modified: 5 DB-driver-free unit tests
- `tests/Integration/Service/Catalog/ProductSearchServiceMySqlIntegrationTest.php` — Created: 218 lines, 4 MySQL-gated integration tests
- `tests/Unit/Service/Cash/CashSessionServiceTest.php` — Modified: added duplicate-open rejection test
- `tests/Unit/Service/Checkout/CheckoutServiceTest.php` — Modified: added 2 mixed-payment cash-impact tests (10 total)

## Archive Move

- Source: `openspec/changes/08-test-environment-parity-and-cash-edge-coverage/`
- Destination: `openspec/changes/archive/2026-05-05-08-test-environment-parity-and-cash-edge-coverage/`

## Verification of Archive

- [x] Main specs updated correctly (`openspec/specs/test-parity-and-cash-edge-coverage/spec.md`)
- [x] Change folder moved to archive
- [x] Archive contains all artifacts: proposal, explore, design, specs, tasks, apply-progress, verify-report, archive-report
- [x] Active `openspec/changes/` contains only `archive/` — no active change directories remaining

## Unresolved Warnings (from verification)

1. **Pre-existing PHP warnings in 3 CheckoutService tests** — `testClearCartIsCalledAfterSuccess`, `testSagaLogsOrphanWhenOrderMetaFails`, `testSagaLogsOrphanWhenStockUpdateFails` trigger `Undefined array key "terminal_id"` and `"session_id"`. These are pre-existing and not introduced by this change.

2. **MySQL integration coverage not validated in this environment** — All 4 `ProductSearchServiceMySqlIntegrationTest` tests skip because MySQL is unavailable. Confirmed passing in MySQL-enabled environment during apply phase.

## Recommendations for Next Change

1. **Extract `MySqlIntegrationTestCase` base class** — Shared base with `setUp()`/`tearDown()`/`isMySqlAvailable()` would reduce duplication across `ProductSearchServiceMySqlIntegrationTest` and `SalesApiIntegrationTest`.
2. **Install coverage driver** — Xdebug or pcov would enable code coverage reporting.
3. **Fix the 3 pre-existing CheckoutService test warnings** by mocking `requireOpenSession()` return value.
