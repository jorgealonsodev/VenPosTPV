# Exploration: 03-sso-integration

## Current State

### TPV Side (Symfony 6.4)
- `src/Controller/App/SsoController.php` — placeholder returning "SSO placeholder — implement in future change"
- `config/routes.yaml` — `/sso` route already defined → `SsoController::index` (GET)
- `.env` — `JWT_SECRET=` exists but **empty**; no `SSO_SHARED_SECRET` parameter
- `config/databases.yaml` — has commented-out PS API placeholders (`ps.api.url`, `ps.api.key`)
- **No Symfony Security component** installed — no firewall, no authenticator, no session handler configured
- **No Doctrine ORM** — only DBAL `ConnectionManager` for raw SQL
- DBAL migration exists with `user` table: `ps_employee_id INT UNSIGNED NULL`, `role_id NOT NULL`, `name NOT NULL`, `pin_hash NOT NULL`
- `session` table exists but is for **cash register sessions** (opening/closing), NOT web auth sessions

### PrestaShop Side (tpvconnect module)
- `SsoToken::generate()` produces: `base64url(json({employee_id, shop_id, timestamp, nonce}))~hmac_sha256`
- `SsoToken::verify()` validates: HMAC-SHA256, timestamp ≤ 60s, nonce single-use (stored in PS `Configuration`)
- Token is consumed on verify (nonce deleted after successful verification)
- Shared secret stored in `Configuration::get('TPVCONNECT_SSO_SECRET')` (auto-generated on install, 64 hex chars)
- "Abrir TPV" button injects redirect via `hookDisplayBackOfficeHeader` → `{TPVCONNECT_URL}/sso?token=...`
- Webservice API key management exists (read-only: products, customers, orders, etc.)

### ⚠️ CRITICAL DISCREPANCY: Token Payload
The task prompt describes the token as `{sub: user_id, employee_id, iat, exp (60s), jti (nonce)}` — a JWT-like format.

**The ACTUAL PS module generates**: `{employee_id, shop_id, timestamp, nonce}` — a custom format.

The TPV **MUST** match what the PS module actually generates, not the task prompt's assumed format.

## Affected Areas

- `src/Controller/App/SsoController.php` — implement SSO receive/validate/authenticate flow
- `config/routes.yaml` — route exists; may need adjustment (POST vs GET, redirect target)
- `.env` — needs `SSO_SHARED_SECRET` parameter (or reuse `JWT_SECRET` with rename)
- `config/services.yaml` — needs SSO service registration
- `src/Service/Sso/` — new: token validator, nonce store, user provisioner
- `src/Repository/` — new: `UserRepository` for PS employee lookup
- `migrations/` — new: nonce tracking table (or reuse cache)
- `config/packages/framework.yaml` — may need session configuration
- `modules/tpvconnect/classes/SsoToken.php` — reference only (no changes needed)

## Approaches

### Approach 1: PHP Native Sessions + Symfony Security Authenticator

Use Symfony's built-in session management with a custom `AuthenticatorInterface` implementation:

1. Custom `SsoAuthenticator` validates token on `/sso` route
2. On success, creates `User` entity in DB (if not exists) and logs in via `Authenticator`
3. Subsequent requests use standard PHP session cookie
4. Requires installing `symfony/security-bundle`, `symfony/security-http`

- **Pros**: Standard Symfony patterns, built-in CSRF, session fixation protection, `is_granted()` everywhere
- **Cons**: Adds ~10 new dependencies; overkill for a TPV that's a single-origin app; requires `User` entity + `UserProvider`
- **Effort**: Medium-High

### Approach 2: Lightweight Session + Manual Auth (Recommended for MVP)

No Symfony Security bundle. Simple session-based auth:

1. `SsoController` validates token, creates/looks up user
2. Sets `$_SESSION['tpv_user_id']` and `$_SESSION['tpv_user_name']`
3. Simple middleware/check on protected routes
4. Nonce stored in a dedicated `sso_nonce` table or Symfony cache

- **Pros**: Zero new dependencies, minimal code, full control, matches current architecture (DBAL-only, no ORM)
- **Cons**: No built-in CSRF, no `is_granted()`, manual session management, no password-based fallback
- **Effort**: Low

