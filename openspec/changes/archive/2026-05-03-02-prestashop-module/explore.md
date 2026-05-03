# Exploration: 02-prestashop-module

## Current State

The TPV application (`/var/www/tpv`) was bootstrapped in change `01-project-bootstrap` with:
- Symfony 6.4 components (routing, http-foundation, http-kernel, console, validator, messenger)
- Doctrine DBAL for the `tpv` schema
- A minimal Kernel with attribute-based routing
- Health check endpoint (`GET /api/health`)
- SSO placeholder controller (`GET /sso`)
- ConnectionManager for the TPV database
- No PrestaShop module exists yet — the `tpvconnect` module is the subject of this change

The PRD (v1.3) defines the architecture as **same-server, subdomain-based** with the TPV loading PrestaShop core via `require_once '/var/www/prestashop/config/config.inc.php'`. However, the task prompt introduces a **critical architectural shift**: the TPV is now **completely independent** from PrestaShop, communicating via **HTTP API only** — no `require_once`, no shared filesystem.

This is a FUNDAMENTAL change from the PRD's architecture and has major implications for the module's design.

## Affected Areas

- **New**: `modules/tpvconnect/` — the entire PrestaShop module (does not exist yet)
- **Modified**: TPV app's SSO controller (`src/Controller/App/SsoController.php`) — currently a placeholder
- **Modified**: TPV app's service layer — needs PrestaShop API client instead of `require_once` bootstrap
- **Modified**: `config/databases.yaml` — PrestaShop connection shifts from `require_once` to HTTP API config
- **Future**: All TPV controllers that currently assume PrestaShop core is available locally

## Architecture Decision: Independent TPV vs PRD's `require_once`

The PRD v1.3 (Section 8.1) explicitly says the TPV loads PrestaShop core via filesystem:
```php
require_once '/var/www/prestashop/config/config.inc.php';
```

The task prompt overrides this: **TPV is completely independent, communicates via HTTP API only**.

This means:
1. The `tpvconnect` module becomes the **API provider** on the PrestaShop side
2. The TPV app becomes an **API consumer** (HTTP client)
3. No `require_once`, no class collisions, no Symfony version conflicts
4. The TPV can run on a **different server** (future-proof)
5. Order creation shifts from `validateOrder()` direct call to HTTP POST to module endpoint

## Approaches for Module API Surface

### Approach 1: PrestaShop Web Service API (Built-in) + Custom Module Endpoints

Use PrestaShop's built-in Webservice API for read operations (products, customers, stock) and custom module front controllers for write operations (order validation, SSO).

- **Pros**: Webservice API is battle-tested, supports CRUD, XML/JSON output, key-based auth, permissions per resource. No custom code for catalog reads.
- **Cons**: Webservice API is XML-first (JSON output added in PS 8.1, but JSON *input* not supported). Cannot create orders via Webservice API (orders are complex, require `validateOrder()`). Still need custom endpoints for order creation and SSO.
- **Effort**: Medium

### Approach 2: Custom Module Front Controllers for Everything

Build all endpoints as module front controllers (`controllers/front/`) returning JSON. The TPV calls these endpoints exclusively.

- **Pros**: Full control over request/response format. JSON in/out. Can use `validateOrder()` directly. Simple authentication (shared secret or API key).
- **Cons**: Reinventing what Webservice API already does well (catalog listing, customer lookup, stock queries). More code to maintain. Need to handle pagination, filtering, etc. manually.
- **Effort**: High

### Approach 3: Hybrid — Webservice API for Reads + Custom Endpoints for Writes (Recommended)

- **Reads** (products, categories, customers, stock): Use PrestaShop's built-in Webservice API with JSON output (`output_format=JSON`).
- **Writes** (order creation, order validation, SSO token): Custom module front controllers that call PrestaShop core directly.
- **Auth**: Webservice API key for reads, shared HMAC-signed token for custom endpoints.

- **Pros**: Best of both worlds. Leages existing, tested API for catalog operations. Custom endpoints only where PrestaShop's API is insufficient (order creation). Clear separation of concerns.
- **Cons**: Two authentication mechanisms to manage. Slightly more complex TPV-side client.
- **Effort**: Medium

