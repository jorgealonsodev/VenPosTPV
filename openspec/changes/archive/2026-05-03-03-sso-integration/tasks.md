# Tasks: 03-sso-integration — SSO Token Reception and Authentication

## Phase 1: Infrastructure

- [x] 1.1 Create `migrations/Version00000002CreateSsoNonceTable.php` — DDL: `nonce VARCHAR(64) PK`, `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, ENGINE=InnoDB; up() creates table, down() drops it
- [x] 1.2 Add `SSO_SHARED_SECRET=` to `.env` and `SSO_SHARED_SECRET=` to `.env.example`
- [x] 1.3 Add `sso.shared_secret: '%env(SSO_SHARED_SECRET)%'` to `config/databases.yaml`
- [x] 1.4 Wire `SsoTokenVerifier` in `config/services.yaml` with `$sharedSecret` and `$connection` constructor args

## Phase 2: Core Implementation

- [x] 2.1 Create `src/Service/Sso/Exception/SsoException.php` — abstract class extending `\RuntimeException`
- [x] 2.2 Create `src/Service/Sso/Exception/InvalidTokenException.php` — extends `SsoException` (400 realm)
- [x] 2.3 Create `src/Service/Sso/Exception/ExpiredTokenException.php` — extends `SsoException` (401 realm)
- [x] 2.4 Create `src/Service/Sso/Exception/ReplayedNonceException.php` — extends `SsoException` (401 realm)
- [x] 2.5 Create `src/Service/Sso/SsoTokenVerifier.php` — verify token: split on `~`, HMAC-SHA256 with `hash_equals`, base64url decode JSON, timestamp ≤60s check, nonce INSERT (dup key = replay), auto-delete nonces >120s old; returns `array{employee_id, shop_id, timestamp, nonce}`

## Phase 3: Integration

- [x] 3.1 Modify `src/Controller/App/SsoController.php` — `index(Request)`: extract `token` query param → call `SsoTokenVerifier::verify()` → on success: lookup user by `ps_employee_id`, call `session_regenerate_id(true)`, set `$_SESSION[tpv_user_id]`, `$_SESSION[ps_employee_id]`, `$_SESSION[shop_id]`, `$_SESSION[authenticated_at]`, update `last_login_at`, redirect 302 to `/app/dashboard`; on missing token return 400 JSON; on SsoException return corresponding HTTP error JSON; on user-not-found return 403 JSON
- [x] 3.2 Verify `/sso` route in `config/routes.yaml` points to `SsoController::index`

## Phase 4: Testing

- [x] 4.1 Unit test `SsoTokenVerifier` — mock Connection; cover: valid token, malformed format (no `~`), bad HMAC, expired timestamp, replayed nonce (INSERT throws), empty secret → 500
- [x] 4.2 Unit test `SsoController` — mock `SsoTokenVerifier` and `ConnectionManagerInterface`; cover: missing token → 400, invalid token → 400/401, valid token + user found → redirect, valid token + user not found → 403, misconfigured secret → 500
- [x] 4.3 Integration test migration `Version00000002` — run up() against test MySQL, assert `sso_nonce` table exists with correct columns; run down(), assert table dropped

## Phase 5: Cleanup

- [ ] 5.1 Clear Symfony cache (`cache:clear`)
- [ ] 5.2 Run migration `doctrine:migrations:migrate`

(End of file - total 32 lines)
