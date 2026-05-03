# Tasks: 05-sales-screen — Cashier Sales Screen

## Phase 1: Backend API Endpoints

- [ ] 1.1 Create `src/Controller/Api/ProductController.php` — `GET /api/catalog/search` (q, category, limit), `GET /api/catalog/categories`, `GET /api/catalog/product/{id}`
- [ ] 1.2 Create `src/Controller/Api/CategoryController.php` — `GET /api/categories` returning active categories
- [ ] 1.3 Create `src/Controller/Api/CartController.php` — `GET /api/cart`, `POST /api/cart/items`, `DELETE /api/cart/items/{lineId}`, `POST /api/cart/clear`, `POST /api/cart/apply-discount`, session-protected, 401 on no session
- [ ] 1.4 Create `src/Controller/Api/CheckoutController.php` — `POST /api/checkout` placeholder (payment_method, cash_amount, card_amount)
- [ ] 1.5 Create `src/Service/Catalog/ProductSearchService.php` — DBAL queries: search by name/SKU/EAN13, filter by category, stock info
- [ ] 1.6 Create `src/Service/Cart/CartService.php` — server-side cart: add/remove/clear lines, apply discount, calculate totals with IVA

## Phase 2: Twig Shell + PosController

- [ ] 2.1 Create `src/Controller/App/PosController.php` — renders `pos.html.twig`, checks session, redirects to `/` if unauthenticated
- [ ] 2.2 Create `templates/pos.html.twig` — Vue 3 SPA shell: `#app` div, vendor scripts (vue3, tailwind, zxing), `<link>` for IBM Plex fonts
- [ ] 2.3 Create `public/pos.html` — Twig-compiled entry at `/app/pos` (alias to PosController)
- [ ] 2.4 Modify `config/routes.yaml` — add route `/app/pos` → `PosController`
- [ ] 2.5 Modify `src/Controller/App/SsoController.php` — change redirect from `/app/dashboard` → `/app/pos`

## Phase 3: Vue 3 App Entry + Vendors + Styles

- [ ] 3.1 Download/vendor `public/assets/vendor/vue3.global.prod.js` — Vue 3 standalone (Composition API)
- [ ] 3.2 Create `public/assets/vendor/tailwind.min.css` — compile Tailwind CLI with oklch dark theme tokens (--bg, --surface, --green, --amber, --red, --blue)
- [ ] 3.3 Create `public/assets/css/app.css` — oklch custom properties, IBM Plex Sans+Mono fonts, 56px min touch targets, border-radius 10-24px, transitions
- [ ] 3.4 Create `public/assets/js/app.js` — `createApp()`, screen routing (Login → CashOpen → Sales), `app.mount('#app')`

## Phase 4: Core Components (Login, CashOpen, SaleScreen, CatalogPanel, TicketPanel)

- [ ] 4.1 Create `public/assets/js/components/LoginScreen.js` — user selector dropdown + 4-6 digit PIN numpad, auto-login on 4th digit, shake animation on <4 digits on confirm
- [ ] 4.2 Create `public/assets/js/components/CashOpenScreen.js` — balance input with presets (0, 50, 100, 150, 200, 300 €) + "Sin fondos" option
- [ ] 4.3 Create `public/assets/js/components/SaleScreen.js` — 3-zone CSS Grid (62/38): header (cashier, terminal, clock, offline icon) + catalog + ticket + action bar
- [ ] 4.4 Create `public/assets/js/components/CatalogPanel.js` — category tabs, search bar (name/SKU/EAN13), 5-col product grid with name/price/stock cards, low-stock amber / no-stock red badge
- [ ] 4.5 Create `public/assets/js/components/TicketPanel.js` — line items (name, price, qty ±, remove), subtotal/discount/IVA/total display, duplicate-tap increments qty

## Phase 5: Modal Components (Payment, Discount, Customer, CashClose)

- [ ] 5.1 Create `public/assets/js/components/PaymentModal.js` — cash numpad + change display + presets + "Exacto" button, card placeholder, mixed split, voucher code field; change amber when ≥ total, green check on success
- [ ] 5.2 Create `public/assets/js/components/DiscountModal.js` — % or € discount input, apply to ticket
- [ ] 5.3 Create `public/assets/js/components/CustomerModal.js` — search by name/email/phone, assign customer to ticket
- [ ] 5.4 Create `public/assets/js/components/CashCloseScreen.js` — closing balance input, session summary, confirm → logout

## Phase 6: API Client + Reactive Stores

- [ ] 6.1 Create `public/assets/js/api.js` — fetch wrapper with session auth headers, 401 redirect, offline queue
- [ ] 6.2 Create `public/assets/js/stores/useSession.js` — `{ user, cashierName, sessionId, online, screen }` + login/openCash/closeCash methods, persist to localStorage
- [ ] 6.3 Create `public/assets/js/stores/useCart.js` — `{ lines, subtotal, discount, customer, total }` + add/remove/sync methods, localStorage-first + server sync fallback
- [ ] 6.4 Create `public/assets/js/stores/useCatalog.js` — `{ products, categories, search, activeCategory, loading }` + load/search methods

## Phase 7: Barcode + Utility Components

- [ ] 7.1 Create `public/assets/js/composables/useBarcode.js` — USB HID detection with ≤50ms debounce + Enter key heuristic, ZXing camera (lazy-loaded from vendor), F6 toggle, `onScan(cb)` callback
- [ ] 7.2 Create `public/assets/js/components/Icon.js` — inline SVG icon set (shopping-cart, trash, user, barcode, etc.)
- [ ] 7.3 Create `public/assets/js/components/Numpad.js` — reusable numpad: digits 0-9, clear, backspace, optional decimal, preset buttons

## Phase 8: Tests + Verification

- [ ] 8.1 Write unit tests for `ProductSearchService` — search by name, SKU, EAN13, category filter, stock thresholds
- [ ] 8.2 Write unit tests for `CartService` — add line, remove line, qty floor (remove at 0), discount application, IVA/total calculation
- [ ] 8.3 Write integration tests for API endpoints — `GET /api/catalog/search`, `GET /api/categories`, cart CRUD, 401 without session
- [ ] 8.4 Verify — render Twig shell at `/app/pos`, mount Vue app, complete full login → cash open → add product → checkout flow
