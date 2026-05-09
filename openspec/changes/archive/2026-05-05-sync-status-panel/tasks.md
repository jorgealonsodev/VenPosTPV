# Tasks: Sync Status Panel

## Phase 1: Foundation / Migration (2 tasks)

- [ ] 1.1 Create `migrations/Version00000010CreateSyncLogTable.php` with table: `id`, `type` ENUM('catalog','stock','prices'), `direction` ENUM('inbound','outbound'), `synced_at` DATETIME, `row_count` INT NULL, `created_at` DATETIME. Add indexes: `idx_type_direction` on (type, direction), `idx_synced_at` on (synced_at).
- [ ] 1.2 Create `src/Service/Sync/SyncLogRepository.php` with `upsert(type, direction, syncedAt, rowCount)` using INSERT ... ON DUPLICATE KEY UPDATE and `getLastSyncByDirection(direction): ?SyncLogEntry`.

## Phase 2: Backend Service (3 tasks)

- [ ] 2.1 Add `ping(): array{online: bool, latency_ms: int|null>` method to `src/Service/PrestaShop/PrestaShopApiClient.php`. Send `GET {baseUrl}/api?limit=0&output_format=JSON` with 5s timeout override. Return `{online: true, latency_ms: N}` on HTTP 200, `{online: false}` on timeout/error.
- [ ] 2.2 Create `src/Service/Sync/SyncStatusService.php` with `getStatus(): SyncStatusDTO` aggregating: ping result, last sync timestamps from `sync_log`, pending offline_queue count, undelivered messenger_messages count, freshness from catalog tables. Include `computeState(SyncStatusDTO): string` implementing 5-state machine: offline→ps_offline, error→last_sync=error, syncing→queue>0, delayed→freshness stale, ok→else.
- [ ] 2.3 Modify `src/Service/Catalog/CatalogSyncService.php`: inject `SyncLogRepository`, call `upsert('catalog', 'inbound', $syncTime, $count)` after `syncAll()` and `upsert('stock', 'inbound', $syncTime, $count)` after `syncStock()`.

## Phase 3: Backend Controller (2 tasks)

- [ ] 3.1 Create `src/Controller/Api/SyncStatusController.php` with `#[Route('/sync/status', methods: ['GET'])]`. Inject `SyncStatusService`, return JsonResponse with: state, ps_online, latency_ms, last_syncs (object by type), freshness (object by type), pending_ops, queued_messages.
- [ ] 3.2 Modify `config/services.yaml`: wire `SyncLogRepository`, `SyncStatusService` with `PrestaShopApiClient` and `Connection` dependencies.

## Phase 4: Frontend Store (2 tasks)

- [ ] 4.1 Modify `public/assets/js/stores/useSession.js`: add `syncState: 'unknown'` and `syncData: null` to session reactive object.
- [ ] 4.2 Add `startSyncPolling()` function: `ApiClient.get('/api/sync/status')` every 10s, update `syncState`/`syncData`, implement backoff (10→20→40→60s cap) on failure, reset to 10s on success. Call after `hydrateCashSession()` succeeds.

## Phase 5: Frontend Components (3 tasks)

- [ ] 5.1 Create `public/assets/js/components/SyncStatusIndicator.js`: props `state`, `data`. Render colored pill: 🟢 ok, 🔵 syncing, 🟡 delayed, 🔴 offline, ⚫ error. Click toggles panel. Show latency when online, "Sin conexión" when offline.
- [ ] 5.2 Create `public/assets/js/components/SyncStatusPanel.js`: props `data`, `open`. Show: PS status + latency, last sync per type, freshness per type (✓/⚠), pending_ops, queued_messages. Escape/click-outside closes.
- [ ] 5.3 Modify `public/assets/js/components/SaleScreen.js` line ~186: replace `v-if="!session.online"` block with `<sync-status-indicator :state="session.syncState" :data="session.syncData" />`.

## Phase 6: Integration / Polish (2 tasks)

- [ ] 6.1 Add `syncStatus()` method to `public/assets/js/api.js`: `ApiClient.get('/api/sync/status')`.
- [ ] 6.2 Modify `src/MessageHandler/SyncCatalogMessageHandler.php`: inject `SyncLogRepository`, call `upsert('catalog', 'inbound', new DateTime(), null)` on successful message handling.

## Phase 7: Testing (3 tasks)

- [ ] 7.1 Unit test `SyncStatusService`: mock DB + ping, assert all 5 state transitions (ok, syncing, delayed, offline, error).
- [ ] 7.2 Integration test `GET /api/sync/status`: kernel test with fixtures, verify response shape matches DTO schema.
- [ ] 7.3 Unit test `SyncStatusIndicator`: mount component with each state prop, assert correct icon/color renders.