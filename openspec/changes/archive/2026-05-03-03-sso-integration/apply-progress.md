# Apply Progress: 03-sso-integration

## Status
**15/17 tasks complete** (Phase 5 task 5.2 blocked by missing Doctrine ORM/console integration)

**Mode**: Standard (Strict TDD disabled)

## Completed Tasks

### Phase 1: Infrastructure
- [x] 1.1 Migration `migrations/Version00000002CreateSsoNonceTable.php`
- [x] 1.2 `.env` and `.env.example` updated with `SSO_SHARED_SECRET=`
- [x] 1.3 `config/databases.yaml` updated with `sso.shared_secret: '%env(SSO_SHARED_SECRET)%'`
- [x] 1.4 `config/services.yaml` wired `SsoTokenVerifier` with factory `doctrine.dbal.tpv_connection`

### Phase 2: Core Implementation
- [x] 2.1 `src/Service/Sso/Exception/SsoException.php` — abstract class extending `\RuntimeException`
- [x] 2.2 `src/Service/Sso/Exception/InvalidTokenException.php` — extends `SsoException`
- [x] 2.3 `src/Service/Sso/Exception/ExpiredTokenException.php` — extends `SsoException`
- [x] 2.4 `src/Service/Sso/Exception/ReplayedNonceException.php` — extends `SsoException`
- [x] 2.5 `src/Service/Sso/SsoTokenVerifier.php` — full implementation matching PS module token format

### Phase 3: Integration
- [x] 3.1 `src/Controller/App/SsoController.php` — full SSO flow with session creation and redirect
- [x] 3.2 `/sso` route verified in `config/routes.yaml`

### Phase 4: Testing
- [x] 4.1 `tests/Unit/Service/Auth/SsoTokenVerifierTest.php` — 6 test cases
- [x] 4.2 `tests/Unit/Controller/App/SsoControllerTest.php` — 7 test cases
- [x] 4.3 `tests/Integration/Migrations/Version00000002CreateSsoNonceTableTest.php` — skips gracefully if no MySQL

### Phase 5: Cleanup
- [x] 5.1 Symfony cache cleared successfully (`php bin/console cache:clear`)
- [ ] 5.2 `doctrine:migrations:migrate` — **blocked**: Doctrine ORM is not installed, so the Symfony console command is not registered. Migration must be run via `vendor/bin/doctrine-migrations` with a configuration file or executed manually.

## Files Changed

| File | Action | Description |
|------|--------|-------------|
| `src/Service/Sso/SsoTokenVerifier.php` | Created | Validates SSO token: HMAC, timestamp, nonce; uses DBAL Connection |
| `src/Service/Sso/Exception/SsoException.php` | Created | Base abstract exception for SSO errors |
| `src/Service/Sso/Exception/InvalidTokenException.php` | Created | Thrown on malformed token or bad HMAC |
| `src/Service/Sso/Exception/ExpiredTokenException.php` | Created | Thrown when token timestamp is >60s old |
| `src/Service/Sso/Exception/ReplayedNonceException.php` | Created | Thrown when nonce was already consumed |
| `migrations/Version00000002CreateSsoNonceTable.php` | Created | Creates `sso_nonce` table (nonce PK, created_at) |
| `src/Controller/App/SsoController.php` | Modified | Full SSO flow: extract token → verify → lookup user → session → redirect |
| `.env` | Modified | Added `SSO_SHARED_SECRET=` |
| `.env.example` | Modified | Added `SSO_SHARED_SECRET=` |
| `config/databases.yaml` | Modified | Added `sso.shared_secret` parameter |
| `config/services.yaml` | Modified | Wired `SsoTokenVerifier` with factory Connection service |
| `tests/Unit/Service/Auth/SsoTokenVerifierTest.php` | Created | Unit tests for token verifier |
| `tests/Unit/Controller/App/SsoControllerTest.php` | Created | Unit tests for SSO controller |
| `tests/Integration/Migrations/Version00000002CreateSsoNonceTableTest.php` | Created | Integration test for migration up/down |

## Deviation from Design

**None significant.** Implementation matches design.md with the following minor alignment:
- HMAC verification confirmed against the real PS module (`modules/tpvconnect/classes/SsoToken.php`) — the user's note about "HMAC over JSON string" was incorrect; the real module computes HMAC over the base64url-encoded payload. Implementation matches the real module.

## Issues Found

1. **Doctrine Migrations CLI not configured**: `bin/console doctrine:migrations:migrate` fails because Doctrine ORM is not installed. The project uses DBAL directly. To run migrations, either:
   - Install `symfony/orm-pack` to register console commands, OR
   - Create a `migrations.php` or `migrations.yaml` config and use `vendor/bin/doctrine-migrations migrate --configuration=migrations.php`, OR
   - Execute migration SQL manually

2. **MySQL PDO driver unavailable in test environment**: The integration test for the migration skips gracefully when the driver is missing. All other tests pass.

## Tool Results

| Tool | Result |
|------|--------|
| `php vendor/bin/php-cs-fixer fix` | ✅ 0 issues |
| `php vendor/bin/phpstan analyse` | ✅ Level 5, no errors |
| `php vendor/bin/phpunit` | ✅ 30 tests, 68 assertions, 1 skipped (no MySQL driver) |

## Remaining Tasks

- [ ] 5.2 Run migration `doctrine:migrations:migrate` — requires Doctrine ORM console integration or manual SQL execution

## Next Steps

1. Decide on migration execution strategy (ORM pack vs custom CLI config vs manual)
2. Run `Version00000002CreateSsoNonceTable` migration against production database
3. Set `SSO_SHARED_SECRET` in production `.env` to match PrestaShop module's `TPVCONNECT_SSO_SECRET`
4. Test end-to-end with PS module's "Abrir TPV" button
