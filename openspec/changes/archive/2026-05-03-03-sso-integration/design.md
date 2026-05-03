# Design: SSO Token Reception and Authentication

## Technical Approach

Lightweight PHP native session auth with zero new dependencies. The TPV mirrors the PS module's exact token format — `base64url(json({employee_id, shop_id, timestamp, nonce}))~hmac_sha256_hex` — verified via HMAC-SHA256 with constant-time comparison, then checked for expiry (≤60s) and nonce replay (single-use via `sso_nonce` DB table). On success, a pre-configured user is looked up by `ps_employee_id`, a PHP session is created, and the user is redirected to `/app/dashboard`. Errors return JSON (no Twig templates exist for SSO error pages yet).

References: Proposal `sdd/03-sso-integration/proposal`, Exploration `sdd/03-sso-integration/explore`.

## Architecture Decisions

| Decision | Choice | Rejected Alternatives | Rationale |
|----------|--------|----------------------|-----------|
| Namespace for SSO service | `VenPosTPV\Service\Sso` | `VenPosTPV\Service\Auth` | Follows existing `Service\Db` pattern; Auth namespace premature for MVP with only SSO |
| Nonce storage | `sso_nonce` DB table (InnoDB, nonce PK) | Symfony Cache, Redis, PS Configuration-like | Matches DBAL-only architecture; durable; no new deps; PK enforces uniqueness atomically |
| Session mechanism | PHP native `$_SESSION` + `session_regenerate_id(true)` | Symfony Security bundle, JWT cookies | Zero new deps; framework config already has `handler_id: null` (native sessions); MVP-appropriate |
| Error response format | JSON with HTTP status codes | Twig templates | No SSO error templates exist yet; JSON is testable; templates can be added in a future change |
| User provisioning | Pre-configured only (query by `ps_employee_id`) | Auto-provision via PS API, embed data in token | Employee name not in token payload; no PS API client yet; manual user setup is explicit and safe for MVP |
| Shared secret env var | `SSO_SHARED_SECRET` (new, dedicated) | Reuse `JWT_SECRET` | Clear purpose; JWT_SECRET is empty and conceptually different; avoids confusion |
| Redirect target | `/app/dashboard` | `/app/`, `/` | Dashboard is the functional landing page after login; if it doesn't exist yet, redirect to `/app/` as fallback |

## Data Flow

```
PS Module                         TPV
──────────                        ──────────
SsoToken::generate()
  │
  ▼
GET /sso?token={token}  ──────►  SsoController::index(Request)
                                    │
                                    ▼
                                  SsoTokenVerifier::verify($token)
                                    ├─ Split on '~' → [$payload, $sig]
                                    ├─ hash_hmac('sha256', $payload, $secret)
                                    ├─ hash_equals($expected, $sig)
                                    ├─ base64url_decode($payload) → JSON
                                    ├─ time() - payload.timestamp ≤ 60
                                    ├─ INSERT sso_nonce (nonce) → dup key = replay
                                    └─ DELETE sso_nonce WHERE created_at < NOW()-120s
                                    │
                                    ▼ (payload array)
                                  DBAL: SELECT * FROM user WHERE ps_employee_id=? AND active=1
                                    │
                                    ├─ Found → session_regenerate_id(true)
                                    │         $_SESSION = [user_id, ps_employee_id, shop_id, authenticated_at]
                                    │         UPDATE user SET last_login_at=NOW()
                                    │         Redirect 302 → /app/dashboard
                                    │
                                    └─ Not found → 403 JSON {"error": "User not configured"}
                                    
  Error paths (all return JSON):
    Invalid format  → 400 {"error": "Invalid token format"}
    Bad HMAC        → 401 {"error": "Invalid token signature"}
    Expired         → 401 {"error": "Token expired"}
    Nonce replay    → 401 {"error": "Token already used"}
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Service/Sso/SsoTokenVerifier.php` | Create | Validates SSO token: HMAC, timestamp, nonce; uses DBAL Connection for nonce storage |
| `src/Service/Sso/Exception/InvalidTokenException.php` | Create | Thrown on malformed token or bad HMAC |
| `src/Service/Sso/Exception/ExpiredTokenException.php` | Create | Thrown when token timestamp is >60s old |
| `src/Service/Sso/Exception/ReplayedNonceException.php` | Create | Thrown when nonce was already consumed |
| `migrations/Version00000002CreateSsoNonceTable.php` | Create | Creates `sso_nonce` table (nonce VARCHAR(64) PK, created_at DATETIME) |
| `src/Controller/App/SsoController.php` | Modify | Full SSO flow: extract token → verify → lookup user → create session → redirect |
| `.env` | Modify | Add `SSO_SHARED_SECRET=` |
| `.env.example` | Modify | Add `SSO_SHARED_SECRET=` |
| `config/databases.yaml` | Modify | Add `sso.shared_secret: '%env(SSO_SHARED_SECRET)%'` parameter |
| `config/services.yaml` | Modify | Wire SsoTokenVerifier with `$sharedSecret` and `$connection` arguments |

