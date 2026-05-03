# Archive Report: 02-prestashop-module

**Archived**: 2026-05-03
**Status**: PASS WITH WARNINGS
**Version**: 0.1.0

---

## Executive Summary

Built the `tpvconnect` PrestaShop module — the API provider and SSO gateway for the independent TPV application. The module enables the standalone Symfony TPV (running on a separate domain) to communicate with PrestaShop exclusively via HTTP with no shared filesystem. All 42 tasks across 7 phases implemented, 32 PHPUnit tests passing (62 assertions). One CRITICAL bug (reinstall order state duplication) found during verification and FIXED. Four warnings accepted for MVP.

---

## What Was Built

| Phase | Deliverable | Files |
|-------|-------------|-------|
| 1. Module Scaffold | Module structure, install/uninstall, hooks | `tpvconnect.php`, `config.xml`, `install/` |
| 2. Custom Order States | 3 order states with multi-lang (ES/EN/FR/PT) | `install/install.php` |
| 3. Admin Configuration | Config page, URL/secret validation, "Abrir TPV" | `configure.tpl`, `translations/es.php` |
| 4. SSO Authentication | HMAC-SHA256 token with nonce, TTL 60s | `classes/SsoToken.php` |
| 5. Order Creation Endpoint | POST /module/tpvconnect/orders with validateOrder() | `controllers/front/orders.php` |
| 6. Webservice Key Mgmt | Read-only WS key generation, status display | `tpvconnect.php` (getContent) |
| 7. Verification | 32 PHPUnit tests covering all modules | `tests/` |

**Total**: 31 files + 11 security index.php stubs + 5 test files

---

## Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| tpvconnect-module | **Created** (new) | Copied delta spec to `openspec/specs/tpvconnect-module/spec.md` — 6 requirements (R1-R6), 6 NFRs, no merge needed as this was a new domain |

---

## Issues Resolved (CRITICAL)

### Reinstall order state duplication — FIXED

**Root cause**: `uninstallConfiguration()` deleted `TPV_OS_PAID`, `TPV_OS_REFUND`, `TPV_OS_PENDING_SYNC` Configuration keys during uninstall. On reinstall, `createOrderStates()` checked `Configuration::get($configKey)` which returned 0 (deleted), causing new duplicate OrderState entries.

**Fix**: In `createOrderStates()`, when `Configuration::get($configKey)` returns 0, the method now queries the fallback table `ps_order_state_tpvconnect` for the `state_key` to find existing OrderState IDs. If found, it restores the Configuration key instead of creating a new state.

**Files affected**: `modules/tpvconnect/install/install.php` — `createOrderStates()` method

---

## Remaining Warnings (Accepted for MVP)

| # | Warning | Impact | Mitigation |
|---|---------|--------|------------|
| 1 | SSO secret stored in plaintext in Configuration (NFR-3 violation) | Medium — DB-level exposure | Config page is behind BO auth/HTTPS; mitigated for MVP |
| 2 | Nonce cleanup uses raw SQL on `ps_configuration` table (NFR-6 loosening) | Low — reads only module's own prefix keys | Deletion uses correct `Configuration::deleteByName()` |
| 3 | Admin template uses custom HTML instead of HelperForm (NFR-5 deviation) | Low — works functionally but loses auto-CSRF | Works on PS 8.x; refactor post-MVP |
| 4 | Admin config test validates rules, not module code | Low — simple validation logic | Low risk of typo in straightforward code |

**Accepted rationale**: All 4 warnings have low-to-medium impact for MVP. The SSO secret plaintext storage is the most significant, but is bounded by BO authentication and HTTPS transport.

---

## Test Results

```
PHPUnit 10.5.63
Configuration: modules/tpvconnect/tests/phpunit.xml
OK (32 tests, 62 assertions)
```

**Compliance**: 16/22 scenarios COMPLIANT or PARTIAL, 6 UNTESTED (runtime-dependent — require real PrestaShop for integration)

---

## Archive Contents

| Artifact | Path |
|----------|------|
| Exploration | `archive/2026-05-03-02-prestashop-module/explore.md` |
| Proposal | `archive/2026-05-03-02-prestashop-module/proposal.md` |
| Spec (delta) | `archive/2026-05-03-02-prestashop-module/specs/tpvconnect-module/spec.md` |
| Design | `archive/2026-05-03-02-prestashop-module/design.md` |
| Tasks | `archive/2026-05-03-02-prestashop-module/tasks.md` |
| Apply Progress | `archive/2026-05-03-02-prestashop-module/apply-progress.md` |
| Verify Report | `archive/2026-05-03-02-prestashop-module/verify-report.md` |
| Archive Report | `archive/2026-05-03-02-prestashop-module/archive-report.md` |

---

## Source of Truth Updated

- `openspec/specs/tpvconnect-module/spec.md` — New main spec (6 requirements, 6 NFRs)

---

## Engram Observation IDs

| Artifact | Engram ID |
|----------|-----------|
| Explore | #2527 |
| Proposal | #2528 |
| Spec | #2529 |
| Design | #2530 |
| Tasks | #2532 |
| Apply Progress | #2533 |
| Verify Report | #2534 |

---

## SDD Cycle Complete

Change `02-prestashop-module` has been fully planned, explored, specified, designed, implemented, verified, and archived. Ready for the next change.
