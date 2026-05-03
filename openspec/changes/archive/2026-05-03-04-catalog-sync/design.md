# Design: Catalog Sync from PrestaShop

## Technical Approach

Full-batch sync from PS Webservice API to local `tpv` tables. `PrestaShopApiClient` paginates through JSON endpoints (`?output_format=JSON`), `CatalogSyncService` truncates all 4 catalog tables then batch-inserts via DBAL transactions, triggered by console command or Messenger handler.

## Architecture Decisions

| Decision | Choice | Rejected | Rationale |
|---|---|---|---|
| API response format | JSON (`?output_format=JSON`) | XML (default) | Simpler parsing, lower overhead. PS 8.1+ supports JSON natively. |
| Column naming | `id` not `ps_id` | `ps_id` prefix | These are LOCAL tables — `id` IS the PrestaShop ID by definition. Simpler queries, consistent with task spec. |
| Combination attributes | Omit `attribute_names` JSON from MVP | Store JSON blob | Attribute names need extra API calls (`/api/product_option_values`). Add in Phase 2 when product search needs them. |
| Product-category many-to-many | Omit `catalog_product_category` table | Full many-to-many | `id_category_default` on product satisfies category navigation for MVP. Add when category browsing requires full product lists. |
| Stock PK | Composite `(id_product, id_product_attribute)` | Auto-increment + unique | Matches PS `stock_availables` natural key. Simpler lookups (`WHERE id_product=? AND id_product_attribute=?`). |
| HTTP client | Symfony HttpClient | Raw cURL, Guzzle | Already aligned with Symfony 6.4 stack. Built-in retry/timeout. Mockable for tests via `MockHttpClient`. |
| Multi-shop | Store `id_shop` in stock, default to 1 | Filter by shop in API query | Store all rows for future multi-shop; filter at query time. MVP assumes single shop. |
| Sync strategy | Truncate + insert (full replace) | Upsert with date comparison | Simpler, no conflict resolution. With 19 products the operation is instant. |
| Price storage | `price` (tax-excl, as PS returns it) | `price_te` + `price_ti` | Tax calculation requires `/api/tax_rules` + `/api/tax` endpoints — out of scope for this change. Store raw PS price. |

## Data Flow

```
┌─────────────────────┐
│  CatalogSyncCommand │ ← bin/console tpv:catalog:sync [--full|--stock-only]
│  (or Messenger)     │
└─────────┬───────────┘
          │ calls syncAll() / syncStock()
          ▼
┌─────────────────────┐       ┌────────────────────────────┐
│ CatalogSyncService  │──────▶│  PrestaShopApiClient       │
│                     │       │  GET /api/products?...      │
│ 1. TRUNCATE tables  │       │  GET /api/combinations?...  │
│ 2. Paginate + INSERT│       │  GET /api/stock_availables? │
│ 3. Report counts    │       │  GET /api/categories?...    │
└─────────┬───────────┘       └────────────────────────────┘
          │                          │ HTTP Basic Auth
          ▼                          │ (api_key:)
   ┌─────────────┐                   │
   │  tpv DB     │                   │
   │ catalog_*   │◀──────────────────┘
   └─────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `migrations/Version00000003CreateCatalogTables.php` | Create | 4 catalog tables: `catalog_product`, `catalog_combination`, `catalog_stock`, `catalog_category` |
| `src/Service/PrestaShop/PrestaShopApiClient.php` | Create | HTTP client with pagination, JSON parsing, error handling |
| `src/Service/PrestaShop/Exception/PrestaShopApiException.php` | Create | Base exception for PS API errors |
| `src/Service/Catalog/CatalogSyncService.php` | Create | Orchestrates full sync: truncate + batch insert per entity |
| `src/Command/CatalogSyncCommand.php` | Create | `tpv:catalog:sync` with `--full`, `--stock-only` options |
| `src/Message/SyncCatalogMessage.php` | Create | Messenger DTO |
| `src/MessageHandler/SyncCatalogMessageHandler.php` | Create | Dispatches `CatalogSyncService::syncAll()` |
| `composer.json` | Modify | Add `symfony/http-client:^6.4` |
| `.env` / `.env.example` | Modify | Uncomment `PS_API_URL`, `PS_API_KEY` |
| `config/databases.yaml` | Modify | Uncomment PS API parameters |
| `config/services.yaml` | Modify | Wire `PrestaShopApiClient` with env vars, register sync services |
| `config/packages/messenger.yaml` | Create | Configure sync transport (doctrine) |

## Interfaces / Contracts

```php
// PrestaShopApiClient — reads from PS Webservice API
class PrestaShopApiClient {
    public function __construct(string $baseUrl, string $apiKey, HttpClientInterface $httpClient) {}
    /** @return array<int, array{id: int, name: string, ...}> */
    public function getProducts(): array {}
    public function getCombinations(): array {}
    public function getStockAvailables(): array {}
    public function getCategories(): array {}
}

