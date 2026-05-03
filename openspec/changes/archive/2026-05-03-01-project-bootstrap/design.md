# Design: Bootstrap TPV Application Foundation

## Technical Approach

Create a **standalone Symfony 6.4 LTS** application — the TPV is an independent domain, NOT a PrestaShop subdomain or filesystem extension. No `require_once` of PrestaShop core. Stock/catalog sync happens via PrestaShop Web Service API (future change). This eliminates the autoload collision risk entirely and simplifies deployment to any server.

The bootstrap provides: composer dependencies, directory structure, HTTP kernel, CLI console, Doctrine DBAL for `tpv` schema, routing, health endpoint, and testing infrastructure.

## Architecture Decisions

| Decision | Choice | Rejected | Rationale |
|----------|--------|----------|-----------|
| Symfony version | **6.4 LTS** | 4.4 (EOL), 7.x (no LTS) | LTS until Nov 2027. No PrestaShop collision since TPV is independent |
| PrestaShop coupling | **Decoupled (API only)** | Filesystem bootstrap, shared autoload | TPV runs on separate domain/server. Sync via API, not `require_once` |
| ORM strategy | **Doctrine DBAL only** | Doctrine ORM, raw PDO | DBAL gives builder/query flexibility without ORM entity overhead. No PS entity conflicts |
| Database scope | **Single `tpv` schema** | Dual-schema direct access | Only `tpv` DB. PrestaShop data accessed via API client (future), not direct SQL |
| Frontend (initial) | **None in this change** | Vue, Alpine, Vite | Deferred to `06-frontend-pipeline`. Bootstrap is backend only |
| Namespace | **`VenPosTPV\`** (PSR-4) | `Tpv\` (PRD uses both) | Matches `composer.json` project name. More descriptive |

## Data Flow

```
HTTP Request
    │
    ▼
public/index.php
    │
    ├─ Load .env (Symfony Dotenv)
    ├─ require vendor/autoload.php  ← NO PrestaShop bootstrap
    │
    ▼
Kernel::handle(Request)
    │
    ├─ Route matching (routes.yaml)
    │   ├─ /api/*  → Api Controllers (JSON)
    │   ├─ /app/*  → App Controllers (Twig)
    │   └─ /sso    → SsoController (future)
    │
    ├─ Container (services.yaml)
    │   ├─ ConnectionManager → Doctrine\DBAL\Connection (tpv schema)
    │   └─ Repositories, Services…
    │
    ▼
Response → send()

CLI (bin/console):
    │
    ├─ doctrine:migrations:migrate
    ├─ messenger:consume (future)
    └─ tpv:* custom commands
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `composer.json` | Create | Symfony 6.4 components, Doctrine DBAL 3.8, Twig 3.8, PHPUnit, PHPStan, dev deps |
| `.env` | Create | APP_ENV, APP_SECRET, TPV_DB_* credentials |
| `.env.example` | Create | Template without secrets |
| `.gitignore` | Create | vendor/, var/, .env, IDE files |
| `phpunit.xml` | Create | PHPUnit 10.5 config with test suites |
| `phpstan.neon` | Create | PHPStan level 5, paths, ignore patterns |
| `.php-cs-fixer.php` | Create | PSR-12 + Symfony rules |
| `bin/console` | Create | Symfony Console entry point |
| `public/index.php` | Create | Web entry point — loads autoload, boots kernel |
| `public/robots.txt` | Create | `User-agent: * / Disallow: /` |
| `public/assets/.gitkeep` | Create | Placeholder for compiled frontend assets |
| `config/routes.yaml` | Create | Route groups: /api/*, /app/*, /sso |
| `config/services.yaml` | Create | Service container wiring, autowiring config |
| `config/databases.yaml` | Create | Single `tpv` connection config |
| `config/packages/_default.yaml` | Create | Framework, doctrine, twig baseline config |
| `src/Kernel.php` | Create | Minimal HTTP kernel extending Symfony HttpKernel |
| `src/Controller/Api/HealthController.php` | Create | `GET /api/health` → JSON status |
| `src/Service/Db/ConnectionManager.php` | Create | Wraps Doctrine DBAL, provides `tpv` connection |
| `src/Service/Db/ConnectionManagerInterface.php` | Create | Interface for DB connection access |
| `migrations/Version00000001CreateRoleTable.php` | Create | First Doctrine migration: `role` table |
| `templates/.gitkeep` | Create | Placeholder for Twig templates |
| `assets/.gitkeep` | Create | Placeholder for frontend sources |
| `tests/Unit/.gitkeep` | Create | Unit test directory |
| `tests/Integration/.gitkeep` | Create | Integration test directory |
| `tests/Functional/.gitkeep` | Create | Functional test directory |
| `var/cache/.gitkeep` | Create | Cache directory |
| `var/log/.gitkeep` | Create | Log directory |
| `var/reports/.gitkeep` | Create | Z-report PDF storage |

## Interfaces / Contracts

### Kernel (src/Kernel.php)
```php
namespace VenPosTPV;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Kernel extends BaseKernel
{
    public function getProjectDir(): string;
    // Standard Symfony kernel — no PrestaShop loading
}
```

### ConnectionManagerInterface (src/Service/Db/ConnectionManagerInterface.php)
```php
namespace VenPosTPV\Service\Db;
use Doctrine\DBAL\Connection;

interface ConnectionManagerInterface
{
    public function getTpvConnection(): Connection;
    // Future: getPrestaShopApiClient(): PrestaShopApiClient
}
```

### ConnectionManager (src/Service/Db/ConnectionManager.php)
```php
namespace VenPosTPV\Service\Db;
use Doctrine\DBAL\ConnectionFactory;

class ConnectionManager implements ConnectionManagerInterface
{
    private ?Connection $tpvConn = null;
    public function __construct(private array $config) {}
    public function getTpvConnection(): Connection;
}
```

### HealthController (src/Controller/Api/HealthController.php)
```php
namespace VenPosTPV\Controller\Api;
// GET /api/health → { status: "ok", ts: ISO8601, db: "ok"|"error" }
```

### .env structure
```
APP_ENV=dev
APP_SECRET=
TPV_DB_HOST=127.0.0.1
TPV_DB_PORT=3306
TPV_DB_NAME=tpv
TPV_DB_USER=tpv_user
TPV_DB_PASSWORD=
# Future: PRESTASHOP_API_URL=
# Future: PRESTASHOP_API_KEY=
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | Kernel boot, ConnectionManager, HealthController | PHPUnit, mocked DB. No PS/DB dependency |
| Integration | DBAL queries against `tpv` schema | MySQL/MariaDB test DB. CI: Docker service |
| Functional | `GET /api/health` full request | Symfony HttpKernel client, in-memory SQLite for speed |

## Migration / Rollout

1. `composer install` completes without conflicts
2. `.env` configured with DB credentials
3. `php bin/console doctrine:migrations:migrate` creates `role` table
4. `php -S localhost:8000 -t public/` serves health check
5. Rollback: delete all created files — repo returns to greenfield

## Open Questions

- [ ] Should `ConnectionManager` eagerly connect on construct or lazy-connect on first query? → Recommend lazy
- [ ] PrestaShop API client scope: should `01-project-bootstrap` scaffold the interface, or defer entirely? → Recommend: scaffold interface only, implement in `02-prestashop-module`
- [ ] PHP minimum: 8.1 vs 8.2? → Recommend 8.1 for hosting flexibility, 8.2 features not required