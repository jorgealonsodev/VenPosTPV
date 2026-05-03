# Exploration: Catalog Sync (04-catalog-sync)

## Current State

The TPV application has:
- **10 DB tables** in the `tpv` schema (role, terminal, user, session, movement, order_meta, offline_queue, audit_log, z_report, messenger_messages) — all operational, **zero catalog tables**.
- **Doctrine DBAL only** (no ORM), with a `ConnectionManager` pattern for lazy connections.
- **Symfony HttpClient is NOT installed** — only Symfony 6.4 components (Routing, HttpFoundation, HttpKernel, Console, DI, Config, YAML, Messenger, Dotenv, FrameworkBundle).
- **PrestaShop module `tpvconnect`** already generates a Webservice API key with read-only permissions for: `products`, `combinations`, `stock_availables`, `customers`, `categories`, `images`, `orders`.
- **Config placeholders** exist in `databases.yaml` and `.env` for `PS_API_URL` and `PS_API_KEY` (commented out).
- **SSO is implemented** (change 03) — the TPV receives authenticated sessions from the PS back-office.

### PrestaShop Webservice API (confirmed via Context7)

| Resource | Endpoint | Key Fields | Notes |
|----------|----------|------------|-------|
| Products | `/api/products?display=full` | `id`, `name` (multi-lang), `price`, `ean13`, `reference`, `active`, `id_category_default`, `id_tax_rules_group`, `link_rewrite`, `associations` | Multi-lang names nested: `<name><language id="1">...</language></name>` |
| Combinations | `/api/combinations?display=full` | `id`, `id_product`, `ean13`, `reference`, `price` (impact), `unit_price_impact`, `associations` (product_option_values, images) | Price is the **impact**, not final price |
| Stock Availables | `/api/stock_availables?display=full` | `id`, `id_product`, `id_product_attribute`, `id_shop`, `quantity` | **Single source of truth** for stock. `product.quantity` is deprecated. |
| Categories | `/api/categories?display=full` | `id`, `id_parent`, `name` (multi-lang), `active`, `associations` (products) | Tree structure via `id_parent` |
| Customers | `/api/customers?display=full` | `id`, `firstname`, `lastname`, `email`, `company`, `dni` (via addresses) | DNI lives in `ps_address`, not `ps_customer` |

**Auth**: HTTP Basic Auth — API key as username, blank password.
**JSON output**: `?output_format=JSON` or `Output-Format: JSON` header (PS 8.1+).
**Pagination**: `?limit=offset,limit` (e.g., `?limit=0,100`).
**Date filtering**: `?date=1&filter[date_upd]=[2024-01-01,2024-12-31]` (requires `date=1` param).
**Performance**: Each request loads full PS core — expect 200-500ms per call even for simple queries. Large catalogs (10K+ products) require pagination.

## Affected Areas

- `config/databases.yaml` — needs PS API parameters uncommented/added
- `.env` / `.env.example` — needs `PS_API_URL` and `PS_API_KEY` env vars
- `config/services.yaml` — needs HttpClient service registration
- `composer.json` — needs `symfony/http-client` (already have symfony/framework-bundle)
- `src/Service/` — new `PsApiClient`, `CatalogSync`, `ProductSearch` services
- `src/Controller/Api/` — new endpoints for product search, stock check, categories
- `src/Repository/` — new catalog repositories (if local storage chosen)
- `migrations/` — new migration for catalog tables (if local storage chosen)
- `modules/tpvconnect/tpvconnect.php` — already generates WS key, may need hooks for cache invalidation

## Approaches

### Option A: Full Sync to Local DB (Batch)
Fetch entire catalog from PS Webservice API and store in local `tpv` tables. Sync on demand (manual trigger or periodic cron).

- **Pros**: Works offline after sync. Fast local search (DBAL queries). No network dependency during sales. Simple to implement.
- **Cons**: Stale data between syncs. Slow initial sync for 10K+ products (many paginated API calls). Misses deletes unless full re-sync.
- **Effort**: Medium
- **Tables needed**: `catalog_product`, `catalog_combination`, `catalog_stock`, `catalog_category`