### Approach 3: JWT-Based Auth (Stateless)

Issue a short-lived JWT after SSO validation, store in httpOnly cookie:

1. `SsoController` validates SSO token, creates/looks up user
2. Issues JWT with `firebase/php-jwt` (already a dependency)
3. JWT stored in httpOnly cookie, validated on each request
4. No server-side session storage needed

- **Pros**: Stateless, `firebase/php-jwt` already installed, works across subdomains if needed
- **Cons**: JWT revocation is hard (need blocklist), overkill for single-server TPV, cookie management complexity
- **Effort**: Medium

## Token Validation: Mirror vs Reuse

The PS module's `SsoToken::verify()` uses `\Configuration` (PS-specific) for nonce storage. The TPV needs its own validator:

| Component | PS Module | TPV (needs) |
|-----------|-----------|-------------|
| HMAC verify | `hash_hmac('sha256', $encodedPayload, $secret)` + `hash_equals` | Same |
| Base64URL decode | Custom `base64UrlDecode` with `\Tools::strlen` | Same logic, pure PHP |
| Timestamp check | `time() - timestamp <= 60` | Same |
| Nonce check | `Configuration::get/set/delete` | DB table or cache adapter |
| Nonce cleanup | SQL on `_DB_PREFIX_.configuration` | Scheduled cleanup or TTL cache |

**Recommendation**: Create `VenPosTPV\Service\Sso\SsoTokenVerifier` as a pure PHP class with injectable nonce storage (interface). This keeps it testable and decoupled from any storage mechanism.

## User Mapping: Auto-Provision vs Pre-Configuration

The `user` table requires: `role_id` (NOT NULL), `name` (NOT NULL), `pin_hash` (NOT NULL).

### Option A: Auto-provision on first SSO
- Look up by `ps_employee_id`; if not found, create user
- Need: default role (e.g., "cashier"), employee name (not in token!), dummy PIN hash
- **Problem**: Employee name is NOT in the token payload. Would need PS Webservice API call to fetch it.

### Option B: Pre-configure users (Recommended for MVP)
- Admin creates TPV users manually, linking `ps_employee_id`
- SSO just looks up existing user by `ps_employee_id`
- If not found → error page: "User not configured. Contact admin."
- **Pros**: No API dependency, no auto-provisioning edge cases, admin controls access
- **Cons**: Requires manual setup per employee

### Option C: Embed employee data in token
- Modify PS module to include `employee_name` and `employee_role` in payload
- Auto-provision becomes trivial
- **Problem**: Changes token format; requires PS module update; name in token could be stale

**Recommendation for MVP**: **Option B** (pre-configure). Phase 2 can add auto-provision with API fetch or embedded data.

## Nonce Storage on TPV Side

Options:
1. **DB table** `sso_nonce` (`nonce CHAR(32) PK, consumed_at DATETIME`) — simple, durable, queryable
2. **Symfony Cache** (filesystem/Redis) with 120s TTL — no schema change, auto-expiry
3. **PHP session** — not durable across requests, not suitable

**Recommendation**: **Option 1** (DB table) for MVP. A `sso_nonce` table is trivial to create, survives restarts, and aligns with the existing DBAL-only architecture. Add a cleanup query in the verifier.

## Shared Secret Location

Current `.env` has `JWT_SECRET=` (empty). Options:

1. **New env var**: `SSO_SHARED_SECRET=` — clear purpose, separate from JWT
2. **Reuse `JWT_SECRET`**: rename concept — confusing since we're not using JWT
3. **Symfony parameter**: `sso_shared_secret: '%env(SSO_SHARED_SECRET)%'` in `databases.yaml`

**Recommendation**: **Option 1** — `SSO_SHARED_SECRET` in `.env`. Clear, explicit, matches PS module's `TPVCONNECT_SSO_SECRET` concept.

## Minimal Viable SSO Flow (MVP Scope)

