# Exploration: 01-project-bootstrap

## Current State

The repository is **completely greenfield**. It contains only:
- `PRD_TPV_PrestaShop_PHP_v1.3.md` (1126 lines) — the full Product Requirements Document
- `.atl/skill-registry.md` — SDD skill registry (auto-generated during `sdd-init`)
- `.git/` — git repository initialized, remote: `https://github.com/jorgealonsodev/VenPosTPV.git`

No `composer.json`, no source code, no test infrastructure, no build tooling. The PRD defines the entire architecture but nothing is implemented yet.

## Affected Areas

This bootstrap change touches **everything** — it creates the foundation:
- `composer.json` — dependency management (the single most critical file)
- `bin/console` — CLI entry point
- `config/routes.yaml` — routing configuration
- `config/services.yaml` — service container wiring
- `config/databases.yaml` — dual-database connection config
- `public/index.php` — web entry point (DocumentRoot)
- `public/robots.txt` — SEO blocking
- `src/Kernel.php` — custom HTTP kernel
- `src/Controller/` — controller directories (Api/, App/)
- `src/Service/` — service layer directories
- `src/Repository/` — Doctrine DBAL repositories
- `src/Migrations/` — database migrations
- `templates/` — Twig templates
- `assets/` — frontend sources (JS/CSS)
- `tests/` — test infrastructure
- `var/` — cache, log, reports directories
- `.env` — environment secrets
- `.gitignore` — version control exclusions

## Critical Discovery: Symfony Version Compatibility

### The Problem

The PRD (Section 7.2) states: *"Symfony Components (Routing, HttpFoundation, Console, Validator) — los mismos que ya usa PrestaShop 8, sin añadir versiones distintas."*

**Reality check from PrestaShop's actual `composer.json`:**

| PrestaShop Version | Symfony Dependency |
|---|---|
| **PS 8.x** (8.2.x branch) | `symfony/symfony: 4.4.*` (monolithic) |
| **PS 9.x** (develop branch) | Individual `symfony/*: ~6.4.0` components |

PrestaShop 8.x uses the **monolithic `symfony/symfony` 4.4 package**, NOT individual components. PrestaShop 9.x (not yet released) migrates to individual Symfony 6.4 components.

### The Autoload Collision Risk

The TPV bootstrap sequence loads:
1. `require_once '/var/www/prestashop/config/config.inc.php'` → PrestaShop's `vendor/autoload.php` registers Symfony 4.4 classes
2. `require_once __DIR__ . '/../vendor/autoload.php'` → TPV's composer autoloader tries to register its own Symfony classes

If the TPV installs Symfony 6.x components, **class name collisions will occur** because both autoloaders register the same class names (e.g., `Symfony\Component\HttpFoundation\Request`) but with different implementations.

### Version Compatibility Analysis

| Component | PS 8.x (Symfony 4.4) | PS 9.x (Symfony 6.4) | PHP 8.1 Min | PHP 8.3 Min |
|---|---|---|---|---|
| symfony/routing | 4.4.x | 6.4.x | 6.4.x ✅ | 6.4+ or 7.x ✅ |
| symfony/http-foundation | 4.4.x | 6.4.x | 6.4.x ✅ | 6.4+ or 7.x ✅ |
| symfony/console | 4.4.x | 6.4.x | 6.4.x ✅ | 6.4+ or 7.x ✅ |
| symfony/validator | 4.4.x | 6.4.x | 6.4.x ✅ | 6.4+ or 7.x ✅ |
| twig/twig | ^3.0 | ^3.0 | 3.x ✅ | 3.x ✅ |
| doctrine/dbal | via doctrine/orm 2.7 | via doctrine/orm 2.15 | 3.x ✅ | 3.x or 4.x ✅ |
| symfony/messenger | NOT in PS 8.x | ~6.4.0 | 6.4.x ✅ | 6.4+ or 7.x ✅ |
| symfony/yaml | 4.4.x | ~6.4.0 | 6.4.x ✅ | 6.4+ or 7.x ✅ |
| symfony/config | 4.4.x | ~6.4.0 | 6.4.x ✅ | 6.4+ or 7.x ✅ |

