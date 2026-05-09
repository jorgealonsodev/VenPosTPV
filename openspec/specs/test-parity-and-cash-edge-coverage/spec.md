# Test Parity and Cash Edge Coverage Specification

## Purpose

Define enforceable test-parity and edge-coverage expectations so unit tests remain environment-independent while SQL and cash-drawer behavior keeps production-level confidence.

## Requirements

### Requirement: Driver-Free Product Search Unit Coverage

The system MUST keep `ProductSearchService` unit coverage driver-free and SHALL NOT require `pdo_sqlite` or any DB engine extension to execute unit assertions.

#### Scenario: Unit suite runs without SQLite driver

- GIVEN a runtime where `pdo_sqlite` is unavailable
- WHEN the unit test suite executes product-search unit tests
- THEN tests execute and assert service behavior without DB-driver bootstrap failures

#### Scenario: Unit tests assert behavior contract only

- GIVEN `ProductSearchService` dependencies represented by test doubles
- WHEN unit tests validate search behavior
- THEN assertions target externally visible outcomes (mapping, filtering decisions, return shape)
- AND MUST NOT couple to query-builder call-order internals

### Requirement: MySQL-Gated SQL Semantics Integration Coverage

The system MUST validate SQL-specific catalog search behavior in integration tests gated by MySQL availability; when MySQL is unavailable, these tests MUST be skipped with explicit reason text.

#### Scenario: SQL behavior covered when MySQL is present

- GIVEN MySQL integration environment is available
- WHEN integration tests run for catalog search
- THEN tests verify name/reference/EAN matching, category filtering, stock joins, and combination handling against real SQL execution

#### Scenario: Deterministic skip when MySQL is absent

- GIVEN MySQL integration environment is not available
- WHEN the integration suite reaches SQL-specific catalog-search tests
- THEN those tests are skipped (not failed)
- AND skip output states MySQL dependency requirements

### Requirement: Duplicate Open Session Rejection Coverage

The system MUST include coverage proving duplicate cash-session opens are rejected for the resolved terminal context.

#### Scenario: Duplicate open attempt is rejected

- GIVEN an already open session exists for the resolved terminal
- WHEN `openSession()`/`POST /api/cash/open` is executed again
- THEN the operation is rejected and no second open session is created

### Requirement: Mixed Payment Cash-Impact Coverage

The system MUST include coverage proving mixed-payment sale movement records only drawer cash impact (`cash_amount`) and not full ticket total.

#### Scenario: Mixed payment uses cash portion only

- GIVEN checkout payload with `payment_method = mixed`, `total = 50`, and `cash_amount = 20`
- WHEN checkout finalizes and records cash movement
- THEN movement amount equals `20` and SHALL NOT equal `50`

#### Scenario: Mixed payment with missing cash amount is explicit

- GIVEN checkout payload with `payment_method = mixed` and no valid `cash_amount`
- WHEN checkout validation and movement logic execute
- THEN behavior is explicitly asserted by tests as either rejected input or zero cash movement
- AND selected behavior remains consistent across unit and integration coverage

### Requirement: Full Suite Stability and Environment-Aware Execution

The system MUST enforce stable test outcomes across developer and CI environments via clear split of unit vs integration responsibilities.

#### Scenario: Unit suite parity across environments

- GIVEN two environments with different PHP extensions
- WHEN unit tests are executed
- THEN pass/fail outcomes are equivalent for non-flaky code changes

#### Scenario: Integration gates do not mask regressions

- GIVEN MySQL is available in CI
- WHEN full suite executes
- THEN SQL-specific integration tests run and fail on real regressions
- AND unit suite remains independent from SQL-driver availability

## Linked Capability Deltas

- `sales-screen`: Search behavior confidence is split into driver-free unit guarantees plus MySQL-gated SQL semantics verification.
- `cash-session-management`: Duplicate-open rejection is now mandatory to assert through automated coverage.
- `checkout-integration`: Mixed-payment drawer impact MUST be verified as cash-portion-only behavior with explicit missing-cash_amount handling.
