# Design: Cashier Sales Screen

## Technical Approach

Vue 3 standalone (vendored `vue3.global.prod.js`) mounted on Twig shell at `/app/pos`. 3-zone CSS Grid (62/38), localStorage-first cart, 9 JSON API endpoints. Dark oklch theme from React prototype. No Node.js build step.

## Architecture Decisions

### Frontend Framework

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Vue 3 standalone | Reactive, 40KB gz, zero build | **Chosen** |
| Alpine.js | 15KB, but unmanageable for complex POS state | Rejected |
| Vue 3 + Vite | Best DX, but requires Node.js | Rejected |

**Rationale**: CDN mode supports Composition API for 3-zone separation. Migrates to Vite later without rewriting components.

### Cart State

| Option | Tradeoff | Decision |
|--------|----------|----------|
| localStorage | Instant, works offline | **Primary** |
| PHP session | Survives refresh, server-visible | **Sync fallback** |
| IndexedDB | More capacity, slower | Deferred |

**Rationale**: localStorage = zero latency. `POST /api/cart/sync` adds server visibility. Recovery from server if localStorage empty.

### Styling

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Tailwind CLI compiled | ~20KB, dark theme via CSS vars | **Chosen** |
| Tailwind CDN | 90KB, network dependency | Rejected |
| Custom CSS only | No utility leverage | Rejected |

### Auth

**Choice**: Existing PHP session (SsoController sets `$_SESSION`). No new auth layer needed.

## Data Flow

```
Browser                          Server
Vue 3 App                        ┌─────────────────────────┐
┌────────────────────┐           │ PosController            │
│ LoginScreen        │  GET/pos  │ → renders pos.html.twig  │
│ CashOpenScreen     │──────────→│                          │
│ SaleScreen         │           │ ProductController        │
│  ├ CatalogPanel    │  fetch()  │ → GET /api/products      │
│  ├ TicketPanel     │──────────→│ → GET /api/categories    │
│  └ ActionBar       │           │                          │
│                    │  POST/DEL │ CartController            │
│ Stores:            │──────────→│ → POST/DELETE cart       │
│  useCart()         │←─ JSON ──│                          │
│  useSession()      │           │ CheckoutController       │
│  useCatalog()      │           │ → POST /api/checkout     │
└────────────────────┘           └─────────────────────────┘

Barcode: USB HID → <50ms debounce heuristic → search API
         Camera → ZXing-js (lazy loaded) → search API
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `public/pos.html` | Create | Entry HTML (Twig-compiled) |
| `public/assets/vendor/vue3.global.prod.js` | Create | Vue 3 prod bundle |
| `public/assets/vendor/tailwind.min.css` | Create | Compiled Tailwind |
| `public/assets/vendor/zxing.min.js` | Create | ZXing (lazy loaded) |
| `public/assets/js/app.js` | Create | App mount, screen routing |
| `public/assets/js/api.js` | Create | Fetch wrapper, auth headers |
| `public/assets/js/components/*.js` | Create | 10 components (Login, CashOpen, Sale, Catalog, Ticket, Payment, Discount, Customer, CashClose, Icon) |
| `public/assets/js/stores/*.js` | Create | useCart, useSession, useCatalog |
| `public/assets/js/composables/useBarcode.js` | Create | USB HID + camera scanner |
| `public/assets/css/app.css` | Create | oklch vars, transitions |
| `src/Controller/App/PosController.php` | Create | Serves Twig shell, checks session |
| `src/Controller/Api/ProductController.php` | Create | Search/detail/categories |
| `src/Controller/Api/CategoryController.php` | Create | Category list |
| `src/Controller/Api/CartController.php` | Create | Cart CRUD + sync |
| `src/Controller/Api/CheckoutController.php` | Create | Checkout placeholder |
| `src/Service/Catalog/ProductSearchService.php` | Create | DBAL product queries |
| `src/Service/Cart/CartService.php` | Create | Server-side cart logic |
| `templates/pos.html.twig` | Create | Twig shell template |
| `config/routes.yaml` | Modify | Add `/app/pos` route |
| `src/Controller/App/SsoController.php` | Modify | Redirect `/app/` → `/app/pos` |

## Interfaces / Contracts

**API** (all JSON, session-protected):

- `GET /api/products?q=&cat=&limit=50` → `{ data: [{ id, name, reference, ean13, price, stock, category }] }`
- `GET /api/categories` → `{ data: [{ id, name, id_parent, active }] }`
- `GET /api/product/{id}` → product detail with combinations
- `POST /api/cart/add` → `{ product_id, combination_id?, quantity }` → cart snapshot
- `DELETE /api/cart/{lineId}` → cart snapshot
- `POST /api/checkout` → `{ payment_method, cash_amount?, card_amount? }` → `{ order_id, total, change? }`

**Vue 3 Stores** (Composition API reactive):

- `useCart()` — `{ lines, subtotal, discount, customer, total }` + add/remove/sync methods
- `useSession()` — `{ user, cashierName, sessionId, online, screen }` + login/openCash/closeCash
- `useCatalog()` — `{ products, categories, search, activeCategory, loading }` + load/search methods
- `useBarcode()` — `{ onScan(cb), startCamera(), stopCamera(), scanning }` — debounce <50ms

**CSS**: oklch custom properties matching prototype (`--bg`, `--surface`, `--green`, `--amber`, `--red`, `--blue`, etc.). IBM Plex Sans/Mono fonts. Min touch target 56px, border-radius 10-24px.

## Testing Strategy

| Layer | Target | Approach |
|-------|--------|----------|
| Unit | ProductSearchService, CartService | PHPUnit with DBAL mock |
| Integration | API endpoints, session auth | PHPUnit KernelTestCase |
| E2E | Full sales flow | Playwright (future change) |

## Migration / Rollout

No DB schema changes. Rollback = delete new files, revert routes.yaml and SSO redirect.

## Open Questions

- [ ] ZXing-js: vendor locally vs lazy-load CDN on first camera scan?
- [ ] MVP cart: localStorage-only first, add server sync in follow-up?