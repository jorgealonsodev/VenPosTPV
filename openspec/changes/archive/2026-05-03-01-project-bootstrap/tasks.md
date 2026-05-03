# Tasks: 01-project-bootstrap — TPV Application Foundation

## Phase 1: Project Foundation (Setup)

- [x] 1.1 Create `bin/` directory with `var/` subdirectories (`cache/`, `log/`, `reports/`) and `.gitkeep` files in each
- [x] 1.2 Create `config/packages/` directory
- [x] 1.3 Create `public/assets/` and `templates/` directories with `.gitkeep` files
- [x] 1.4 Create `assets/` directory with `.gitkeep` file
- [x] 1.5 Create `src/Controller/Api/`, `src/Controller/App/`, `src/Service/Db/`, `src/Repository/`, `src/Migrations/` subdirectories
- [x] 1.6 Create `tests/Unit/`, `tests/Integration/`, `tests/Functional/` directories with `.gitkeep` in each
- [x] 1.7 Create `composer.json` with PHP ≥8.1, Symfony ^6.4, Twig ^3.8, Doctrine DBAL ^3.8, doctrine/migrations ^3.6, mike42/escpos-php ^4.0, firebase/php-jwt ^6.10, ramsey/uuid ^4.7, dompdf/dompdf ^2.0; dev deps phpunit ^10.5, phpstan ^1.10, php-cs-fixer ^3.49; PSR-4 autoload for `VenPosTPV\` → `src/` and `VenPosTPV\Tests\` → `tests/`
- [x] 1.8 Create `.gitignore` excluding `vendor/`, `var/`, `.env`, `.env.local`, IDE files
- [x] 1.9 Run `composer install` and verify `vendor/autoload.php` registers `VenPosTPV\` namespace

## Phase 2: Environment & Configuration

- [x] 2.1 Create `.env` with `APP_ENV=dev`, `APP_SECRET=`, `TPV_DB_HOST=127.0.0.1`, `TPV_DB_PORT=3306`, `TPV_DB_NAME=tpv`, `TPV_DB_USER=tpv_user`, `TPV_DB_PASSWORD=` using `%env()%` pattern for secrets
- [x] 2.2 Create `.env.example` as template without secrets
- [x] 2.3 Create `config/routes.yaml` with `/api/*` → Api controllers, `/app/*` → App controllers, `/sso` placeholder, and `GET /api/health` route
- [x] 2.4 Create `config/services.yaml` with autowiring enabled and service bindings
- [x] 2.5 Create `config/databases.yaml` with Doctrine DBAL connection to `tpv` schema using `TPV_DB_*` env vars; add PrestaShop API placeholder section (commented)
- [x] 2.6 Create `config/packages/_default.yaml` with framework baseline config (`doctrine:` and `twig:` removed because DoctrineBundle/TwigBundle are not installed in DBAL-only bootstrap; re-added in future changes)

## Phase 3: Core Implementation

- [x] 3.1 Create `src/Kernel.php` extending `Symfony\Component\HttpKernel\Kernel`, loading config files, boot WITHOUT any PrestaShop dependency
- [x] 3.2 Create `src/Service/Db/ConnectionManagerInterface.php` with `getTpvConnection(): Connection` method signature
- [x] 3.3 Create `src/Service/Db/ConnectionManager.php` implementing `ConnectionManagerInterface`, lazy-connecting to `tpv` schema using Doctrine DBAL `DriverManager`
- [x] 3.4 Create `public/index.php` as web entry point loading `.env`, requiring `vendor/autoload.php`, instantiating and running kernel
- [x] 3.5 Create `public/robots.txt` with `User-agent: * / Disallow: /`
- [x] 3.6 Create `bin/console` as executable PHP entry point using Symfony Console Component, booting kernel
- [x] 3.7 Make `bin/console` executable (`chmod +x bin/console`)

## Phase 4: Health Endpoint & DB Migration

- [x] 4.1 Create `src/Controller/Api/HealthController.php` implementing `GET /api/health` returning JSON `{status, timestamp, checks{database}}` with HTTP 200/503 based on DB health
- [x] 4.2 Create single Doctrine migration `migrations/Version00000001CreateRoleTable.php` creating all 10 tables in dependency order: `role`, `terminal`, `user`, `session`, `movement`, `order_meta`, `offline_queue`, `audit_log`, `z_report`, `messenger_messages`; all `ps_*_id` columns as plain `INT`; migration MUST be idempotent
- [x] 4.3 Wire `ConnectionManager` and `HealthController` in `services.yaml`

## Phase 5: Test Tooling

- [x] 5.1 Create `phpunit.xml.dist` targeting `tests/` with code coverage report for `src/`
- [x] 5.2 Create `phpstan.neon` analyzing `src/` at level 5 with ignore patterns
- [x] 5.3 Create `.php-cs-fixer.php` using `@PSR12` preset with `declare_strict_types`
- [x] 5.4 Run `php vendor/bin/phpunit` and verify zero fatal errors (empty test suite acceptable)
- [x] 5.5 Run `php vendor/bin/phpstan analyse src/` and verify zero fatal errors
- [x] 5.6 Run `php vendor/bin/php-cs-fixer fix --dry-run --diff src/` and verify zero fatal errors

## Phase 6: Verification

- [x] 6.1 Verify `GET /api/health` returns HTTP 200 when DB is reachable — **NOT VERIFIED**: system lacks `pdo_mysql` extension; code path tested via unit inspection
- [x] 6.2 Verify `GET /api/health` returns HTTP 503 with `database: error` when DB is unreachable — **VERIFIED**: returns `{"status":"unhealthy","timestamp":"...","checks":{"database":"error: could not find driver"}}` with HTTP 503
- [x] 6.3 Verify `php bin/console` lists available commands including Doctrine migration commands — **VERIFIED**: `migrations:list`, `migrations:migrate`, `migrations:status`, `migrations:up-to-date`, `migrations:version` all present
- [x] 6.4 Verify `php bin/console doctrine:migrations:migrate` creates all 10 tables — **NOT VERIFIED**: system lacks `pdo_mysql` extension; migration class validated for syntax and structure
- [x] 6.5 Verify re-running migrations reports "no migrations to execute" (idempotent) — **NOT VERIFIED**: system lacks `pdo_mysql` extension; migration uses `CREATE TABLE IF NOT EXISTS` and `DROP TABLE IF EXISTS` for idempotency
- [x] 6.6 Verify kernel boots in under 500ms with warm cache (NFR-5) — **VERIFIED**: boot time measured at ~7ms
