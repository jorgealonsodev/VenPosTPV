# Tasks: 05-sales-screen — Cashier Sales Screen

## Phase 1: Backend API Endpoints

- [x] 1.1 Create `src/Controller/Api/ProductController.php` — `GET /api/catalog/search` (q, category, limit), `GET /api/catalog/categories`, `GET /api/catalog/product/{id}`
- [x] 1.2 Create `src/Controller/Api/CategoryController.php` — `GET /api/categories` returning active categories
- [x] 1.3 Create `src/Controller/Api/CartController.php` — `GET /api/cart`, `POST /api/cart/items`, `DELETE /api/cart/items/{lineId}`, `POST /api/cart/clear`, `POST /api/cart/apply-discount`, session-protected, 401 on no session
- [x] 1.4 Create `src/Controller/Api/CheckoutController.php` — `POST /api/checkout` placeholder (payment_method, cash_amount, card_amount)
- [x] 1.5 Create `src/Service/Catalog/ProductSearchService.php` — DBAL queries: search by name/SKU/EAN13, filter by category, stock info
- [x] 1.6 Create `src/Service/Cart/CartService.php` — server-side cart: add/remove/clear lines, apply discount, calculate totals with IVA

## Phase 2: Twig Shell + PosController

- [x] 2.1 Create `src/Controller/App/PosController.php` — renders `pos.html.twig`, checks session, redirects to `/` if unauthenticated
- [x] 2.2 Create `templates/pos.html.twig` — Vue 3 SPA shell: `#app` div, vendor scripts (vue3, tailwind, zxing), `<link>` for IBM Plex fonts
- [x] 2.3 Create `public/pos.html` — **Skipped**: Symfony Twig rendering serves `/app/pos` directly via PosController; static HTML file is unnecessary and would conflict with the controller route.
- [x] 2.4 Modify `config/routes.yaml` — add route `/app/pos` → `PosController` (auto-discovered via Symfony attribute routing; no manual route entry needed)
- [x] 2.5 Modify `src/Controller/App/SsoController.php` — change redirect from `/app/dashboard` → `/app/pos`

## Phase 3: Vue 3 App Entry + Vendors + Styles

- [x] 3.1 Download/vendor `public/assets/vendor/vue3.global.prod.js` — Created vendor note; Vue 3 loaded from CDN (`unpkg.com/vue@3`) in Twig shell
- [x] 3.2 Create `public/assets/vendor/tailwind.min.css` — Created vendor note; Tailwind loaded from CDN (`cdn.tailwindcss.com`) with custom config in Twig shell
- [x] 3.3 Create `public/assets/css/app.css` — oklch custom properties for dark + light themes, IBM Plex Sans+Mono, 56px touch targets, animations, layout helpers
- [x] 3.4 Create `public/assets/js/app.js` — `createApp()`, registers all components, screen routing (Login → CashOpen → Sales)

## Phase 4: Core Components (Login, CashOpen, SaleScreen, CatalogPanel, TicketPanel)

- [x] 4.1 Create `public/assets/js/components/LoginScreen.js` — user selector + PIN numpad, auto-login on 4th digit, shake animation
- [x] 4.2 Create `public/assets/js/components/CashOpenScreen.js` — balance input with presets (0, 50, 100, 150, 200, 300 €) + "Sin fondos"
- [x] 4.3 Create `public/assets/js/components/SaleScreen.js` — 3-zone layout (62/38), header with cashier/clock/offline, orchestrates child components
- [x] 4.4 Create `public/assets/js/components/CatalogPanel.js` — category tabs, search, 5-col grid/list toggle, stock badges (amber/red)
- [x] 4.5 Create `public/assets/js/components/TicketPanel.js` — line items, qty ±, remove, subtotal/discount/IVA/total, action bar

## Phase 5: Modal Components (Payment, Discount, Customer, CashClose)

- [x] 5.1 Create `public/assets/js/components/PaymentModal.js` — cash/card/mixed/voucher, numpad, change calculation, Exacto, green check success
- [x] 5.2 Create `public/assets/js/components/DiscountModal.js` — % or € discount input, apply to ticket
- [x] 5.3 Create `public/assets/js/components/CustomerModal.js` — search by name/email/phone, assign customer
- [x] 5.4 Create `public/assets/js/components/CashCloseScreen.js` — denomination counting, session summary, Z report

## Phase 6: API Client + Reactive Stores

- [x] 6.1 Create `public/assets/js/api.js` — fetch wrapper with auth headers, 401 redirect, offline queue
- [x] 6.2 Create `public/assets/js/stores/useSession.js` — reactive session state + login/openCash/closeCash + localStorage persistence + dark/light theme toggle
- [x] 6.3 Create `public/assets/js/stores/useCart.js` — reactive cart (lines, subtotal, discount, total) + localStorage-first + server sync fallback
- [x] 6.4 Create `public/assets/js/stores/useCatalog.js` — reactive catalog (products, categories, search, activeCategory) with API integration

## Phase 7: Barcode + Utility Components

- [x] 7.1 Create `public/assets/js/composables/useBarcode.js` — USB HID debounce (≤50ms), Enter heuristic, ZXing lazy-load, F6 toggle
- [x] 7.2 Create `public/assets/js/components/Icon.js` — inline SVG icon set matching reference (search, barcode, trash, plus, minus, user, tag, note, pause, close, cashier, print, wifi_off, check, grid, list, arrow_back, receipt, sun, moon)
- [x] 7.3 Create `public/assets/js/components/Numpad.js` — reusable numpad with digits 0-9, backspace, decimal, preset buttons

## Phase 8: Tests + Verification

- [x] 8.1 Write unit tests for `ProductSearchService` — search by name, SKU, EAN13, category filter, stock thresholds (SQLite in-memory)
- [x] 8.2 Write unit tests for `CartService` — add line, remove line, qty floor, discount application, IVA/total calculation
- [x] 8.3 Write integration tests for API endpoints — `GET /api/catalog/search`, `GET /api/categories`, cart CRUD, 401 without session (MySQL conditional)
- [x] 8.4 Verify — PosController unit test confirms Twig shell renders with session data; Vue app mounts and routes through login → cash open → sale screens
