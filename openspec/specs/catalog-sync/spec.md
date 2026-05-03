# Catalog Sync Specification

## Purpose

Pull active products, combinations, stock, and categories from PrestaShop Webservice API into local TPV tables; expose manual and async triggers for offline-capable product search.

## Requirements

### Requirement: PS API Client (C1)

MUST authenticate via HTTP Basic Auth (API key as username, blank password). SHALL request JSON via `?output_format=JSON`, paginate via `?limit=offset,limit`, filter `active=1`. MUST throw `PsApiException` on error.

#### Scenario: Successful paginated fetch
- GIVEN PS API reachable with valid credentials
- WHEN client requests products with pagination and active filter
- THEN client returns decoded product records array

#### Scenario: Authentication failure
- GIVEN PS API credentials are invalid or missing
- WHEN client makes any request
- THEN client throws `PsApiException` with HTTP 401

#### Scenario: Network error
- GIVEN PS API host is unreachable or times out
- WHEN retry attempts exhaust (3 with backoff)
- THEN client throws `PsApiException` with network error

#### Scenario: Rate limit (HTTP 429)
- GIVEN PS API returns HTTP 429
- WHEN client receives rate-limit response
- THEN client retries up to 3 times with backoff, then throws exception

### Requirement: Local Catalog Tables (C2)

MUST create `catalog_product`, `catalog_combination`, `catalog_stock`, `catalog_category`, and `catalog_product_category` tables in `tpv` schema via Doctrine migration. All tables use InnoDB with `synced_at DATETIME DEFAULT CURRENT_TIMESTAMP`.

#### Scenario: Migration creates all catalog tables
- GIVEN empty `tpv` schema
- WHEN migration executes
- THEN 5 tables exist with correct columns, indexes, and foreign keys

### Requirement: Catalog Sync Service (C3)

MUST provide `CatalogSyncService` performing full replace: truncate tables, paginate and insert all active PS records. Sync order: categories → products → product-category → combinations → stock (FK order). Attributes as JSON; multi-lang names default to language ID 1. MUST be transactional — failure rolls back all changes.

#### Scenario: Full sync populates all tables
- GIVEN PS API returns products, combinations, stock entries, and categories
- WHEN sync service runs
- THEN all tables populated; product-category mappings inserted

#### Scenario: Sync replaces stale data
- GIVEN local tables contain stale data from previous sync
- WHEN sync service runs
- THEN stale data replaced; no orphaned rows

#### Scenario: Sync with empty PS catalog
- GIVEN PS API returns zero active products
- WHEN sync service runs
- THEN local catalog tables are truncated (empty but exist)

#### Scenario: Sync fails mid-way
- GIVEN PS API succeeds for products but fails on combinations
- WHEN sync encounters error during combinations step
- THEN changes roll back; tables unchanged; `CatalogSyncException` thrown

### Requirement: Console Command (C4)

MUST provide `php bin/console tpv:catalog:sync` triggering full sync with record counts per table.

#### Scenario: Manual sync succeeds
- GIVEN PS API reachable with valid credentials
- WHEN operator runs `tpv:catalog:sync`
- THEN command exits code 0 with sync summary counts per table

#### Scenario: Manual sync fails
- GIVEN PS API unreachable or credentials invalid
- WHEN operator runs `tpv:catalog:sync`
- THEN command exits code 1 with error message

### Requirement: Background Sync (C5)

MUST provide `CatalogSyncMessage` + `CatalogSyncHandler` via Symfony Messenger for async execution.

#### Scenario: Async sync completed
- GIVEN Messenger async transport configured and worker running
- WHEN `CatalogSyncMessage` dispatched
- THEN handler executes full sync; result logged at INFO with counts per table

#### Scenario: Async sync fails
- GIVEN PS API unreachable when handler processes message
- WHEN handler catches `PsApiException`
- THEN handler logs ERROR and rethrows for Messenger retry

### Requirement: Configuration (C6)

MUST read `PS_API_URL` and `PS_API_KEY` from env vars, wired to `PsApiClient` via DI. `PS_API_URL` SHALL end with `/api`. `PS_API_KEY` MUST NOT be empty.

#### Scenario: Valid configuration
- GIVEN `.env` has `PS_API_URL` (ending `/api`) and `PS_API_KEY`
- WHEN application boots
- THEN `PsApiClient` receives values and authenticates

#### Scenario: Missing or invalid configuration
- GIVEN `PS_API_KEY` is empty or `PS_API_URL` is missing
- WHEN application boots or sync attempted
- THEN system throws `InvalidConfigurationException`