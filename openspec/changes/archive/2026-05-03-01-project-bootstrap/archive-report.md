# Archive Report: 01-project-bootstrap

**Archived**: 2026-05-03
**Verdict**: PASS WITH WARNINGS (0 CRITICAL)

---

## Executive Summary

The `01-project-bootstrap` change created the foundational infrastructure for the **VenPosTPV** application — a standalone Symfony 6.4 TPV (Point of Sale) system. All 37 tasks across 6 phases were implemented. The kernel boots successfully (~3ms), console commands work (including Doctrine migrations), the health endpoint returns correct JSON structure, and all three quality tools (PHPUnit, PHPStan at level 5, PHP-CS-Fixer) pass with zero errors.

### What Was Built

1. **Symfony 6.4 Application Foundation**: Standalone kernel (`VenPosTPV\Kernel`) using `MicroKernelTrait`, with YAML-based routing, services, and package configuration. No PrestaShop filesystem dependency — the TPV is independent and syncs via API (future change).

2. **Composer Dependency Tree**: 16 runtime deps (Symfony components ^6.4, Doctrine DBAL ^3.8, Twig ^3.8, JWT, UUID, ESC/POS, DomPDF) and 3 dev deps (PHPUnit ^10.5, PHPStan ^1.10, PHP-CS-Fixer ^3.49). PSR-4 autoloading: `VenPosTPV\` → `src/`, `VenPosTPV\Tests\` → `tests/`.

3. **HTTP Kernel + CLI Console**: `public/index.php` (web entry with Dotenv), `bin/console` (executable Symfony Console entry point with Doctrine migration commands registered).

4. **Health Endpoint**: `GET /api/health` returns `{status, timestamp (ISO 8601), checks{database}}` with HTTP 200 (healthy) / HTTP 503 (unhealthy).

5. **Doctrine Migration**: Single migration (`Version00000001CreateRoleTable`) creating all 10 `tpv` tables in dependency order: `role`, `terminal`, `user`, `session`, `movement`, `order_meta`, `offline_queue`, `audit_log`, `z_report`, `messenger_messages`. All `ps_*_id` columns as plain `INT` (logical references, no FK to remote schema). Idempotent through `CREATE TABLE IF NOT EXISTS`.

6. **Testing Infrastructure**: PHPUnit 10.5 config with code coverage, PHPStan at level 5, PHP-CS-Fixer with `@PSR12` and `declare_strict_types`.

7. **Service Layer**: `ConnectionManagerInterface` / `ConnectionManager` (lazy Doctrine DBAL connection), `HealthController`, `SsoController` placeholder.

## Key Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Symfony version | **6.4 LTS** | Active support until Nov 2027. TPV is independent (no autoload collision) |
| PrestaShop coupling | **Decoupled (API only)** | TPV runs on its own domain/server. No `require_once` of PS core |
| ORM strategy | **Doctrine DBAL only** | No ORM entity overhead. No PS entity conflicts |
| Database scope | **Single `tpv` schema** | PS data accessed via Web Service API (future), not direct SQL |
| Namespace | **`VenPosTPV\`** (PSR-4) | Matches project name |
| Frontend | **None in this change** | Deferred to `06-frontend-pipeline` |
| Connection manager | **Lazy connection** | Connection created on first `getTpvConnection()` call |

## Design Deviations

| Deviation | Severity | Rationale |
|-----------|----------|-----------|
| Added `symfony/framework-bundle` + `symfony/dotenv` | ✅ Justified | `MicroKernelTrait` requires FrameworkBundle; Dotenv required for `.env` loading |
| `config/packages/_default.yaml` lacks `doctrine:` and `twig:` sections | ✅ Justified | No DoctrineBundle/TwigBundle → those extensions crash the kernel |
| `databases.yaml` not loaded by kernel's container loader | 🟡 WARNING | File exists as config documentation; actual DB config injected via `services.yaml`. Spec R3 says kernel should load it, but without DoctrineBundle there's no extension for the `database:` key |
| `ConnectionManager` uses `DriverManager::getConnection()` | ✅ Justified | `ConnectionFactory` is internal/ORM class in DBAL 3.x |
| `phpunit.xml.dist` vs `phpunit.xml` | ✅ Justified | `.dist` pattern is Symfony/industry standard |

## Warnings from Verification (3 Total)

1. **`databases.yaml` not loaded by kernel (R3)**: 🟡 **Unresolved**. The file exists as configuration documentation but is not loaded by the kernel's container. No DoctrineBundle is installed, so there's no extension to process the `database:` top-level key. DB config is correctly injected via `services.yaml` → `ConnectionManager`. Acceptable for bootstrap — consider a future change to either remove `databases.yaml` or implement a custom config loader.

2. **`doctrine/migrations` version constraint (`^3.7` vs spec `^3.6`)**: 🟡 **Unresolved** (minor). `composer.json` resolved `^3.6` → `^3.7` (minimum stability). `3.7` is within the `^3.6` range. Does not affect functionality. No action needed.

3. **No actual tests exist (0 tests)**: 🟡 **Unresolved** (acceptable). Spec R10 explicitly states "zero tests is acceptable for phpunit." The bootstrap phase is infrastructure-only. Tests will be added in subsequent feature changes as controllers and services are implemented.

## Files Created (24+ Project Files)

### Source Code (PHP)
| File | Description |
|------|-------------|
| `src/Kernel.php` | Custom HTTP kernel extending Symfony `HttpKernel`, using `MicroKernelTrait` |
| `src/Controller/Api/HealthController.php` | `GET /api/health` → JSON status endpoint |
| `src/Controller/App/SsoController.php` | Placeholder SSO controller for future integration |
| `src/Service/Db/ConnectionManagerInterface.php` | Interface for DB connection access |
| `src/Service/Db/ConnectionManager.php` | Lazy Doctrine DBAL connection manager |
| `migrations/Version00000001CreateRoleTable.php` | First migration — all 10 tables in dependency order |

### Config & Environment
| File | Description |
|------|-------------|
| `composer.json` | Symfony 6.4 + all dependencies |
| `.env` | Development defaults with `%env()%` pattern |
| `.env.example` | Template without secrets |
| `.gitignore` | Excludes vendor/, var/, .env, IDE files |
| `config/routes.yaml` | Route groups: `/api/*`, `/app/*`, `/sso` |
| `config/services.yaml` | Autowiring + service bindings |
| `config/databases.yaml` | Doctrine DBAL connection config (reference) |
| `config/packages/_default.yaml` | Framework baseline config |

### Entry Points
| File | Description |
|------|-------------|
| `public/index.php` | Web entry point (DocumentRoot) |
| `public/robots.txt` | Disallow all crawlers |
| `bin/console` | Executable CLI entry point (chmod +x) |

### Tooling Config
| File | Description |
|------|-------------|
| `phpunit.xml.dist` | PHPUnit 10.5 with code coverage |
| `phpstan.neon` | PHPStan level 5 analyzing `src/` |
| `.php-cs-fixer.php` | PSR-12 + `declare_strict_types` |

### Directory Placeholders (`.gitkeep`)
| File | Description |
|------|-------------|
| `var/cache/.gitkeep` | Cache directory |
| `var/log/.gitkeep` | Log directory |
| `var/reports/.gitkeep` | Z-report PDF storage |
| `bin/.gitkeep` | Bin directory |
| `config/packages/.gitkeep` | Packages directory |
| `public/assets/.gitkeep` | Compiled frontend assets |
| `templates/.gitkeep` | Twig templates |
| `assets/.gitkeep` | Frontend sources |
| `tests/Unit/.gitkeep` | Unit tests |
| `tests/Integration/.gitkeep` | Integration tests |
| `tests/Functional/.gitkeep` | Functional tests |
| `src/Controller/Api/.gitkeep` | API controllers |
| `src/Controller/App/.gitkeep` | App controllers |
| `src/Service/Db/.gitkeep` | DB services |
| `src/Repository/.gitkeep` | Repositories |
| `src/Migrations/.gitkeep` | Migrations |
| `migrations/.gitkeep` | Migration scripts |

## Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| project-bootstrap | Created (first change) | Delta spec copied to main specs. 10 requirements (R1-R10), 5 non-functional requirements (NFR-1 to NFR-5), 16 scenarios |

## Engram Artifact References

| Artifact | Observation ID |
|----------|---------------|
| sdd/01-project-bootstrap/explore | #2513 |
| sdd/01-project-bootstrap/proposal | #2515 |
| sdd/01-project-bootstrap/spec | #2519 |
| sdd/01-project-bootstrap/design | #2517 |
| sdd/01-project-bootstrap/tasks | #2521 |
| sdd/01-project-bootstrap/apply-progress | (openspec only) |
| sdd/01-project-bootstrap/verify-report | #2523 |
| sdd/01-project-bootstrap/archive-report | #THIS |

## SDD Cycle Complete

The `01-project-bootstrap` change has completed the full SDD cycle:
- ✅ Explore — Symfony version compatibility analysis, bootstrap sequence design
- ✅ Proposal — Scope, intent, capabilities, success criteria
- ✅ Spec — 10 requirements, 16 scenarios, 5 NFRs
- ✅ Design — Architecture decisions, data flow, file changes, interfaces
- ✅ Tasks — 37 tasks across 6 phases
- ✅ Apply — All 37 tasks implemented
- ✅ Verify — PASS WITH WARNINGS (0 CRITICAL, 3 warnings)
- ✅ Archive — Main spec synced, change moved to archive

Ready for next change: `02-prestashop-module` (PrestaShop `tpvconnect` module for Web Service API).
