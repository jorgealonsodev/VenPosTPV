# Design: Sync Status Panel

## Technical Approach

Add a `sync_log` table to persist inbound/outbound sync timestamps, a `SyncStatusService` that aggregates PS ping + queue counts + freshness into a single DTO, and a `SyncStatusController` exposing `GET /api/sync/status`. Frontend replaces the "Sin conexión" badge in `SaleScreen` header with a `<sync-status-indicator>` component that polls every 10s and expands into `<sync-status-panel>` on click.

## Architecture Decisions

### Decision: sync_log table vs config_value for timestamps

**Choice**: Dedicated `sync_log` table
**Alternatives**: `config_value` key-value store
**Rationale**: `sync_log` is queryable by type/direction, supports history, and is extensible. `config_value` is an opaque dump with no schema or indexing.

### Decision: PS ping method

**Choice**: `GET /api?limit=0` via `PrestaShopApiClient::ping()`
**Alternatives**: Full catalog fetch, separate health endpoint
**Rationale**: Returns empty response (~200B), uses existing PS auth, no side effects. Timeout 5s — fail-fast for UI.

### Decision: Polling interval

**Choice**: 10s with exponential backoff on consecutive failures (10→20→40, capped at 60s)
**Alternatives**: WebSocket, server-sent events
**Rationale**: Matches PRD §3 requirements. Simple `setInterval` + retry count. No new infra needed.

### Decision: State derivation — server-side

**Choice**: `SyncStatusService` computes state server-side; frontend receives final state string
**Alternatives**: Frontend derives state from raw data
**Rationale**: Single source of truth. State machine lives in one place. Frontend just renders.

## Data Model

### `sync_log` table

| Column | Type | Description |
|--------|------|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `type` | `ENUM('catalog','stock','prices')` | What was synced |
| `direction` | `ENUM('inbound','outbound')` | In from PS or out to PS |
| `synced_at` | `DATETIME NOT NULL` | Timestamp of successful sync |
| `row_count` | `INT UNSIGNED NULL` | Number of rows synced |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | Row creation time |

Indexes: `idx_type_direction` (`type`, `direction`), `idx_synced_at` (`synced_at`)

One row per (type, direction) — upsert on each sync completion. Always keeps latest timestamp.

## Backend Architecture

### SyncStatusService

```
class SyncStatusService
  __construct(Connection, PrestaShopApiClient)
  getStatus(): SyncStatusDTO
  computeState(SyncStatusDTO): string  // 'ok'|'syncing'|'delayed'|'offline'|'error'
  getPingLatencyMs(): ?int
```

`getStatus()` aggregates:
1. `ping()` → `ps_online` (bool) + `latency_ms` (int|null)
2. `SELECT synced_at FROM sync_log WHERE direction='inbound'` → last sync timestamps per type
3. `SELECT COUNT(*) FROM offline_queue WHERE status='pending'` → pending ops
4. `SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL` → queued messages
5. Freshness from `MAX(synced_at)` on `catalog_product`/`catalog_stock`

`computeState()` applies: offline if `!ps_online`, error if last sync = error, delayed if freshness exceeds thresholds, syncing if queue > 0, else ok.

### SyncStatusController

```
#[Route('/sync/status', methods: ['GET'])]
class SyncStatusController
  __construct(SyncStatusService)
  __invoke(): JsonResponse
```

Response shape:
```json
{
  "state": "ok",
  "ps_online": true,
  "latency_ms": 45,
  "last_syncs": { "catalog": "2026-05-05T10:30:00Z", "stock": "2026-05-05T10:30:00Z" },
  "freshness": { "catalog": 120, "stock": 5 },
  "pending_ops": 0,
  "queued_messages": 0
}
```

### CatalogSyncService changes

After `syncAll()` commit → call `SyncLogRepository::upsert('catalog', 'inbound', $counts)`.
After `syncStock()` commit → call `SyncLogRepository::upsert('stock', 'inbound', $counts)`.

### SyncCatalogMessageHandler changes

On success → log `catalog` inbound type.
On failure → no sync_log write (next poll will still show stale last-sync).

## PS Ping

`PrestaShopApiClient::ping()`: sends `GET {baseUrl}/api?limit=0&output_format=JSON` with 5s timeout. Returns `{online: true, latency_ms: N}` on 200, `{online: false}` on network error or timeout. Uses existing `request()` method with a short timeout override.

