# Tasks: 04-catalog-sync

## Overview
Full sync from PrestaShop Webservice API to local `tpv` catalog tables, triggered manually via console command or asynchronously via Symfony Messenger.

**Total tasks**: 24 across 6 phases

---

## Phase 1: DB Migration + Env Config + DI Wiring

### 1.1. Create Doctrine Migration `Version00000003CreateCatalogTables`
- [x] Create `migrations/Version00000003CreateCatalogTables.php`
- [x] Use `Schema::createTable()` for 4 tables: `catalog_product`, `catalog_combination`, `catalog_stock`, `catalog_category`
- [x] `catalog_product`: `id` (PK), `name`, `reference`, `ean13`, `price`, `id_category_default`, `active`, `synced_at`; indexes on `name`, `ean13`, `active`
- [x] `catalog_combination`: `id` (PK), `id_product` (indexed), `reference`, `ean13`, `price_impact`, `synced_at`; indexes on `id_product`, `ean13`
- [x] `catalog_stock`: composite PK `(id_product, id_product_attribute)`, `quantity`, `id_shop` (default 1), `synced_at`; index on `id_product`
- [x] `catalog_category`: `id` (PK), `name`, `id_parent`, `active`, `synced_at`; index on `active`
- [x] All tables: InnoDB engine, `synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- [x] Add `down()` method that drops all 4 tables

### 1.2. Uncomment and Configure PS API Env Vars
- [x] In `.env`, uncomment `PS_API_URL` and `PS_API_KEY`
- [x] Set `PS_API_URL` default to `https://tienda.ejemplo.com/api`
- [x] Add validation in `Kernel.php` or `config/bootstrap.php` that throws `InvalidConfigurationException` if `PS_API_KEY` is empty when app boots (deferred — catch at service construction time instead)
- [x] In `.env.example`, keep both vars commented with example values

### 1.3. Wire `PrestaShopApiClient` in `services.yaml`
- [x] Add `PsApiClient` factory/definition with `$baseUrl` from env `PS_API_URL`, `$apiKey` from env `PS_API_KEY`
- [x] Add `HttpClientInterface` autowiring (Symfony HttpClient is already in framework-bundle)
- [x] Register `VenPosTPV\Service\Catalog\CatalogSyncService` with `PsApiClient` + `doctrine.dbal.tpv_connection` injected
- [x] Register `VenPosTPV\Message\SyncCatalogMessageHandler` with `CatalogSyncService` injected

### 1.4. Create `config/packages/messenger.yaml`
- [x] Create `config/packages/messenger.yaml`
- [x] Configure `sync_catalog` transport using `doctrine` transport with table name `messenger_messages`
- [x] Add `SyncCatalogMessage` routing to `sync_catalog` transport
- [x] Configure `failed` queue with `doctrine` transport for retry handling

---

## Phase 2: PrestaShopApiClient

### 2.1. Create `PsApiException` Class
- [x] Create `src/Service/PrestaShop/Exception/PsApiException.php`
- [x] Extend `RuntimeException`
- [x] Add constructor accepting `string $message`, `int $code`, `?Throwable $previous`, and optional `?ResponseInterface $response`
- [x] Add `?ResponseInterface getResponse(): ?ResponseInterface` getter
- [x] Add factory static methods: `::httpError(int $code, string $message)`, `::networkError(string $message, Throwable $previous)`, `::rateLimitExceeded(int $retryAfter)`

### 2.2. Create `PrestaShopApiClient` Class
- [x] Create `src/Service/PrestaShop/PrestaShopApiClient.php`
- [x] Constructor: `__construct(string $baseUrl, string $apiKey, HttpClientInterface $httpClient)`
- [x] Store baseUrl (must end with `/api`) and apiKey; use `HttpClientInterface` for all requests
- [x] Implement `getProducts(): array` — calls `GET /api/products?output_format=JSON&active=1&display=full&limit=0,100` (paginate until empty)
- [x] Implement `getCombinations(): array` — calls `GET /api/combinations?output_format=JSON&display=full&limit=0,100`
- [x] Implement `getStockAvailables(): array` — calls `GET /api/stock_availables?output_format=JSON&display=full&limit=0,100`
- [x] Implement `getCategories(): array` — calls `GET /api/categories?output_format=JSON&display=full&limit=0,100`
- [x] Add private `paginate(string $endpoint): array` helper that loops `?limit=offset,limit` until no more results; collect all pages into one array
- [x] Add private `extractTranslations(array $field, string $languageKey = '1'): mixed` helper for multi-lang fields (name, description)
- [x] Add private `buildAuthHeader(): string` — returns `base64_encode($apiKey . ':')` as Basic Auth header
- [x] HTTP Client configured with timeout 10s, max 3 retries with exponential backoff (1s, 2s, 4s)
- [x] Handle HTTP 401 → `PsApiException::httpError(401, 'Invalid PS API credentials')`
- [x] Handle HTTP 429 → `PsApiException::rateLimitExceeded()` after 3 retries exhausted
- [x] Handle HTTP errors 4xx/5xx → `PsApiException::httpError($code, ...)` with Response body in message
- [x] Handle network errors → `PsApiException::networkError(...)`

