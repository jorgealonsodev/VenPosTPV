# Proposal: Bootstrap TPV Application Foundation

## Intent
Create the foundational infrastructure for `venpostpv`: a PHP TPV application running as a Symfony 6.4 subdominio alongside PrestaShop, with its own database schema, kernel, routing, and tooling.

## Scope

### In Scope
- `composer.json` with Symfony 6.4, Twig, Doctrine DBAL, testing, and hardware libraries
- Directory structure (`bin/`, `config/`, `public/`, `src/`, `templates/`, `tests/`, `var/`)
- `public/index.php` with PrestaShop bootstrap isolation + TPV kernel
- `src/Kernel.php` — minimal HTTP kernel
- `bin/console` — CLI entry point
- `config/routes.yaml`, `config/services.yaml`, `config/databases.yaml`
- `.env` template and `.gitignore`
- PHPUnit, PHPStan, PHP-CS-Fixer configurations
- First Doctrine migration (`role` table)
- `GET /api/health` endpoint

### Out of Scope
- PrestaShop `tpvconnect` module → `02-prestashop-module`
- Remaining DB tables → `03-database-schema`
- Business controllers, services, repositories → feature changes
- Frontend build pipeline → `06-frontend-pipeline`

## Capabilities

### New Capabilities
- `project-bootstrap`: Application foundation — kernel, routing, DB config, health check, CLI console

### Modified Capabilities
- None

## Approach
Use **Symfony 6.4 LTS** with an isolation adapter layer. PrestaShop 8.x loads first (Symfony 4.4 autoloader), then TPV autoloader fills gaps without overriding. TPV uses its own 6.4 router, kernel, and console. PSR-4 namespace `VenPosTPV\`. YAML config per PRD. Dual-database: `prestashop` via PS core, `tpv` via Doctrine DBAL.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `composer.json` | New | All backend and dev dependencies |
| `public/index.php` | New | Web entry point with PS bootstrap |
| `src/Kernel.php` | New | Custom HTTP kernel |
| `config/` | New | Routes, services, databases (YAML) |
| `tests/` | New | PHPUnit, PHPStan, CS-Fixer configs |
| `src/Migrations/` | New | First migration (`role` table) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Symfony 4.4/6.4 class collision | High | Load PS first, TPV second; adapter layer; Docker PS 8.x for testing |
| PS core bootstrap fails | High | Graceful degradation when PS unavailable; validation script |
| No DB for local dev | Medium | Environment-driven connection skip; CI-safe config |

## Rollback Plan
Delete all created files and directories. Repository returns to greenfield state with only PRD and `.atl/`.

## Dependencies
- PHP 8.1+, Composer, MySQL/MariaDB
- Symfony 6.4 components
- Twig 3.8, Doctrine DBAL 3.8, PHPUnit 10.5, PHPStan 1.10, PHP-CS-Fixer 3.49
- `mike42/escpos-php`, `firebase/php-jwt`, `ramsey/uuid`, `dompdf/dompdf`

## Success Criteria
- [ ] `composer install` completes without conflicts
- [ ] `php bin/console` lists commands without errors
- [ ] `php public/index.php` boots kernel (graceful without PS/DB)
- [ ] `php bin/console doctrine:migrations:migrate` creates `role` table
- [ ] `GET /api/health` returns JSON status
- [ ] PHPUnit, PHPStan, PHP-CS-Fixer run without fatal errors