**Key insight**: The PRD's architecture assumes the TPV loads PrestaShop core via `require_once`, but the TPV has its **own separate `vendor/` directory** in `/var/www/tpv/vendor/`. The PRD explicitly says (Section 16, Risk #1): *"El TPV mantiene su propio vendor/ aislado en /pos/vendor; solo require el config.inc.php de PrestaShop, sin mezclar autoloads."*

This means the autoload collision is a **KNOWN RISK** that the PRD acknowledges. The mitigation strategy is isolation — but it still requires careful version alignment.

## Approaches

### Approach 1: Match PrestaShop 8.x — Use Symfony 4.4

Pin all Symfony components to `^4.4` to match what PrestaShop 8.x already loads.

- **Pros**: Zero collision risk with PS 8.x (the PRD's primary target). Simplest path.
- **Cons**: Symfony 4.4 reached EOL in November 2023. No security patches. Missing modern features (attributes, improved messenger, etc.). Will need a painful migration when PS 9.x arrives.
- **Effort**: Low (initial), High (future migration)

### Approach 2: Use Symfony 6.4 LTS — Target PrestaShop 9.x readiness

Pin all Symfony components to `^6.4` (LTS, supported until Nov 2027).

- **Pros**: Modern, actively maintained. Aligns with PS 9.x direction. Long support window. Better DX (attributes, improved error handler, etc.).
- **Cons**: Potential class collision with PS 8.x (which loads Symfony 4.4). Requires careful bootstrap ordering and potentially class-alias shims.
- **Effort**: Medium (initial), Low (future migration)

### Approach 3: Use Symfony 6.4 + Isolation Layer (Recommended)

Use Symfony 6.4 components for the TPV, but implement a strict bootstrap isolation:

1. Load PrestaShop core FIRST (its autoloader registers Symfony 4.4)
2. Load TPV composer autoloader SECOND with `prepend: false` (default behavior — TPV classes only fill gaps, don't override)
3. For the TPV's own code, always reference the 6.x classes explicitly via fully-qualified names
4. Use a thin adapter layer where the TPV interacts with PrestaShop core (the adapter uses PS's 4.4 classes, the rest of the TPV uses 6.x)

- **Pros**: Best of both worlds — modern Symfony for TPV development, compatibility with PS 8.x, ready for PS 9.x.
- **Cons**: Requires discipline in the adapter layer. Need to test thoroughly with PS 8.x.
- **Effort**: Medium

### Approach 4: No Symfony Components — Build Minimal Custom Router

Skip Symfony Components entirely. Build a minimal custom router and request/response handler.

- **Pros**: Zero dependency collision. Minimal footprint.
- **Cons**: Reinventing the wheel. No validator, no console, no messenger. Massive effort for features Symfony provides out of the box. Violates the PRD's architecture.
- **Effort**: Very High

## Recommendation

**Approach 3: Symfony 6.4 + Isolation Layer**

The PRD already anticipates this risk and prescribes isolation. Symfony 6.4 is the right choice because:
1. It's the current LTS (supported until Nov 2027)
2. PS 9.x already uses 6.4 — we're building for the future
3. The adapter layer is thin and well-defined by the PRD architecture
4. The collision risk is manageable: PrestaShop's autoloader loads first, TPV's autoloader fills gaps. As long as the TPV doesn't try to use Symfony classes that PrestaShop already loaded (which it shouldn't — the TPV uses its own router, its own console, etc.), there's no conflict.

**Recommended dependency versions:**

```json
{
    "require": {
        "php": ">=8.1",
        "symfony/routing": "^6.4",
        "symfony/http-foundation": "^6.4",
        "symfony/http-kernel": "^6.4",
        "symfony/console": "^6.4",
        "symfony/validator": "^6.4",
        "symfony/yaml": "^6.4",
        "symfony/config": "^6.4",
        "symfony/messenger": "^6.4",
        "symfony/doctrine-messenger": "^6.4",
        "twig/twig": "^3.8",
        "doctrine/dbal": "^3.8",
        "mike42/escpos-php": "^4.0",
        "firebase/php-jwt": "^6.10",
        "ramsey/uuid": "^4.7",
        "dompdf/dompdf": "^2.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "phpstan/phpstan": "^1.10",
        "friendsofphp/php-cs-fixer": "^3.49"
    }
}
```

## Database Migration Plan

The `tpv` schema tables should be created in dependency order (foreign keys first):

| Order | Table | Dependencies | Rationale |
|---|---|---|---|
| 1 | `role` | None | No dependencies. Must exist before users. |
| 2 | `terminal` | None | No dependencies. Must exist before sessions. |
| 3 | `user` | `role` | FK to role(id). |
| 4 | `session` | `terminal`, `user` | FK to both terminal(id) and user(id). |
| 5 | `movement` | `session` | FK to session(id). References ps_order_id logically (no FK). |
| 6 | `order_meta` | None (logical refs) | PK is ps_order_id (logical ref). FK-free by design. |
| 7 | `offline_queue` | `terminal` (logical) | References terminal_id logically. No FK per PRD. |
| 8 | `audit_log` | `user`, `terminal` (logical) | References both logically. No FK per PRD. |
| 9 | `z_report` | `session` | FK to session(id). Unique constraint on session_id. |
| 10 | `messenger_messages` | None | Symfony Messenger doctrine transport table. Independent. |

**Migration strategy**: Use Doctrine Migrations. Each table gets its own migration file. This allows incremental deployment and rollback.

## Bootstrap Sequence

The order in which things load at runtime:

```
1. Web request hits /var/www/tpv/public/index.php
2. require_once '/var/www/prestashop/config/config.inc.php'
   └── PrestaShop core bootstraps
   └── PrestaShop's vendor/autoload.php registers (Symfony 4.4 classes)
   └── PrestaShop connects to its DB (prestashop schema)
3. require_once __DIR__ . '/../vendor/autoload.php'
   └── TPV's composer autoloader registers (Symfony 6.4 classes)
   └── Only fills gaps — doesn't override already-loaded classes
4. new \Tpv\Kernel()
   └── Loads config/routes.yaml
   └── Loads config/services.yaml
   └── Loads config/databases.yaml
   └── Creates Doctrine DBAL Connection for 'tpv' schema
   └── Configures Twig loader (templates/)
   └── Sets up Router (Symfony Routing)
5. $kernel->handle($request)
   └── Route matching
   └── Controller dispatch
   └── Response generation
6. $response->send()
```

## Risks

| Risk | Severity | Mitigation |
|---|---|---|
| **Symfony 4.4/6.4 autoload collision** | HIGH | Adapter layer; load PS first, TPV second; test with PS 8.x |
| **PrestaShop core bootstrap fails** (wrong path, missing permissions) | HIGH | Bootstrap validation script; clear error messages; installer checks |
| **Doctrine DBAL version mismatch** with PS's Doctrine ORM 2.7/2.15 | MEDIUM | DBAL 3.x is compatible with ORM 2.x. Different packages, no collision. |
| **Twig version conflict** (PS uses ^3.0, TPV uses ^3.8) | LOW | Same major version. Backwards compatible. |
| **PHP 8.1 minimum vs system PHP 8.3.6** | LOW | Target 8.1+, test on 8.3. Use `platform.php` in composer.json if needed. |
| **No PrestaShop installation available for testing** | MEDIUM | Use Docker to spin up PS 8.x for integration testing. |
| **Frontend build pipeline complexity** | MEDIUM | Start with CDN-hosted Vue 3/Alpine + Tailwind CDN. Add Vite later. |

## Decisions Needed

1. **PrestaShop target version**: Should we target PS 8.x only (Symfony 4.4) or design for PS 9.x readiness (Symfony 6.4)? → **Recommendation: Symfony 6.4 with isolation layer**
2. **Frontend framework**: Vue 3 or Alpine.js for the ticket screen? → **Recommendation: Alpine.js for MVP (simpler, no build step), Vue 3 if SPA complexity grows**
3. **Testing framework**: PHPUnit or Pest? → **Recommendation: PHPUnit (industry standard, PRD mentions it)**
4. **Static analysis**: PHPStan level? → **Recommendation: Level 5 initially, ramp to 8**
5. **PrestaShop module scope**: Scaffold `tpvconnect` module now or in a separate change? → **Recommendation: SEPARATE CHANGE. The module is a distinct component with its own lifecycle. Bootstrap the TPV app first, then the module in `02-prestashop-module`.**

## Recommended Scope for This Change

### IN SCOPE (this change: `01-project-bootstrap`)
- [ ] `composer.json` with all backend dependencies
- [ ] Complete directory structure (bin/, config/, public/, src/, templates/, assets/, tests/, var/)
- [ ] `public/index.php` — web entry point with PrestaShop bootstrap + TPV kernel
- [ ] `src/Kernel.php` — minimal HTTP kernel (routing, response handling)
- [ ] `bin/console` — CLI entry point
- [ ] `config/routes.yaml` — initial route definitions (health check, placeholder routes)
- [ ] `config/services.yaml` — service container wiring
- [ ] `config/databases.yaml` — dual-database configuration template
- [ ] `.env` — environment template
- `.gitignore` — PHP, Composer, Node, IDE exclusions
- [ ] `var/` directories with `.gitkeep` files
- [ ] `public/robots.txt` — Disallow all
- [ ] PHPUnit configuration (`phpunit.xml.dist`)
- [ ] PHPStan configuration (`phpstan.neon`)
- [ ] PHP-CS-Fixer configuration (`.php-cs-fixer.php`)
- [ ] First Doctrine DBAL migration (empty or with `role` table)
- [ ] Basic health check endpoint (`GET /api/health`)
- [ ] Basic Kernel test (can boot, can handle request)

### OUT OF SCOPE (future changes)
- PrestaShop `tpvconnect` module → `02-prestashop-module`
- Database tables beyond the first migration → `03-database-schema`
- Controllers beyond health check → `04-authentication` or `05-pos-screen`
- Frontend build pipeline (Vite, Tailwind compilation) → `06-frontend-pipeline`
- Service layer, repositories → subsequent feature changes
- Twig templates → subsequent feature changes
- Symfony Messenger setup → `07-messenger-worker`

## Risks Summary

1. **Symfony version collision is the #1 risk**. The bootstrap MUST be tested against a real PrestaShop 8.x installation to verify no class conflicts.
2. **The PRD has a path inconsistency**: it uses both `/pos/` and `/var/www/tpv/` as the TPV directory. The PRD title says "subdominio dedicado" and Annex 17.1 uses `/var/www/tpv/`, but some sections reference `/pos/`. **Decision: use `/var/www/tpv/` as the canonical path** (matches Annex 17.1).
3. **No database available for local development**. The bootstrap should work without a real DB connection (use environment variables, skip migrations in CI).
4. **PrestaShop core cannot be loaded without a real PrestaShop installation**. The Kernel must gracefully handle the case where PrestaShop is not available (for unit testing).

## Ready for Proposal

**Yes.** This exploration provides sufficient analysis for the orchestrator to proceed to `sdd-propose`. The key decisions are:
- Use Symfony 6.4 LTS with an isolation adapter layer
- Target PrestaShop 8.x compatibility with forward-compatibility for 9.x
- Defer the PrestaShop module to a separate change
- Use PHPUnit for testing, PHPStan for static analysis
- Bootstrap scope limited to infrastructure (composer, directories, kernel, routing, config, first migration)