### 2.3. Write Unit Tests for `PrestaShopApiClient`
- [x] Create `tests/Unit/Service/PrestaShop/PrestaShopApiClientTest.php`
- [x] Test successful single-page fetch for each entity type
- [x] Test pagination: mock 2 pages of results, verify offset updates correctly
- [x] Test multi-lang name extraction: `{1: 'Nombre ES', 2: 'English Name'}` → returns `'Nombre ES'` when languageKey=1
- [x] Test HTTP 401 throws `PsApiException` with 401 code
- [x] Test HTTP 429 with retries: verify 3 retry attempts before throwing
- [x] Test HTTP 500 throws `PsApiException` after retries
- [x] Test network error throws `PsApiException` with network error type
- [x] Use `MockHttpClient` from `Symfony\Component\HttpClient\MockHttpClient`
- [x] Create canned JSON response fixtures for testing pagination

---

## Phase 3: CatalogSyncService

### 3.1. Create `CatalogSyncService` Class
- [x] Create `src/Service/Catalog/CatalogSyncService.php`
- [x] Constructor: `__construct(PrestaShopApiClient $apiClient, Connection $connection)`
- [x] Implement `syncAll(): array`:
  1. Begin transaction
  2. Truncate `catalog_category` (FK order: no dependencies)
  3. Truncate `catalog_combination`
  4. Truncate `catalog_stock`
  5. Truncate `catalog_product`
  6. Fetch `getCategories()` → batch-insert into `catalog_category`
  7. Fetch `getProducts()` → batch-insert into `catalog_product` (multi-lang name → extract lang 1)
  8. Fetch `getCombinations()` → batch-insert into `catalog_combination`
  9. Fetch `getStockAvailables()` → batch-insert into `catalog_stock`
  10. Commit transaction
  11. Return `{products: int, combinations: int, stock: int, categories: int}`
- [x] Implement `syncStock(): array`:
  1. Begin transaction
  2. Truncate `catalog_stock`
  3. Fetch `getStockAvailables()` → batch-insert
  4. Commit
  5. Return `{stock: int}`
- [x] Add private `batchInsert(string $table, array $rows, Closure $rowToParams): int` helper using `$connection->executeStatement()`
- [x] All DB operations must use `$connection` (TPV connection from ConnectionManager)
- [x] On any exception during sync: rollback transaction, then re-throw `CatalogSyncException`
- [x] Add private `truncateTable(string $table): void` helper using `$connection->executeStatement("TRUNCATE TABLE `$table`")`

### 3.2. Create `CatalogSyncException` Class
- [x] Create `src/Service/Catalog/Exception/CatalogSyncException.php`
- [x] Extend `RuntimeException`
- [x] Add static factory: `::fromPrevious(Throwable $previous): self`

### 3.3. Write Unit Tests for `CatalogSyncService`
- [x] Create `tests/Unit/Service/Catalog/CatalogSyncServiceTest.php`
- [x] Mock `PrestaShopApiClient` to return canned data
- [x] Use in-memory SQLite connection for unit tests
- [x] Test `syncAll()` — verify all 4 tables get correct row counts after truncate + insert
- [x] Test `syncStock()` — verify only `catalog_stock` gets modified
- [x] Test transaction rollback: after simulating DB error mid-sync, verify no tables are modified
- [x] Test `syncAll()` with empty PS catalog — verify tables are truncated but exist

---

## Phase 4: Console Command + Messenger Message/Handler

### 4.1. Create `SyncCatalogMessage` Class
- [x] Create `src/Message/SyncCatalogMessage.php`
- [x] Empty DTO with no properties (marker class for Messenger)
- [x] Add `@final` annotation — not to be extended

### 4.2. Create `SyncCatalogMessageHandler` Class
- [x] Create `src/MessageHandler/SyncCatalogMessageHandler.php`
- [x] Implement `MessageHandlerInterface` with `__invoke(SyncCatalogMessage $message)`
- [x] Inject `CatalogSyncService`
- [x] On `__invoke`: call `$this->catalogSyncService->syncAll()`
- [x] Log INFO with sync counts: `"Catalog sync completed: {products} products, {combinations} combinations, {stock} stock, {categories} categories"`
- [x] Catch `PsApiException`: log ERROR with exception message, then `throw` (requeue for retry)
- [x] Catch `CatalogSyncException`: log ERROR, then `throw` (requeue for retry)