## Module Structure (MVP)

Based on PrestaShop documentation and example modules, the minimal viable structure:

```
modules/tpvconnect/
├── tpvconnect.php              # Main module class (extends Module)
├── config.xml                  # Module metadata (auto-generated or manual)
├── config/
│   └── routes.yml              # Symfony admin routes (PS 8+/9+)
├── controllers/
│   ├── front/
│   │   ├── api.php             # TpvConnectApiModuleFrontController — JSON API
│   │   ├── sso.php             # TpvConnectSsoModuleFrontController — SSO redirect
│   │   └── webhook.php         # TpvConnectWebhookModuleFrontController — order sync
│   └── admin/
│       └── AdminTpvConnectController.php  # Admin config page
├── src/
│   ├── Controller/Admin/       # Symfony admin controllers (PS 9+ style)
│   ├── Install/
│   │   └── Installer.php       # DB tables, order states, hooks
│   └── Service/
│       └── SsoTokenGenerator.php
├── views/
│   ├── templates/admin/        # Admin configuration form
│   └── img/                    # Module icon
├── install/
│   └── sql/
│       └── install.sql         # Custom order states
└── upgrade/
    └── upgrade-1.0.1.php       # Migration scripts
```

## SSO Design

The PRD (Section 8.1, 12.1) describes:
1. Back-office "Abrir TPV" button generates a **single-use signed token**
2. Redirect to `https://tpv.tudominio.com/sso?token=...`
3. TPV validates token, authenticates user, starts session

**Recommended flow:**
1. Module stores a shared secret in `Configuration::get('TPVCONNECT_SSO_SECRET')`
2. Token = `base64(json_encode({employee_id, shop_id, timestamp, nonce}))` + HMAC-SHA256 signature
3. Token expires after 60 seconds (timestamp validation)
4. Nonce prevents replay attacks (stored temporarily, checked on use)
5. TPV validates HMAC, extracts employee_id, maps to TPV user, creates session

**Authentication options for TPV → PrestaShop API calls:**
- **Option A**: Webservice API key (built-in, PS manages permissions)
- **Option B**: Shared secret + HMAC for custom endpoints (more flexible, custom implementation)
- **Option C**: JWT (firebase/php-jwt already in TPV deps, familiar to the team)

**Recommendation**: Option A for Webservice reads, Option B for custom write endpoints. JWT is overkill for server-to-server communication.

## Custom Order States

The PRD (Section 8.4) requires three order states:
- **Pagado en TPV** (green, paid=true, logable=true)
- **Devolución TPV** (gray)
- **Pendiente sincronización** (yellow, for offline-synced orders)

Created during module installation via `OrderState` class:
```php
$orderState = new OrderState();
$orderState->name = ['Pagado en TPV', 'Paid in POS', ...];
$orderState->color = '#32CD32';
$orderState->paid = true;
$orderState->logable = true;
$orderState->module_name = 'tpvconnect';
$orderState->save();
Configuration::updateValue('TPV_OS_PAID', $orderState->id);
```

## Hooks Required

| Hook | Purpose | MVP? |
|------|---------|------|
| `actionValidateOrder` | Invalidate TPV cache when order created externally | Phase 2 |
| `actionUpdateQuantity` | Notify TPV of stock changes | Phase 2 |
| `actionProductUpdate` | Notify TPV of product changes | Phase 2 |
| `displayBackOfficeHeader` | Inject "Abrir TPV" button | Yes |
| `actionAdminControllerSetMedia` | Load admin CSS/JS for config page | Yes |

For MVP, only the admin display hooks are needed. Cache invalidation hooks are Phase 2.

## PrestaShop Version Targeting

| Version | Symfony | Module Structure | Webservice JSON | Status |
|---------|---------|-----------------|-----------------|--------|
| **1.7.x** | 4.4 (monolith) | Legacy + Symfony | XML only | Legacy, still widely used |
| **8.x** | 4.4 (monolith) | Legacy + Symfony | JSON output (8.1+) | Current stable |
| **9.x** | 6.4 (components) | Symfony-first | JSON output+input | In development |