// CatalogSyncService — writes to local DB
class CatalogSyncService {
    public function __construct(PrestaShopApiClient $apiClient, Connection $connection) {}
    /** @return array{products: int, combinations: int, stock: int, categories: int} */
    public function syncAll(): array {}
    /** @return array{stock: int} */
    public function syncStock(): array {}
}

// CatalogSyncCommand — Symfony Console
class CatalogSyncCommand extends Command {
    protected static $defaultName = 'tpv:catalog:sync';
    // Options: --full (default), --stock-only
}

// SyncCatalogMessage — Messenger DTO
class SyncCatalogMessage {}
```

### Catalog Table Schemas

```sql
CREATE TABLE catalog_product (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    reference VARCHAR(64),
    ean13 VARCHAR(13),
    price DECIMAL(20,6) NOT NULL,
    id_category_default INT UNSIGNED,
    active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_ean13 (ean13),
    INDEX idx_active (active)
) ENGINE=InnoDB;

CREATE TABLE catalog_combination (
    id INT UNSIGNED PRIMARY KEY,
    id_product INT UNSIGNED NOT NULL,
    reference VARCHAR(64),
    ean13 VARCHAR(13),
    price_impact DECIMAL(20,6) NOT NULL DEFAULT 0,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product (id_product),
    INDEX idx_ean13 (ean13)
) ENGINE=InnoDB;

CREATE TABLE catalog_stock (
    id_product INT UNSIGNED NOT NULL,
    id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 0,
    id_shop INT UNSIGNED NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_product, id_product_attribute),
    INDEX idx_product (id_product)
) ENGINE=InnoDB;

CREATE TABLE catalog_category (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    id_parent INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (active)
) ENGINE=InnoDB;
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `PrestaShopApiClient` JSON parsing, pagination, error handling | `MockHttpClient` with canned PS responses; test multi-lang name extraction, pagination loops |
| Unit | `CatalogSyncService` truncate+insert logic | In-memory SQLite; verify row counts after `syncAll()` |
| Unit | `CatalogSyncCommand` option routing | Mock `CatalogSyncService`; assert `syncAll()` vs `syncStock()` called per option |
| Integration | Migration creates all 4 tables with correct schema | Real MySQL (localhost `tpv` DB); `up()` then introspect columns |
| Integration | Full sync against real PS 8.1.7 DB | Seed PS with known products; run command; assert local table counts match |

## Migration / Rollout

1. **Migration #3** creates all 4 catalog tables (no FK to PS tables — these are independent local copies).
2. Add `symfony/http-client` via composer.
3. Uncomment and configure `PS_API_URL` / `PS_API_KEY` in `.env`.
4. Wire services in `config/services.yaml`.
5. Create `config/packages/messenger.yaml` for sync transport.
6. Run `php bin/console tpv:catalog:sync --full` to populate initial data.
7. **Rollback**: Revert migration, remove composer package, delete services, comment out env vars.

## Open Questions

- [ ] Should `catalog_product` include `id_tax_rules_group` and `link_rewrite` for future tax and URL generation? (Recommended: yes, add in migration but don't sync yet)
- [ ] Should Messenger transport use `doctrine` (existing DB) or a separate Redis/AMQP? (Recommended: doctrine for MVP — sync is low-frequency)