## Interfaces / Contracts

### SsoTokenVerifier

```php
namespace VenPosTPV\Service\Sso;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Sso\Exception\InvalidTokenException;
use VenPosTPV\Service\Sso\Exception\ExpiredTokenException;
use VenPosTPV\Service\Sso\Exception\ReplayedNonceException;

class SsoTokenVerifier
{
    private const TOKEN_TTL = 60;
    private const NONCE_TTL_SECONDS = 120;

    public function __construct(
        private readonly string $sharedSecret,
        private readonly Connection $connection,
    ) {}

    /**
     * @return array{employee_id: int, shop_id: int, timestamp: int, nonce: string}
     * @throws InvalidTokenException  malformed token or HMAC mismatch
     * @throws ExpiredTokenException  timestamp too old
     * @throws ReplayedNonceException  nonce already consumed
     */
    public function verify(string $token): array;

    private function base64UrlDecode(string $data): string;
    private function cleanupNonces(): void;
}
```

### Exception Hierarchy

```php
namespace VenPosTPV\Service\Sso\Exception;

abstract class SsoException extends \RuntimeException {}
class InvalidTokenException extends SsoException {}   // 400 realm
class ExpiredTokenException extends SsoException {}    // 401 realm
class ReplayedNonceException extends SsoException {}   // 401 realm
```

### SsoController

```php
namespace VenPosTPV\Controller\App;

class SsoController
{
    public function __construct(
        private readonly SsoTokenVerifier $tokenVerifier,
        private readonly ConnectionManagerInterface $connectionManager,
    ) {}

    public function index(Request $request): Response;
    // Returns: RedirectResponse on success, JsonResponse on error
}
```

### sso_nonce Table DDL

```sql
CREATE TABLE sso_nonce (
    nonce VARCHAR(64) PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `SsoTokenVerifier::verify` — valid token, bad HMAC, expired timestamp, replayed nonce, malformed input | Mock `Connection`; test each exception path; mirror PS `SsoTokenTest` patterns |
| Unit | `SsoTokenVerifier::base64UrlDecode` — roundtrip with PS module's `base64UrlEncode` | Generate tokens with PS logic, decode in TPV verifier |
| Unit | `SsoController::index` — missing token, invalid token, expired, valid login, user-not-found | Mock `SsoTokenVerifier` and `ConnectionManagerInterface`; assert JSON error shapes and redirect targets |
| Integration | Migration `Version00000002` — up/down creates/drops `sso_nonce` | Run migration against test MySQL; assert table exists |
| E2E | Full SSO flow — generate token with PS `SsoToken`, hit `/sso?token=X`, assert session and redirect | Defer unless integration test infra exists |

## Migration / Rollout

1. Deploy migration `Version00000002CreateSsoNonceTable` (`doctrine:migrations:migrate`)
2. Set `SSO_SHARED_SECRET` in `.env` (must match `TPVCONNECT_SSO_SECRET` in PS module config)
3. Clear Symfony cache (`cache:clear`)
4. Test with PS module's "Abrir TPV" button
5. **Rollback**: Revert SsoController to placeholder, drop `sso_nonce`, remove env var, clear sessions

## Open Questions

- [ ] Should `/app/dashboard` route exist before this change, or should SsoController redirect to `/app/` with a fallback check?
- [ ] Rate limiting on `/sso` endpoint — defer to a future change or include basic IP throttling now?