```
GET /sso?token=...
  ├─ 1. Extract token from query param
  ├─ 2. Validate format (two parts separated by ~)
  ├─ 3. Verify HMAC-SHA256 against SSO_SHARED_SECRET
  ├─ 4. Check timestamp ≤ 60s
  ├─ 5. Check nonce not consumed (sso_nonce table)
  ├─ 6. Mark nonce as consumed
  ├─ 7. Look up user by ps_employee_id
  │   ├─ Found → proceed
  │   └─ Not found → error page "User not configured"
  ├─ 8. Update user.last_login_at
  ├─ 9. Create PHP session with user_id
  └─ 10. Redirect to /app/dashboard (or configured landing page)
```

## Security Considerations

| Concern | Mitigation |
|---------|-----------|
| Token replay | Nonce table + single-use (consume on first valid use) |
| Token expiry | Timestamp check ≤ 60s (matches PS module) |
| Secret rotation | Env var change; old tokens instantly invalid |
| Session fixation | `session_regenerate_id()` on SSO login |
| CSRF on post-SSO actions | Add CSRF tokens to forms (future, not SSO itself) |
| Brute force on `/sso` | Rate limiting (IP-based, 10 req/min) |
| Token in server logs | Token in query param may be logged; use POST or strip from logs |
| Man-in-the-middle | HTTPS required (enforced by PS module config validation) |

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| **Token payload mismatch** (task prompt vs actual code) | HIGH | TPV must match actual PS module: `{employee_id, shop_id, timestamp, nonce}` |
| **No Symfony Security** means no standard auth patterns | MEDIUM | Acceptable for MVP; document manual session pattern; plan Security bundle for Phase 2 |
| **Employee name not in token** prevents auto-provision | MEDIUM | Use pre-configuration for MVP; add API fetch or embedded data in Phase 2 |
| **Nonce table grows unbounded** | LOW | Cleanup consumed nonces older than 120s on each verification (same as PS module) |
| **Query param token in logs/referer** | LOW | Document HTTPS requirement; consider POST redirect in Phase 2 |
| **No role/permissions system yet** | LOW | Default role assignment on pre-config; full RBAC is Phase 2 |

## Decisions Needed

1. **Session mechanism**: PHP native sessions (Approach 2) vs Symfony Security (Approach 1)?
   - **Recommendation**: PHP native sessions for MVP. Zero new deps, matches current DBAL-only architecture.

2. **User provisioning**: Auto-create on first SSO vs pre-configure?
   - **Recommendation**: Pre-configure for MVP. Auto-provision requires PS API call or token format change.

3. **Nonce storage**: DB table vs Symfony Cache?
   - **Recommendation**: DB table `sso_nonce` for MVP. No new deps, durable, simple.

4. **Landing page after SSO**: Where does the user go?
   - **Recommendation**: `/app/dashboard` (to be created in a future change). For MVP, redirect to a simple "Welcome, {name}" page.

5. **Shared secret env var name**: `SSO_SHARED_SECRET` or reuse `JWT_SECRET`?
   - **Recommendation**: `SSO_SHARED_SECRET`. Clear purpose, no confusion with JWT.

## Recommended Scope for Phase 1 (MVP)

### IN SCOPE:
- `SsoTokenVerifier` service (HMAC, timestamp, nonce validation)
- `sso_nonce` table migration (nonce tracking)
- `SsoController` implementation (receive, validate, authenticate, redirect)
- `SSO_SHARED_SECRET` env var in `.env` and `databases.yaml`
- PHP session creation on successful SSO
- Simple session check middleware/check for future protected routes
- Error pages for invalid/expired/replayed tokens
- User lookup by `ps_employee_id` (pre-configured users only)
- `last_login_at` update on successful login

### OUT OF SCOPE (defer to Phase 2+):
- Auto-provisioning users from PS API
- Symfony Security bundle integration
- JWT-based auth
- Role-based access control
- Employee name/role embedded in token
- POST-based SSO (vs GET redirect)
- Dashboard/landing page (separate change)
- PS Webservice API client on TPV side

## Ready for Proposal

**Yes.** The exploration is complete with clear recommendations. The MVP scope is well-defined: implement the SSO receiver on the TPV side that mirrors the PS module's token format, validates it, looks up pre-configured users, and creates a PHP session. No new dependencies required beyond what's already installed.

The critical discrepancy (token payload format) is resolved: the TPV must match `{employee_id, shop_id, timestamp, nonce}`, not the JWT-like format described in the task prompt.
