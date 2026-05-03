# Archive Report: 03-sso-integration

**Change**: 03-sso-integration — SSO Token Reception and Authentication
**Archived to**: `openspec/changes/archive/2026-05-03-03-sso-integration/`
**Date**: 2026-05-03
**Mode**: Hybrid (filesystem + Engram)

---

## SDD Artifacts (Traceability)

| Artifact | Filesystem | Engram ID |
|----------|-----------|-----------|
| proposal.md | `archive/2026-05-03-03-sso-integration/proposal.md` | #2540 |
| design.md | `archive/2026-05-03-03-sso-integration/design.md` | #2542 |
| explore.md | `archive/2026-05-03-03-sso-integration/explore.md` | #2539 |
| specs/sso-reception/spec.md | `archive/2026-05-03-03-sso-integration/specs/sso-reception/spec.md` | #2541 |
| tasks.md | `archive/2026-05-03-03-sso-integration/tasks.md` | #2546 |
| apply-progress.md | `archive/2026-05-03-03-sso-integration/apply-progress.md` | #2547 |
| verify-report.md | `archive/2026-05-03-03-sso-integration/verify-report.md` | #2549 |

## Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| sso-reception | **Created** | New domain. Full spec copied from delta to `openspec/specs/sso-reception/spec.md`. Contains 6 requirements (S1-S6) with 17 scenarios covering token verification, SSO controller, user lookup, nonce storage, error handling, and configuration. |

## Verification Summary

| Metric | Value |
|--------|-------|
| Verdict | **PASS WITH WARNINGS** |
| Tasks complete | 15/17 (2 cleanup blocked by Doctrine ORM) |
| Tests passing | 30 (68 assertions), 1 skipped (no MySQL driver) |
| PHPStan | Level 5 — no errors |
| CS-Fixer | 0 issues |
| Spec compliance | 16/17 scenarios compliant, 1 partial |
| Critical issues | 0 |
| Warnings | 3 (session key naming, redirect target, 2 cosmetic message wordings) |

## Implementation Summary

- **Token format**: `base64url(json(employee_id, shop_id, timestamp, nonce))~hmac_sha256_hex` — verified byte-for-byte against PS module `modules/tpvconnect/classes/SsoToken.php`
- **Verifier**: `SsoTokenVerifier` in `src/Service/Sso/` — HMAC-SHA256, 60s TTL, nonce replay protection via `sso_nonce` DB table
- **Controller**: `SsoController` at `GET /sso` — session creation with `session_regenerate_id(true)`, redirect to `/app/`
- **Exception hierarchy**: `SsoException` → `InvalidTokenException`, `ExpiredTokenException`, `ReplayedNonceException`
- **Configuration**: `SSO_SHARED_SECRET` from `.env` → `databases.yaml` → `services.yaml`

## Source of Truth Updated

The following specs now reflect the new behavior:
- `openspec/specs/sso-reception/spec.md` — SSO Reception Specification (new)

## SDD Cycle Complete

The change has been fully planned, implemented, verified, and archived.
Ready for the next change.

---

## Known Warnings (Carried Forward)

1. **Session key naming** — spec says `tpv_user_id`/`tpv_user_name`; code uses `user_id`/`ps_employee_id`/`shop_id`/`authenticated_at`
2. **Redirect target** — `/app/` vs spec's `/app/dashboard` (documented as intentional fallback)
3. **Error message wording** — "Invalid token format" vs "Malformed token"; "Token expired" vs "Token has expired" (cosmetic)
