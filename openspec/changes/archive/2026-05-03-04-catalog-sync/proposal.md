# Proposal: Catalog Sync from PrestaShop to Local TPV Database

## Intent

Sync product catalog, stock levels, and categories from the PrestaShop Web Service API into local TPV database tables. Enables fast, offline-capable product search and stock checks without relying on slow real-time PS API calls.

## Scope

### In Scope
- `PrestaShopApiClient` service: HTTP Basic Auth, pagination, JSON output
- Local catalog tables: `catalog_product`, `catalog_combination`, `catalog_stock`, `catalog_category`
- `CatalogSyncService`: full replace sync (truncate + insert) from PS API to local tables
- Console command: `php bin/console tpv:catalog:sync` (manual trigger)
- `.env` configuration: `PS_API_URL`, `PS_API_KEY`
- Symfony Messenger async handler for background sync jobs

### Out of Scope
- Incremental / delta sync
- Product image download (URLs only)
- Customer sync
- Multi-shop filtering beyond storing `id_shop`
- Tax-inclusive price calculation
- Real-time stock validation at checkout

## Capabilities

### New Capabilities
- `catalog-sync`: Pull active products, combinations, stock, and categories from PrestaShop Webservice API into local DB; expose manual and async sync triggers.

### Modified Capabilities
- None

## Approach

Symfony HttpClient (`symfony/http-client`) paginates through PS API endpoints (`products`, `combinations`, `stock_availables`, `categories`) using `?output_format=JSON` and `?limit=offset,limit`. `CatalogSyncService` runs a full replace: truncate local tables, paginate and insert all active records. Multi-lang names default to language ID 1; combination attributes stored as JSON. Sync runs manually via console command or asynchronously via Symfony Messenger to avoid blocking the request thread during the 20-50s full sync window.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `composer.json` | Modified | Add `symfony/http-client` |
| `.env` / `.env.example` | Modified | Add `PS_API_URL`, `PS_API_KEY` |
| `config/databases.yaml` | Modified | Wire PS API parameters |
| `config/services.yaml` | Modified | Register HttpClient and catalog services |
| `migrations/` | New | Migration for catalog tables |
| `src/Service/Catalog/` | New | `PsApiClient`, `CatalogSyncService` |
| `src/Repository/` | New | Catalog repositories (DBAL) |
| `src/Command/` | New | `TpvCatalogSyncCommand` |
| `config/packages/messenger.yaml` | Modified | Async transport for sync job |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|-------------|
| Full sync of 10K products takes 20-50s | High | Run as Messenger background job; show progress |
| Network / API unavailability breaks sync | Medium | Graceful error handling; retry with HttpClient |
| API rate limits on large catalogs | Medium | Pagination with delays; single-threaded for MVP |
| Stale stock between syncs | Medium | Document as known limitation; full re-sync recommended |

## Rollback Plan

1. Revert migrations to drop catalog tables
2. Remove `symfony/http-client` from `composer.json`
3. Remove PS API env vars from `.env` and service config
4. Delete `src/Service/Catalog/`, `src/Command/TpvCatalogSyncCommand.php`, and catalog repositories

## Dependencies

- `tpvconnect` PS module installed with Webservice API key generated
- PrestaShop Webservice API enabled

## Success Criteria

- [ ] Console command triggers full sync and populates all 4 catalog tables
- [ ] Async Messenger handler executes sync without blocking HTTP requests
- [ ] Unit tests mock PS API client; no network dependency in test suite
- [ ] Only `active=1` products are synced