## Frontend Architecture

### useSession — new `syncState` field

```js
const session = Vue.reactive({
  // ...existing fields...
  syncState: 'unknown',  // 'unknown'|'ok'|'syncing'|'delayed'|'offline'|'error'
  syncData: null,         // full DTO from /api/sync/status
});
```

New function `startSyncPolling()`: calls `ApiClient.get('/api/sync/status')`, updates `syncState`/`syncData`, implements backoff on failure. Started after `hydrateCashSession()` succeeds.

### SaleScreen — indicator integration

Replace lines 186-188 (the `v-if="!session.online"` block) with:
```html
<sync-status-indicator :state="session.syncState" :data="session.syncData" />
```

### SyncStatusIndicator component (new)

Props: `state` (string), `data` (object|null)
- Renders colored pill: 🟢 ok, 🔵 syncing, 🟡 delayed, 🔴 offline, ⚫ error
- Click toggles `<sync-status-panel>`
- Shows latency when online, "Sin conexión" when offline

### SyncStatusPanel component (new)

Props: `data` (object|null), `open` (boolean)
- Detail panel showing: last sync timestamps, queue counts, latency, freshness per type
- Click-outside or close button to dismiss

## Polling Mechanism

- `setInterval(fetchSyncStatus, 10000)` when on sale screen
- On failure: increment backoff counter (10→20→40→60s cap)
- On success: reset interval to 10s
- `ApiClient.get('/api/sync/status')` — GET, no queue interference (bypasses offline queue for GETs)

## State Machine

```
         ┌─────────────────────┐
         │  ps_online=false?   │──yes──▶ OFFLINE
         └──────────┬──────────┘
               no   │
         ┌──────────▼──────────┐
         │  last_sync=error?   │──yes──▶ ERROR
         └──────────┬──────────┘
               no   │
         ┌──────────▼──────────┐
         │  queue > 0 items?   │──yes──▶ SYNCING
         └──────────┬──────────┘
               no   │
         ┌──────────▼──────────┐
         │  freshness stale?   │──yes──▶ DELAYED
         └──────────┬──────────┘
               no   │
                   OK
```

Freshness thresholds: stock ≤30s, prices ≤5min, catalog ≤15min. If ANY type exceeds its threshold → delayed.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `migrations/Version00000010CreateSyncLogTable.php` | Create | sync_log table migration |
| `src/Service/Sync/SyncLogRepository.php` | Create | DB queries for sync_log |
| `src/Service/Sync/SyncStatusService.php` | Create | Aggregate status + state machine |
| `src/Controller/Api/SyncStatusController.php` | Create | GET /api/sync/status endpoint |
| `src/Service/PrestaShop/PrestaShopApiClient.php` | Modify | Add `ping()` method (5s timeout) |
| `src/Service/Catalog/CatalogSyncService.php` | Modify | Inject SyncLogRepository, upsert after sync |
| `src/MessageHandler/SyncCatalogMessageHandler.php` | Modify | Log sync success via SyncLogRepository |
| `public/assets/js/stores/useSession.js` | Modify | Add syncState, syncData, startSyncPolling |
| `public/assets/js/components/SyncStatusIndicator.js` | Create | Header pill component |
| `public/assets/js/components/SyncStatusPanel.js` | Create | Expandable detail panel |
| `public/assets/js/components/SaleScreen.js` | Modify | Replace offline badge with sync indicator |
| `public/assets/js/api.js` | Modify | Add syncStatus() method |
| `config/services.yaml` | Modify | Wire new services |

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | SyncStatusService state machine | All 5 state transitions with mock DB/ping |
| Unit | SyncLogRepository upsert | INSERT … ON DUPLICATE KEY UPDATE via integration test |
| Unit | PrestaShopApiClient::ping | Mock HTTP client, assert timeout and response |
| Integration | GET /api/sync/status | Kernel test with fixtures |
| Unit | SyncStatusIndicator rendering | Vue component mount with each state |

## Migration / Rollout

Migration `Version00000010` creates `sync_log`. No data migration needed. Rollback: drop table, delete new services, revert SaleScreen to offline badge.

## Open Questions

- [ ] Should `sync_log` keep history rows (append-only) or single-row-per-type (upsert)? Design uses upsert for simplicity; history table would require periodic cleanup.