**Recommendation**: Target **PS 8.x as primary** (`ps_versions_compliancy: min 1.7.7.0, max 8.x.x`), with forward-compatibility for 9.x. The module structure should work on both 8.x and 9.x by using the hybrid legacy+Symfony approach.

## Multi-Shop Support

PrestaShop's Webservice API supports `associatedShops` per API key. The module should:
1. Store TPV URL per shop (in `Configuration` with shop context)
2. Webservice API keys should be shop-scoped
3. SSO tokens include `shop_id` for validation
4. Module config page shows shop selector if multi-shop is active

## MVP Scope Determination

### MINIMAL viable `tpvconnect` module for Phase 1:

1. **Module class** (`tpvconnect.php`) — install/uninstall, hooks registration
2. **Admin config page** — TPV URL, Webservice API key, SSO secret, "Abrir TPV" button
3. **SSO endpoint** (`controllers/front/sso.php`) — generates signed token, redirects to TPV
4. **Order state creation** — "Pagado en TPV", "Devolución TPV", "Pendiente sincronización"
5. **Webservice API enablement** — ensures Webservice is enabled, creates API key with appropriate permissions

### OUT OF SCOPE for MVP:
- Custom API endpoints for order creation (the TPV will use `require_once` per PRD for Phase 1, shift to HTTP API in Phase 2)
- Cache invalidation hooks
- Webhook endpoints
- Upgrade scripts
- Multi-shop advanced config

## Recommendation

**Approach 3 (Hybrid)** with the following Phase 1 scope:

1. Build the module scaffold with standard PrestaShop structure
2. Admin configuration page with: TPV URL, SSO shared secret, Webservice API key management
3. SSO token generation and redirect flow
4. Custom order states creation on install
5. Webservice API key provisioning for the TPV
6. "Abrir TPV" button in back-office header

**Defer to Phase 2**:
- Custom API endpoints (products sync, stock sync, order creation via HTTP)
- Cache invalidation hooks
- Webhook for order synchronization

This gives the module its core identity (SSO + config + order states) while deferring the complex API surface until the TPV app is ready to consume it.

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| **PRD architecture mismatch**: PRD says `require_once`, task says HTTP API only | HIGH | Clarify with stakeholder. If HTTP API is the direction, the TPV app's bootstrap needs redesign. If `require_once` stays, the module is simpler (just SSO + config + order states). |
| **Webservice API JSON input limitation** (PS 8.x) | MEDIUM | Use XML for Webservice writes, JSON for reads. Or use custom endpoints for writes. |
| **No PrestaShop installation for testing** | HIGH | Use Docker to spin up PS 8.x. Test module installation, SSO flow, order states. |
| **SSO token replay attacks** | MEDIUM | Nonce + timestamp + short TTL (60s). Store used nonces temporarily. |
| **Multi-shop complexity** | LOW | Start with single-shop support. Add multi-shop in Phase 2. |
| **Module tab registration differs between PS 8 and PS 9** | LOW | Use the `$this->tabs` array approach (works on both). |

## Key Decisions Needed

1. **Architecture direction**: Does the TPV use `require_once` (PRD v1.3) or HTTP API only (task prompt)? This is the SINGLE MOST IMPORTANT decision — it determines whether the module needs custom API endpoints or just SSO + config.
2. **Authentication for TPV → PrestaShop**: Webservice API key, shared secret, or JWT?
3. **PrestaShop version range**: 1.7.7+ to 8.x? Or 8.x only?
4. **Order creation in Phase 1**: Via `require_once` (PRD) or via module API endpoint (independent)?
5. **Multi-shop in MVP**: Support from day 1 or defer?

## Ready for Proposal

**Yes, with one critical caveat**: The architecture direction (`require_once` vs HTTP API) MUST be resolved before proceeding. The exploration covers both scenarios, but the proposal and specs will differ significantly based on this decision.

If `require_once` stays (PRD v1.3): Module is simpler — SSO + config + order states + hooks.
If HTTP API only (task prompt): Module needs custom API endpoints for order creation, product sync, stock sync — significantly larger scope.
