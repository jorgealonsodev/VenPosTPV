# Exploration: Sales Screen (05-sales-screen)

## Current State

The TPV application has:
- **Symfony 6.4 micro-kernel** with `MicroKernelTrait`, `FrameworkBundle` only
- **Doctrine DBAL** (no ORM), `ConnectionManager` pattern for lazy connections
- **15 DB tables**: 10 core (role, terminal, user, session, movement, order_meta, offline_queue, audit_log, z_report, messenger_messages) + 1 SSO (sso_nonce) + 4 catalog (catalog_product, catalog_combination, catalog_stock, catalog_category)
- **Catalog data verified**: 19 products, 39 combinations, 9 categories synced locally
- **1 API endpoint**: `GET /api/health` — returns DB status
- **1 App controller**: `GET /sso` — HMAC token verification, creates PHP session
- **No frontend**: `assets/` is empty (only `.gitkeep`), `templates/` is empty
- **PHP sessions** already in use (SsoController calls `session_start()`)
- **Twig installed** but no templates exist yet
- **Routing**: attribute-based for `Api/` and `App/` controllers, plus explicit routes in `routes.yaml`
- **No Vite, no build tooling** — static assets would be served from `public/assets/`

### Existing Controller Pattern
Controllers use `#[AsController]` attribute, constructor injection, return `JsonResponse` or `RedirectResponse`. No base controller class.

### Database Schema (catalog tables)
```
catalog_product: id (PK), name, reference, ean13, price, id_category_default, active, synced_at
catalog_combination: id (PK), id_product, reference, ean13, price_impact, synced_at
catalog_stock: (id_product, id_product_attribute) PK, quantity, synced_at
catalog_category: id (PK), name, id_parent, active, synced_at
```

## Affected Areas

- `src/Controller/Api/` — new endpoints: product search, categories, cart/ticket
- `src/Controller/App/` — new controller: sales screen page (`/app/sales`)
- `src/Service/Catalog/` — new `ProductSearchService` for local DB queries
- `src/Service/Cart/` — new cart/ticket management service
- `src/Repository/` — new catalog repositories (or direct DBAL queries)
- `public/assets/` — frontend JS/CSS bundles
- `templates/` — Twig template for sales screen shell
- `config/routes.yaml` — new routes or attribute routing
- `config/services.yaml` — new service registrations
- `assets/` — source files if using Vite build

## Approaches

### Frontend Framework Decision

| Approach | Pros | Cons | Effort |
|----------|------|------|--------|
| **Vue 3 standalone (CDN)** | Reactive, component-based, good devtools, large ecosystem, PRD explicitly mentions it | Requires learning Vue reactivity, larger payload (~40KB gzipped) | Medium |
| **Alpine.js + Tailwind** | Minimal (~15KB), no build step, declarative HTML, fast to prototype | Less structured for complex state, harder to test, limited component reuse | Low |
| **Vue 3 + Vite (built)** | Best DX, tree-shaking, HMR, production-optimized | Requires build step, Node.js dependency (contradicts PRD "no extra services") | Medium-High |

### Serving the Frontend

| Approach | Pros | Cons | Effort |
|----------|------|------|--------|
| **Twig shell + CDN Vue 3** | No build step, Symfony serves everything, simple deployment | CDN dependency (or vendor locally), no HMR | Low |
| **Twig shell + Vite-built assets** | Production-optimized bundles, code splitting | Requires Node.js build step in CI/CD | Medium |
| **Pure static HTML + JS** | Simplest, no Twig needed | Loses Symfony session integration, CSRF handling | Low |

### Cart/Ticket State Management

| Approach | Pros | Cons | Effort |
|----------|------|------|--------|
| **PHP session (server)** | Survives page refresh, secure, works with existing SSO session | Requires API call per cart change, server state | Medium |
| **LocalStorage (client)** | Instant, works offline, no server round-trip | Lost on browser clear, sync complexity, no server visibility | Low |
| **Hybrid: LocalStorage + API sync** | Fast + persistent, offline-capable, server can see active tickets | Most complex, conflict resolution needed | High |

## Recommendation

### 1. **Vue 3 standalone (CDN) for the sales screen**
Vue 3 is the right choice because:
- The sales screen is the **most complex UI** in the entire app — 3 zones, real-time updates, barcode handling, offline queue
- Alpine.js would become unwieldy with the cart state management, keyboard shortcuts, and barcode scanner logic
- Vue 3 CDN mode (`<script src="vue.global.prod.js">`) requires **zero build step** — aligns with PRD's "no Node.js services" philosophy
- Vue 3's Composition API gives clean separation of concerns for the 3 zones
- Can always migrate to Vite build later without changing component logic

