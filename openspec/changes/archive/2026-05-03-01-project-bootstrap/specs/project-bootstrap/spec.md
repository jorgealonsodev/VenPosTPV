# Project Bootstrap Specification

## Purpose

Foundation for `venpostpv`: a standalone PHP POS application built with Symfony 6.4 components, its own `tpv` database schema, kernel, routing, and tooling. The TPV is independent from PrestaShop — no filesystem dependency, no autoload collision, syncs via API (future change).

## Requirements

### R1: Directory Structure

The project MUST follow PRD Annex 17.1 structure with `public/` as sole DocumentRoot. All source code MUST reside outside the web root. Required directories: `bin/`, `config/` (with `packages/`), `public/`, `src/` (with `Controller/Api/`, `Controller/App/`, `Service/`, `Repository/`, `Migrations/`), `templates/`, `assets/`, `tests/`, `var/` (with `cache/`, `log/`, `reports/`). `var/` subdirectories MUST contain `.gitkeep` files.

#### Scenario: Fresh install creates all directories

- GIVEN a greenfield repository with no source code
- WHEN `composer install` completes
- THEN all required directories MUST exist with correct structure

### R2: Composer Dependencies

`composer.json` MUST declare runtime deps: PHP ≥8.1, Symfony components ^6.4 (routing, http-foundation, http-kernel, console, validator, yaml, config, messenger, doctrine-messenger), twig ^3.8, doctrine/dbal ^3.8, doctrine/migrations ^3.6, mike42/escpos-php ^4.0, firebase/php-jwt ^6.10, ramsey/uuid ^4.7, dompdf/dompdf ^2.0. Dev deps: phpunit ^10.5, phpstan ^1.10, php-cs-fixer ^3.49. PSR-4 autoloading: `VenPosTPV\` → `src/`, `VenPosTPV\Tests\` → `tests/`. No PrestaShop packages or filesystem dependencies MUST appear in the dependency tree.

#### Scenario: Clean install

- GIVEN PHP 8.1+ and Composer available
- WHEN `composer install` runs
- THEN all dependencies resolve without conflicts and `vendor/autoload.php` registers `VenPosTPV\` namespace

### R3: Kernel Bootstrap

`src/Kernel.php` MUST extend `Symfony\Component\HttpKernel\Kernel`, loading `routes.yaml`, `services.yaml`, and `databases.yaml` from config. It MUST boot WITHOUT any PrestaShop filesystem dependency — no `require_once` of PrestaShop paths.

#### Scenario: Standalone boot

- GIVEN installed dependencies
- WHEN `new \VenPosTPV\Kernel('prod', false)` is instantiated
- THEN kernel MUST boot and container MUST contain configured routes, services, and database connection

#### Scenario: Graceful degradation without database

- GIVEN unreachable or nonexistent `tpv` database
- WHEN kernel boots
- THEN kernel MUST still boot successfully; health endpoint SHOULD report database as unhealthy

### R4: Routing Configuration

`config/routes.yaml` MUST define routes with prefixes: `/api/*` for JSON endpoints, `/app/*` for Twig-rendered views. Health check route MUST be defined.

#### Scenario: Health route resolves

- GIVEN a booted application
- WHEN `GET /api/health` is dispatched through the router
- THEN router MUST resolve to the health check controller

#### Scenario: Undefined route returns 404

- GIVEN a booted application
- WHEN a request to an undefined route is dispatched
- THEN kernel MUST return a 404 response

### R5: Database Configuration

`config/databases.yaml` MUST configure a Doctrine DBAL connection for the `tpv` schema using environment variables for credentials (`TPV_DB_HOST`, `TPV_DB_PORT`, `TPV_DB_NAME`, `TPV_DB_USER`, `TPV_DB_PASSWORD`). A PrestaShop API section MAY exist as placeholder for future integration but MUST NOT create an active DB connection. All `ps_*_id` columns in `tpv` tables MUST be plain `INT` — logical references, no foreign keys to any remote schema.

#### Scenario: TPV connection succeeds

- GIVEN valid `TPV_DB_*` environment variables and an existing `tpv` schema
- WHEN the Doctrine DBAL connection is requested from the container
- THEN it MUST connect successfully

#### Scenario: Graceful database failure

- GIVEN invalid or missing database credentials
- WHEN the DBAL connection attempt occurs
- THEN the application MUST NOT crash; health check MUST report `database: unhealthy`

### R6: Console Application

`bin/console` MUST be an executable PHP entry point using Symfony Console Component, booting the kernel to provide access to all registered commands including Doctrine migrations.

#### Scenario: Console lists commands

- GIVEN an installed application with dependencies
- WHEN `php bin/console` is executed
- THEN it MUST list available commands without errors and Doctrine migration commands MUST be present

### R7: Environment Configuration

`.env` MUST ship with development defaults. Production secrets (`APP_SECRET`, `TPV_DB_PASSWORD`, `JWT_SECRET`, `PS_API_KEY`, `PS_API_URL`) MUST use the `%env()%` Symfony env var pattern. `.env.local` and system environment variables MUST take precedence over `.env` defaults.

#### Scenario: Dev defaults enable local development

- GIVEN a `.env` file with `APP_ENV=dev` defaults
- WHEN a local MySQL `tpv` schema exists
- THEN the application MUST boot in development mode with debugging enabled

#### Scenario: Production overrides take precedence

- GIVEN a production deployment with environment overrides
- WHEN `.env.local` or system env vars are set
- THEN production values MUST override `.env` defaults

### R8: Health Check Endpoint

`GET /api/health` MUST return JSON with: `status` (`healthy` or `unhealthy`), `timestamp` (ISO 8601), `checks` object containing `database` (connection test result). HTTP 200 when all checks pass, HTTP 503 when any check fails.

#### Scenario: All checks pass

- GIVEN the `tpv` database is reachable
- WHEN `GET /api/health` is requested
- THEN response MUST be HTTP 200 with `{"status":"healthy","timestamp":"...","checks":{"database":"ok"}}`

#### Scenario: Database unreachable

- GIVEN the `tpv` database is unreachable
- WHEN `GET /api/health` is requested
- THEN response MUST be HTTP 503 with `{"status":"unhealthy","timestamp":"...","checks":{"database":"error: ..."}}`

### R9: Database Migration

The initial Doctrine migration MUST create all 10 `tpv` tables in dependency order: `role`, `terminal`, `user`, `session`, `movement`, `order_meta`, `offline_queue`, `audit_log`, `z_report`, `messenger_messages`. All `ps_*_id` columns MUST be plain `INT` (logical references, no FK to remote schema). Re-running migrations MUST be idempotent.

#### Scenario: Fresh migration creates all tables

- GIVEN an empty `tpv` schema
- WHEN `php bin/console doctrine:migrations:migrate` is executed
- THEN all 10 tables MUST be created with columns, types, FKs (within `tpv`), and indexes per PRD Section 9.2

#### Scenario: Idempotent re-run

- GIVEN the `tpv` schema already has all tables from a prior migration
- WHEN `doctrine:migrations:migrate` is executed again
- THEN it MUST report "no migrations to execute" without errors

### R10: Test Tooling

- `phpunit.xml.dist` MUST target `tests/` with code coverage for `src/`
- `phpstan.neon` MUST analyze `src/` at level 5
- `.php-cs-fixer.php` MUST use `@PSR12` preset with `declare_strict_types`
- All tools MUST execute without fatal errors

#### Scenario: Test suite and analysis run cleanly

- GIVEN the application is installed with dev dependencies
- WHEN `php vendor/bin/phpunit`, `php vendor/bin/phpstan analyse`, and `php vendor/bin/php-cs-fixer fix --dry-run` are each executed
- THEN each MUST exit without fatal errors (zero tests is acceptable for phpunit)

## Non-Functional Requirements

| ID | Requirement |
|----|------------|
| NFR-1 | PHP 8.1+ compatibility; `declare(strict_types=1)` in all PHP files |
| NFR-2 | PSR-4 autoloading via Composer |
| NFR-3 | No external service dependency required at bootstrap (no PrestaShop, no Redis, no external API) |
| NFR-4 | All configuration via environment variables; no hardcoded credentials |
| NFR-5 | Application MUST boot in under 500ms on standard hardware with warm cache |