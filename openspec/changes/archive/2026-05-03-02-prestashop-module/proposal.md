# Proposal: 02-prestashop-module — tpvconnect PrestaShop Module

## Intent

Build the `tpvconnect` PrestaShop module as the **API provider and SSO gateway** for the independent TPV. The TPV runs on a separate domain/server and communicates with PrestaShop exclusively via HTTP — no shared filesystem, no `require_once`.

## Scope

### In Scope (Phase 1 — MVP)
- Module scaffold with install/uninstall, hook registration
- Admin config page: TPV URL, SSO shared secret, Webservice key provisioning
- SSO token generation: HMAC-SHA256, nonce, 60s TTL, redirect to TPV
- Custom order states on install: "Pagado en TPV", "Devolución TPV", "Pendiente sincronización"
- "Abrir TPV" button in back-office header
- Custom endpoint: `POST /module/tpvconnect/orders` via `validateOrder()`

### Out of Scope (Phase 2+)
- Cache invalidation hooks (`actionValidateOrder`, `actionUpdateQuantity`)
- Product/stock sync push
- Full custom API surface beyond order creation
- Multi-shop advanced config

## Capabilities

### New
- `prestashop-module-scaffold`: Module structure, lifecycle, hooks
- `admin-configuration`: Config form, validation, persistence
- `sso-authentication`: HMAC token, nonce tracking, redirect
- `order-creation-api`: Front controller wrapping `validateOrder()`
- `custom-order-states`: Install-time TPV order states

### Modified
- None

## Approach

**Hybrid API strategy**:
- **Reads** (products, stock, customers): PrestaShop **Webservice API** with JSON output
- **Writes** (orders, SSO): **Custom module front controllers** calling core directly
- **Auth**: Webservice key for reads; shared HMAC secret for custom endpoints

Target **PS 8.x** (`min 1.7.7.0, max 8.99.99`) with forward-compatible structure.

## Affected Areas

| Area | Impact |
|------|--------|
| `modules/tpvconnect/` | New module |
| `src/Controller/App/SsoController.php` | TPV-side token validation |
| `config/services.yaml` | PS API client service |
| `.env` | `PS_API_URL`, `PS_API_KEY`, `TPV_SSO_SECRET` |

## Risks

| Risk | L | Mitigation |
|------|---|------------|
| No local PS for testing | H | Docker (`prestashop/prestashop:8`) |
| Multi-version compat (1.7/8.x/9.x) | M | Hybrid legacy+Symfony structure |
| Webservice JSON input limit on PS 8.x | M | Custom endpoints for all writes |
| SSO token replay | L | Nonce + 60s TTL |

## Rollback Plan

1. Disable/uninstall module in Back Office
2. Delete `modules/tpvconnect/` directory
3. TPV detects 404/401 and falls back to offline queue

## Dependencies

- PrestaShop 8.x (for integration testing)
- TPV app from change `01-project-bootstrap`

## Success Criteria

- [ ] Module installs/uninstalls cleanly on PS 8.x
- [ ] Admin config persists TPV URL, SSO secret, Webservice key
- [ ] SSO redirect generates valid HMAC token and redirects
- [ ] Custom order states exist after install
- [ ] `POST /module/tpvconnect/orders` creates a real PrestaShop order