### 4.3. Create `CatalogSyncCommand` Class
- [x] Create `src/Command/CatalogSyncCommand.php`
- [x] Extend `Command`, set `protected static $defaultName = 'tpv:catalog:sync'`
- [x] Add option `--full` (default, no value) — runs `syncAll()`
- [x] Add option `--stock-only` (no value) — runs `syncStock()`
- [x] On execute:
  - Validate only one mode is selected
  - Call appropriate service method
  - Output sync summary table with counts and duration
  - Exit code 0 on success, 1 on failure
- [x] Catch `PsApiException` → output `"<error>PrestaShop API error: {message}</error>"`, exit 1
- [x] Catch `CatalogSyncException` → output `"<error>Sync failed: {message}</error>"`, exit 1
- [x] Catch `InvalidArgumentException` → output usage info, exit 1

### 4.4. Write Unit Tests for Command and Handler
- [x] Create `tests/Unit/Command/CatalogSyncCommandTest.php`
- [x] Mock `CatalogSyncService`
- [x] Test `--full` calls `syncAll()`, `--stock-only` calls `syncStock()`
- [x] Test exit code 0 on success, 1 on exception
- [x] Create `tests/Unit/MessageHandler/SyncCatalogMessageHandlerTest.php`
- [x] Test handler calls `syncAll()` and logs counts

---

## Phase 5: Tests

### 5.1. Integration Test: Migration
- [x] Create `tests/Integration/Migration/Version00000003CreateCatalogTablesTest.php`
- [x] Connect to local MySQL `tpv` database (localhost)
- [x] Run `up()` on fresh schema
- [x] Introspect: verify all 4 tables exist with correct columns
- [x] Verify column types match design (INT UNSIGNED, DECIMAL, DATETIME, etc.)
- [x] Verify indexes present (idx_name on catalog_product, composite PK on catalog_stock)
- [x] Run `down()`, verify tables dropped

### 5.2. Integration Test: Full Sync with Real PS API
- [x] Create `tests/Integration/Service/CatalogSyncEndToEndTest.php` (only if PS_API_URL points to real instance)
- [x] Seed PrestaShop with known products, combinations, stock, categories
- [x] Run `CatalogSyncService::syncAll()`
- [x] Assert local table counts match seeded PS data counts
- [x] Mark as `@group integration` and `@requires external_ps_api`

### 5.3. Add `symfony/http-client` to `composer.json`
- [x] Run `composer require symfony/http-client:^6.4`
- [x] Verify in `composer.json` `"symfony/http-client": "^6.4"` appears
- [x] Ensure `php-http/discovery` is available for autowiring `HttpClientInterface`

---

## Phase 6: Verification

### 6.1. Self-Verification Against Spec
- [x] Re-read `openspec/changes/04-catalog-sync/specs/catalog-sync/spec.md`
- [x] For each requirement (C1–C6): verify at least one implementation artifact addresses it
- [x] Document verification checklist in task comments

### 6.2. Code Quality Check
- [x] Run `php -l` on all new PHP files (no syntax errors)
- [x] Verify all new classes have `declare(strict_types=1)` and proper namespace
- [x] Run `vendor/bin/phpunit` — all tests green
- [x] Check `src/Service/PrestaShop/PrestaShopApiClient.php` handles the 4 error scenarios from spec (401, network error, 429, general HTTP error)

### 6.3. Final Output
- [x] Run `php bin/console tpv:catalog:sync --full` (dry-run if no real PS API)
- [x] Confirm command exits 0 and shows summary
- [x] Save verification summary to `sdd/04-catalog-sync/verification.md` in Engram

---

## Task Count by Phase

| Phase | Tasks | Description |
|-------|-------|-------------|
| Phase 1 | 4 | DB Migration + Env Config + DI Wiring |
| Phase 2 | 3 | PrestaShopApiClient (exception, client, tests) |
| Phase 3 | 3 | CatalogSyncService (service, exception, tests) |
| Phase 4 | 4 | Console Command + Messenger Message/Handler |
| Phase 5 | 3 | Tests (migration integration, e2e, composer) |
| Phase 6 | 3 | Verification |
| **Total** | **20** | |

---

## Skill Resolution

| Skill | When Used | Reason |
|-------|-----------|--------|
| `sdd-apply` | Phase 2–4 tasks | Writing actual service/command code following specs and design |
| `php` (Symfony) | All phases | PHP 8.2+, Symfony 6.4 framework patterns |
| `symfony-messenger` | Phase 4 | Message/Handler patterns, transport configuration |
| `doctrine-migrations` | Phase 1 | Migration file creation with Schema tool |
| `php-testing` | Phase 2.3, 3.3, 4.4 | PHPUnit + MockHttpClient patterns |

---

## Next Recommended

After this tasks phase completes, the orchestrator should dispatch **Phase 1 tasks** to an `sdd-apply` agent. Phase 1 is standalone (migration + config) with no dependencies on later phases.

**Dependency order**: Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6