### 2. **Twig shell + vendored Vue 3 + Tailwind (no CDN dependency)**
- Single Twig template at `templates/sales/index.html.twig` serves as the SPA shell
- Vendor Vue 3 and Tailwind CSS into `public/assets/vendor/` — no external CDN needed (critical for offline mode)
- Tailwind via CDN build script during development, compiled CSS for production
- Symfony session provides auth context, CSRF token injected into the template

### 3. **Hybrid cart: LocalStorage primary + PHP session fallback**
- **Primary**: Cart lives in `localStorage` — instant add/remove, works fully offline
- **Sync**: On each cart change, POST to `/api/cart/sync` to mirror server-side (for multi-terminal awareness)
- **Recovery**: If localStorage is empty but PHP session has a cart ID, restore from server
- This gives the best UX (instant) while maintaining server visibility

### 4. **API Surface (all under `/api/` prefix)**

| Endpoint | Method | Purpose | Auth |
|----------|--------|---------|------|
| `/api/catalog/search?q=&category=` | GET | Search products by name/SKU/EAN, filter by category | Session |
| `/api/catalog/categories` | GET | List all active categories (tree) | Session |
| `/api/catalog/product/{id}` | GET | Single product detail (with combinations) | Session |
| `/api/cart` | GET | Get current cart from session/localStorage sync | Session |
| `/api/cart/items` | POST | Add/update item in cart | Session |
| `/api/cart/items/{lineId}` | DELETE | Remove item from cart | Session |
| `/api/cart/clear` | POST | Empty the cart | Session |
| `/api/cart/apply-discount` | POST | Apply line or global discount | Session |
| `/api/cart/checkout` | POST | Process payment, create PS order | Session |

### 5. **Barcode scanning: dual approach**
- **USB HID**: Native keyboard input — listen for rapid sequential keystrokes ending with Enter (typical scanner behavior). Debounce: if >50ms between chars, it's manual typing.
- **Camera**: ZXing-js loaded on-demand when user clicks "scan with camera" button. Uses `@zxing/browser`.

### 6. **Layout: CSS Grid**
```
┌──────────────────────────────────────────────┐
│  Header: user, terminal, offline indicator    │
├─────────────────────┬────────────────────────┤
│                     │                        │
│  Catalog (60%)      │  Ticket (40%)          │
│  - Search bar       │  - Line items          │
│  - Category tabs    │  - Quantities          │
│  - Product grid     │  - Discounts           │
│  - Images+name+price│  - Totals              │
│                     │  - [COBRAR] button     │
├─────────────────────┴────────────────────────┤
│  Action bar: client, discount, notes, park   │
└──────────────────────────────────────────────┘
```

## Keyboard Shortcuts (F1-F12)
| Key | Action |
|-----|--------|
| F1 | Focus search bar |
| F2 | Apply discount |
| F3 | Select client |
| F4 | Park ticket |
| F5 | Add note to ticket |
| F6 | Open camera scanner |
| F8 | Remove selected line |
| F9 | Open checkout modal |
| F10 | Void ticket |
| F11 | Open X report |
| F12 | Open Z report |

## Risks

1. **Vue 3 CDN size**: Full Vue 3 is ~40KB gzipped. On slow connections, initial load may exceed PRD's 3s target. Mitigation: vendor locally, use production build, lazy-load ZXing only when needed.
2. **localStorage limits**: ~5-10MB per origin. A cart with 1000 items + offline queue could approach limits. Mitigation: cap cart at reasonable size, use IndexedDB (Dexie) for offline queue instead.
3. **Barcode scanner debounce**: Distinguishing scanner input from manual typing is heuristic. Some scanners send chars faster than 50ms, some slower. Mitigation: make debounce configurable per terminal, add manual "scan mode" toggle.
4. **Cart sync conflicts**: If two terminals modify the same cart (edge case), conflicts arise. Mitigation: cart is terminal-local by design, no cross-terminal cart sharing.
5. **No build step means no TypeScript**: Vue 3 in CDN mode is plain JS. No compile-time type checking. Mitigation: use JSDoc types, add Vite+TS in a future phase.
6. **Tailwind without build**: Using Tailwind CDN means full utility set is loaded (~90KB). Mitigation: use Tailwind CLI to compile only used utilities for production, or use a minimal custom CSS with Tailwind-like classes.

## Ready for Proposal

**Yes** — sufficient information gathered. The recommended approach (Vue 3 standalone + Twig shell + hybrid cart + local catalog API) is well-scoped, aligns with PRD requirements (§5.1, §10, §7.2), and follows existing architecture patterns (attribute controllers, DBAL queries, PHP sessions).
