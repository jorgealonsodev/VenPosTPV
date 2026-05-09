# Proposal: Sync Status Panel

## Intent

Make PrestaShop synchronization visible and actionable for cashiers per PRD v2 §3. Replace the basic "Sin conexión" indicator with a 5-state sync status bar and detail panel showing queue health, data freshness, and PS connectivity.

## Scope

### In Scope
- 5-state indicator bar (synced, syncing, delayed, offline, error)
- Detail panel: queue counts (offline_queue + messenger_messages), latency, last sync timestamps
- Freshness rules: stock ≤30s, prices ≤5min, catalog ≤15min
- Polling mechanism: 10s interval via lightweight PS ping
- New `sync_log` table for tracking timestamps
- `SyncStatusController` + `SyncStatusService`

### Out of Scope
- Conflict auto-resolution
- CSV export of sync logs
- Per-product staleness indicators
- Webhooks or push-based updates

## Capabilities

### New Capabilities
- `sync-status-indicator`: Header indicator with 5 states and click-to-expand panel
- `sync-status-api`: Backend endpoint aggregating PS health, queue counts, and freshness
- `sync-log-persistence`: `sync_log` table for tracking inbound/outbound sync timestamps

### Modified Capabilities
- `sales-screen-ui`: Replace header "Sin conexión" with new sync indicator
- `catalog-sync`: Record `synced_at` timestamp in `sync_log` after successful sync

## Approach

Hybrid persistence: new `sync_log` table stores last-success timestamps for inbound/outbound syncs. Queue counts derived from existing `offline_queue` and `messenger_messages` tables. Freshness computed from `MAX(synced_at)` on catalog tables. PS connectivity checked via lightweight `GET /api?limit=0` through `PrestaShopApiClient`.

Backend: `SyncStatusService` aggregates DB queries + PS ping into a single status DTO. `SyncStatusController` exposes `GET /api/sync/status`. Frontend: indicator in SaleScreen header (replacing current offline badge), expandable `SyncStatusPanel` detail component. Polling every 10s via `setInterval`.

## Key Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Timestamp storage | `sync_log` table | Dedicated table with `direction` (in/out), `type` (catalog/stock/prices), and `synced_at` is queryable and extensible. `config_value` is a key-value dump with no history or schema. |
| PS ping | `GET /api?limit=0` | Lightweight (empty response, ~200 bytes), uses existing auth, no side effects. Faster than full catalog probe. |
| Polling interval | 10s | Balances real-time feel with server load. PRD targets <1s visibility; 10s is acceptable for queue/consistency monitoring. |
| Indicator placement | SaleScreen header | Replaces existing "Sin conexión" badge at line 186–188. Keeps visibility without new chrome. |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Controller/Api/` | New | `SyncStatusController` at `GET /api/sync/status` |
| `src/Service/Sync/` | New | `SyncStatusService` + `SyncLogRepository` |
| `migrations/` | New | Migration for `sync_log` table |
| `public/assets/js/components/` | New | `SyncStatusIndicator.js`, `SyncStatusPanel.js` |
| `public/assets/js/components/SaleScreen.js` | Modified | Replace offline badge with `<sync-status-indicator>` |
| `public/assets/js/stores/useSession.js` | Modified | Add `syncState` reactive property |
| `src/Service/Catalog/CatalogSyncService.php` | Modified | Insert `sync_log` row on successful sync |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|----------|
| PS ping adds load | Low | `limit=0` is negligible; 10s interval is conservative |
| Indicator noise | Med | States mapped to color only; no toast spam |
| Stale freshness on first boot | Med | Default to "delayed" until first sync completes |

## Rollback Plan

Drop `sync_log` table. Delete `SyncStatusController`, `SyncStatusService`, and JS components. Revert SaleScreen header to original offline badge. Remove sync_log writes from `CatalogSyncService`. No changes to `offline_queue` or `messenger_messages`.

## Dependencies

- `PrestaShopApiClient` (existing, needs ping method)
- `CatalogSyncService` (to write sync_log on completion)
- `messenger_messages` table (queue count source)
- `offline_queue` table (pending ops count source)
- `useSession` store (to hold reactive sync state)

## Success Criteria

- [ ] Indicator renders 5 correct states based on PS health + queue + freshness
- [ ] Panel shows accurate queue counts and last sync timestamps
- [ ] Catalog sync writes to `sync_log` and freshness updates
- [ ] Polls every 10s without blocking UI
- [ ] Rollback restores original offline badge
