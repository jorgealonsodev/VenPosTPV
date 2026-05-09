# Proposal: Cashier Sales Screen

## Intent

Build the primary cashier-facing sales interface where authenticated users search the local catalog, manage an active ticket, and process cash payments. This is the core daily-use screen of the TPV.

## Scope

### In Scope
- Twig shell at `/app/pos` (SSO redirect target)
- Vue 3 CDN SPA with 3-zone CSS Grid (catalog, ticket, action bar)
- Product search by name/SKU/EAN + category filter tabs
- Active ticket: add/remove items, adjust quantities, show subtotals
- Cash payment flow: enter amount, calculate change, confirm
- 9 session-protected API endpoints under `/api/`
- Barcode input: USB HID keyboard wedge + ZXing-js camera scanner
- Responsive layout for desktop/tablet

### Out of Scope
- Offline mode, card payments, receipt printing
- Customer assignment, discount rules, returns
- IndexedDB/Dexie (future), Vite build step

## Capabilities

### New Capabilities
- `sales-screen-ui`: Twig shell, Vue 3 SPA, CSS Grid layout, keyboard shortcuts
- `catalog-query-api`: Search, categories, product detail endpoints
- `cart-management-api`: Cart get/add/remove/clear endpoints
- `checkout-flow`: Cash payment modal and checkout placeholder endpoint
- `barcode-input`: USB HID listener and ZXing-js camera integration

### Modified Capabilities
- `sso-reception`: Update redirect target from `/app/` to `/app/pos`

## Approach

Vue 3 standalone (vendored to `public/assets/vendor/`) mounted on a Twig shell. Cart state in `localStorage` with optional server sync. API controllers use existing DBAL `ConnectionManager` pattern. Barcode via rapid-keystroke detection (USB) and on-demand ZXing-js (camera). Tailwind-like utility CSS vendored or minimal custom build.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Controller/App/` | New | `PosController` serves Twig shell at `/app/pos` |
| `src/Controller/Api/` | New | Catalog and cart endpoint controllers |
| `src/Service/Catalog/` | New | `ProductSearchService` for DBAL queries |
| `src/Service/Cart/` | New | `CartService` for ticket logic |
| `templates/pos/` | New | Twig shell template |
| `public/assets/` | New | Vendored Vue 3, Tailwind CSS, app JS |
| `config/routes.yaml` | Modified | Add `/app/pos` route |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Vue 3 CDN payload size | Med | Vendor minified prod build; lazy-load ZXing |
| Barcode false positives | Med | Configurable debounce; manual scan mode toggle |
| localStorage size limit | Low | Cap cart items; defer offline queue to later change |

## Rollback Plan

Delete new controllers, services, templates, and public assets. Revert `routes.yaml` and SSO redirect. No DB schema changes required.

## Dependencies

- Catalog sync must have run at least once (19 products, 39 combos, 9 cats already synced)

## Success Criteria

- [ ] `/app/pos` loads Vue 3 SPA after SSO login
- [ ] Catalog grid renders with search and category filters
- [ ] Items add/remove to ticket with live totals
- [ ] Cash payment calculates correct change
- [ ] Barcode scan (USB or camera) finds product