### Option B: Real-time Proxy (No Local Storage)
Every product search / stock check queries the PS Webservice API in real-time. Cache responses in-process (APCu) or short-lived (Redis).

- **Pros**: Always fresh data. No local catalog tables. No sync logic. Simple architecture.
- **Cons**: **Requires network for every operation**. Search latency 200-500ms per API call. Fails completely if PS is down. Rate limiting concerns.
- **Effort**: Low
- **Tables needed**: None (maybe a simple cache table)

### Option C: Hybrid — Local Cache + Incremental Sync
Full sync on first run, then incremental updates via `date_upd` filter. Module hooks (`actionProductUpdate`, `actionUpdateQuantity`) can optionally push invalidation events.

- **Pros**: Fast local search. Near-real-time stock. Handles deletes (periodic full re-sync). Good offline support.
- **Cons**: Most complex. Needs sync scheduler, conflict resolution, delete detection. Module hooks for push-based updates (requires module changes).
- **Effort**: High
- **Tables needed**: Same as Option A + `catalog_sync_log`

### Option D: Local Cache with TTL (Time-based Invalidation)
Cache individual product/category lookups with a TTL (e.g., 5 min). Background job refreshes popular products.

- **Pros**: Good balance of freshness and speed. No full sync needed. Handles cache misses gracefully.
- **Cons**: Cache stampede on cold start. Complex TTL management. Still needs network for cache misses.
- **Effort**: Medium-High
- **Tables needed**: `catalog_cache` (key-value with TTL)

## Recommendation

**Option A (Full Sync to Local DB) for MVP, with Option C as Phase 2.**

Rationale:
1. **PRD requirement**: "Soportar al menos 10.000 productos sin degradación perceptible" and "Búsqueda de producto < 200 ms". Real-time proxy (Option B) cannot guarantee this — PS Webservice API is too slow for sub-200ms searches.
2. **PRD requirement**: "Modo offline en cliente (Service Worker + IndexedDB)". The backend must serve catalog data to the frontend even when PS API is unreachable.
3. **Architecture consistency**: The TPV already has its own DB with Doctrine DBAL. Adding catalog tables follows the established pattern.
4. **Simplicity for MVP**: Full sync is straightforward — paginate through all products, combinations, stock, and categories. Store locally. Re-sync on demand.
5. **Future path**: Incremental sync (Option C) can be added later when hooks from the PS module are implemented.

### Recommended Table Structure

```sql
-- Product catalog (from /api/products)
CREATE TABLE catalog_product (
    ps_id           INT UNSIGNED PRIMARY KEY,       -- id_product from PS
    name            VARCHAR(255) NOT NULL,           -- default language name
    reference       VARCHAR(64),
    ean13           VARCHAR(13),
    price           DECIMAL(20,6) NOT NULL,          -- base price (tax excl)
    active          TINYINT(1) NOT NULL DEFAULT 1,
    id_category_default INT UNSIGNED,
    id_tax_rules_group  INT UNSIGNED,
    link_rewrite    VARCHAR(255),
    has_combinations TINYINT(1) NOT NULL DEFAULT 0,
    synced_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_reference (reference),
    INDEX idx_ean13 (ean13),
    INDEX idx_active (active)
) ENGINE=InnoDB;

-- Product combinations (from /api/combinations)
CREATE TABLE catalog_combination (
    ps_id               INT UNSIGNED PRIMARY KEY,   -- id_product_attribute
    ps_product_id       INT UNSIGNED NOT NULL,
    reference           VARCHAR(64),
    ean13               VARCHAR(13),
    price_impact        DECIMAL(20,6) NOT NULL DEFAULT 0,
    unit_price_impact   DECIMAL(20,6) NOT NULL DEFAULT 0,
    attribute_names     JSON,                       -- {"Color": "Red", "Size": "M"}
    synced_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ps_product_id) REFERENCES catalog_product(ps_id),
    INDEX idx_product (ps_product_id),
    INDEX idx_ean13 (ean13),
    INDEX idx_reference (reference)
) ENGINE=InnoDB;

-- Stock (from /api/stock_availables) — single source of truth
CREATE TABLE catalog_stock (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ps_product_id       INT UNSIGNED NOT NULL,
    ps_product_attribute_id INT UNSIGNED NOT NULL DEFAULT 0,
    quantity            INT NOT NULL DEFAULT 0,
    id_shop             INT UNSIGNED NOT NULL DEFAULT 1,
    synced_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_attr_shop (ps_product_id, ps_product_attribute_id, id_shop),
    INDEX idx_product (ps_product_id)
) ENGINE=InnoDB;

-- Categories (from /api/categories)
CREATE TABLE catalog_category (
    ps_id           INT UNSIGNED PRIMARY KEY,       -- id_category from PS
    id_parent       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    is_root         TINYINT(1) NOT NULL DEFAULT 0,
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    synced_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_parent (id_parent),
    INDEX idx_active (active)
) ENGINE=InnoDB;

-- Product-Category mapping (many-to-many, from product associations)
CREATE TABLE catalog_product_category (
    ps_product_id   INT UNSIGNED NOT NULL,
    ps_category_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (ps_product_id, ps_category_id),
    INDEX idx_category (ps_category_id)
) ENGINE=InnoDB;
```

