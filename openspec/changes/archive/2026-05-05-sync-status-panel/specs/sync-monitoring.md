# Sync Monitoring Specification

## Purpose

5-state sync indicator + detail panel replacing "Sin conexión" badge in SaleScreen header. Shows PS connectivity, data freshness, and queue health. Backend: `SyncStatusService` aggregates PS ping, `sync_log` timestamps, and queue counts at `GET /api/sync/status`. Frontend: polls every 10s.

## Requirements

### R1: Sync Indicator

MUST display 5-state indicator in SaleScreen header (replaces `v-if="!session.online"` badge at line 186). States and priority: `error` (red ⚠) > `offline` (red) > `delayed` (amber) > `syncing` (blue anim) > `synced` (green). SHALL poll `GET /api/sync/status` every 10s.

| Scenario | Given | When | Then |
|---|---|---|---|
| All synced | PS reachable, all freshness OK, no errors | Indicator refreshes | State `synced`, green |
| Stale data | PS reachable, stock >30s / prices >5min / catalog >15min | Indicator refreshes | State `delayed`, amber |
| PS offline | PS ping fails/times out (5s) | Indicator refreshes | State `offline`, red |
| Sync error | `CatalogSyncService` throws exception | Indicator refreshes | State `error`, red ⚠ |
| Active sync | Catalog sync in progress | Indicator refreshes | State `syncing`, blue anim |
| First boot | `sync_log` empty, no `synced_at` data | Indicator renders | State `delayed` (amber, not offline) |

### R2: Detail Panel

Clicking indicator MUST expand panel below header showing: PS status (reachable/unreachable + latency ms), last sync timestamp per type, freshness per type (✓/⚠), outgoing count (`offline_queue status='pending'`), incoming count (`messenger_messages delivered_at IS NULL`), state label. Escape or outside-click closes. Panel MUST NOT block sales interaction.

| Scenario | Given | When | Then |
|---|---|---|---|
| Open | Indicator visible | User clicks indicator | Panel expands with all metrics |
| Close outside | Panel open | Click outside panel | Panel collapses |
| Close Escape | Panel open | Press Escape | Panel collapses |
| Queue counts | 3 pending outgoing, 1 undelivered incoming | Panel opens | Shows outgoing=3, incoming=1 |

### R3: Freshness Rules

MUST compute staleness from `sync_log.synced_at` per type. Thresholds: stock ≤30s, prices ≤5min, catalog ≤15min. Computation: `NOW() - MAX(synced_at)` per type vs threshold. No `sync_log` rows → all types default `stale`.

| Scenario | Given | When | Then |
|---|---|---|---|
| Stock fresh | Last stock sync 20s ago | Evaluate freshness | Stock = `fresh` (≤30s) |
| Stock stale | Last stock sync 45s ago | Evaluate freshness | Stock = `stale` (>30s) |
| Prices fresh | Last prices sync 3min ago | Evaluate freshness | Prices = `fresh` (≤5min) |
| Catalog stale | Last catalog sync 20min ago | Evaluate freshness | Catalog = `stale` (>15min) |
| Cold start | `sync_log` has zero rows | Evaluate freshness | All types = `stale` |

### R4: PS Connectivity

MUST check PS via `PrestaShopApiClient.ping()` (`GET /api?limit=0`). HTTP 200 → reachable + latency (ms). Failure (timeout 5s, 4xx, 5xx, network) → unreachable + latency null. Runs each polling cycle.

| Scenario | Given | When | Then |
|---|---|---|---|
| Reachable | PS API operational | `GET /api?limit=0` → 200 in 320ms | Status=reachable, latency=320ms |
| Unreachable timeout | PS server down | Ping exceeds 5s | Status=unreachable, latency=null, indicator `offline` |
| Unreachable auth | PS API key invalid | `GET /api?limit=0` → 401 | Status=unreachable, latency=null |

### R5: Queue Visibility

MUST show: outgoing = `offline_queue WHERE status='pending'` (label "Pendientes"), incoming = `messenger_messages WHERE delivered_at IS NULL` (label "En proceso"). Refreshed each poll cycle (10s).

| Scenario | Given | When | Then |
|---|---|---|---|
| Non-zero | 5 pending outgoing, 2 undelivered incoming | Panel renders | "Pendientes: 5", "En proceso: 2" |
| Empty | Zero matching rows in both queues | Panel renders | "Pendientes: 0", "En proceso: 0" |
| Processing | 3 pending → 1 processed to `status='done'` | Next poll cycle | "Pendientes: 2" |