### HTTP Client Choice

**Symfony HttpClient** (`symfony/http-client`).

Rationale:
- Already aligned with Symfony 6.4 stack.
- Built-in retry, timeout, and streaming support.
- No extra dependency beyond what the framework already uses.
- Better than raw cURL: typed responses, mockable for tests, PSR-18 compatible.
- Add to `composer.json`: `symfony/http-client:^6.4` + `nyholm/psr7:^1.0` (for PSR-7/18 if needed).

### Multi-Shop Handling

- `stock_availables` includes `id_shop` and `id_shop_group` — filter by the terminal's shop.
- Products/categories are shared across shops by default unless overridden.
- The TPV terminal already has `ps_shop_id` in the `terminal` table — use this to filter stock queries.
- For MVP: assume single-shop. Store `id_shop` in stock records for future multi-shop support.

### Images

**URLs only, no download.**

Rationale:
- PS Webservice API serves images at predictable URLs: `/img/p/{id1}-{id2}.jpg` (product) and `/img/c/{id}.jpg` (category).
- Downloading and storing images locally adds complexity (storage, cleanup, sync).
- The TPV and PS are on the same server — image URLs resolve internally with zero latency.
- Frontend can construct image URLs from product/category IDs without API calls.

## Risks

1. **API performance with large catalogs**: 10K products × paginated requests (100 per page) = 100+ API calls. Each call 200-500ms = 20-50 seconds for full sync. Mitigation: run sync as background job (Symfony Messenger), show progress to user.
2. **Multi-language names**: PS Webservice returns nested multi-lang fields. Need to pick the terminal's language or store all languages. Mitigation: store default language name in `name` column, full multi-lang in a JSON column if needed later.
3. **Price complexity**: PS prices are tax-exclusive in the API. The TPV needs tax-inclusive prices for display. Mitigation: fetch tax rules and calculate, or store both `price_te` and `price_ti`.
4. **Stock accuracy**: If sync runs every 5 minutes, there's a window where TPV shows stale stock. Mitigation: check stock via API at checkout time as a final verification.
5. **Deleted products**: Full sync misses products deleted in PS between syncs. Mitigation: periodic full re-sync (e.g., nightly) or track `date_upd` to detect missing IDs.

## Decisions Needed

1. **Sync trigger**: Manual (admin button) vs automatic (cron every N minutes)? → Recommend: both — manual for immediate refresh + cron for periodic.
2. **Language**: Which language for product names? → Recommend: use the terminal's shop default language, configurable.
3. **Price display**: Tax-inclusive or tax-exclusive? → Recommend: tax-inclusive (what the customer pays), calculate from `price` + `id_tax_rules_group`.
4. **Initial sync scope**: All products or only active? → Recommend: only `active=1` products to reduce sync time.
5. **Sync frequency**: How often for MVP? → Recommend: every 5 minutes for stock, every 30 minutes for products/categories.

## Ready for Proposal

**Yes** — sufficient information gathered to proceed to sdd-propose. The recommended approach (Option A: Full Sync to Local DB) is well-scoped, aligns with PRD requirements, and follows the existing architecture patterns.
