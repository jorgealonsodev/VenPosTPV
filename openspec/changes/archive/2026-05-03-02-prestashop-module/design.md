# Design: tpvconnect PrestaShop Module

## Technical Approach

Build a **standard PrestaShop module** (`tpvconnect`) using the hybrid legacy+Symfony structure required by PS 8.x. The module is the sole PrestaShop-side component — it provides: SSO gateway (HMAC token redirect), order creation endpoint (wrapping `validateOrder()`), custom order states, Webservice key provisioning, and admin configuration. The TPV app (bootstrapped in change `01`) is an **independent HTTP consumer** — no `require_once`, no filesystem coupling.

API strategy is **hybrid**: PrestaShop Webservice API for catalog reads (products, stock, customers); custom module front controller for order writes (needs `validateOrder()`) and SSO.

## Architecture Decisions

| Decision | Choice | Rejected | Rationale |
|----------|--------|----------|-----------|
| API strategy | **Hybrid: WS reads + custom writes** | All-custom endpoints (reinvents WS), WS-only (can't create orders) | WS API handles reads well (pagination, filtering, JSON output 8.1+). Orders require `validateOrder()` which WS can't call. Custom endpoints only where needed |
| Auth for custom endpoints | **HMAC-SHA256 over request body** | JWT (overkill for S2S), Bearer API key (no tamper protection), PS WS key per request (awkward for POST bodies) | Shared secret already exists for SSO. HMAC gives integrity+auth in one step. Same `TPVCONNECT_SSO_SECRET` for both SSO and endpoint auth |
| Module structure | **Legacy+Symfony hybrid** | Pure Symfony controller (PS 9+ only), Pure legacy (deprecated) | PS 8.x still uses legacy `Module` class. `src/` for services. Forward-compatible with PS 9 |
| Order creation input | **JSON body via front controller** | XML via Webservice API, Symfony admin controller | JSON is natural for TPV's HTTP client. Webservice API doesn't support JSON input on PS 8.x. Front controller bypasses WS limitations |
| SSO token format | **base64url(payload)~hmac** | JWT, opaque random+DB lookup | Stateless verification (no DB query). HMAC proves origin. Tilde separator avoids base64 padding issues. Payload is inspectable without secret |
| Admin config | **HelperForm (legacy)** | Symfony 6.4 form with `#[AdminSecurity]` | HelperForm works on PS 1.7.7+ through 8.x. `#[AdminSecurity]` is PS 9+ only. Can migrate later |
| Nonce storage | **Configuration key with timestamp** | DB table, file-based, cache-based | `Configuration` is PS-native, shop-context-aware, and auto-cleaned. Prefix `TPVCONNECT_NONCE_` with TTL cleanup on each generation |

## Data Flow

### SSO Flow: PS Back Office → TPV

```
Admin clicks "Abrir TPV"
         │
         ▼
tpvconnect.php::hookDisplayBackOfficeHeader()
         │
         ├─ Generate nonce (random_bytes(16))
         ├─ Build payload: {employee_id, shop_id, timestamp, nonce}
         ├─ base64url(json(payload)) → encoded_payload
         ├─ HMAC-SHA256(encoded_payload, TPVCONNECT_SSO_SECRET) → signature
         ├─ Store nonce: Configuration::updateValue('TPVCONNECT_NONCE_{nonce}', time())
         │
         ▼
Redirect to {TPVCONNECT_URL}/sso?token={encoded_payload}~{signature}
         │
         ▼
TPV SsoController (future change)
         ├─ Split token on "~"
         ├─ Verify HMAC-SHA256(payload, shared_secret)
         ├─ Check timestamp < 60s
         ├─ Check nonce not reused (HTTP call to PS or local cache)
         ├─ Authenticate employee → create session
         └─ Redirect to TPV dashboard
```

### Order Creation: TPV → PrestaShop

```
TPV POST /module/tpvconnect/orders
  Headers: Authorization: HMAC-SHA256 {signature}
  Body: {id_customer, id_currency, id_shop, products[], payment_method}
         │
         ▼
TpvConnectOrdersModuleFrontController::postProcess()
         │
         ├─ Verify HMAC: hash_hmac('sha256', raw_body, TPVCONNECT_SSO_SECRET)
         ├─ Validate JSON input (required fields, product existence)
         │
         ├─ Create Cart: $cart = new Cart(); add products; $cart->save()
         ├─ Call: $paymentModule->validateOrder(
         │       $cart->id,
         │       Configuration::get('TPV_OS_PAID'),
         │       $cart->getOrderTotal(true),
         │       $payment_method,
         │       null, ['transaction_id' => ...])
         │
         ▼
JSON Response: {order_id, reference}  → HTTP 201
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `modules/tpvconnect/tpvconnect.php` | Create | Main module class: install/uninstall, hook registration, `getContent()` admin config, SSO token generation |
| `modules/tpvconnect/config.xml` | Create | Module metadata: name, version, author, PS compatibility |
| `modules/tpvconnect/logo.png` | Create | Module icon (placeholder, admin display) |
| `modules/tpvconnect/controllers/front/orders.php` | Create | `TpvConnectOrdersModuleFrontController`: HMAC auth, Cart creation, `validateOrder()`, JSON response |
| `modules/tpvconnect/classes/SsoToken.php` | Create | HMAC token generation, payload encoding, nonce management |
| `modules/tpvconnect/views/templates/admin/configure.tpl` | Create | Admin config page: TPV URL, SSO secret (masked), WS key status, "Abrir TPV" button, regenerate secret |
| `modules/tpvconnect/install/install.php` | Create | Order state creation, Configuration defaults, hook registration |
| `modules/tpvconnect/install/sql/install.php` | Create | SQL insert for custom order states as fallback |
| `modules/tpvconnect/upgrade/upgrade-0.1.0.php` | Create | Placeholder upgrade script for future migration |
| `modules/tpvconnect/translations/es.php` | Create | Spanish translation file |

**TPV-side (modified in future change, not this one):**

| File | Action | Description |
|------|--------|-------------|
| `src/Controller/App/SsoController.php` | Future | Replace placeholder with HMAC validation, session creation |
| `.env` | Future | Add `TPV_SSO_SECRET`, `PS_API_URL`, `PS_API_KEY` |
| `config/services.yaml` | Future | Add `PrestaShopApiClient` service |

## Interfaces / Contracts

### SsoToken (classes/SsoToken.php)

```php
namespace TpvConnect;
class SsoToken
{
    public static function generate(int $employeeId, int $shopId, string $secret): string;
    // Returns: base64url(json({employee_id, shop_id, timestamp, nonce}))~hmac_sha256

    public static function verify(string $token, string $secret): ?array;
    // Returns: decoded payload or null (HMAC fail, expired, replayed nonce)
}
```

### Order Creation Request/Response

```
POST /module/tpvconnect/orders
Authorization: HMAC-SHA256 {hex_signature}
Content-Type: application/json

Request:
{
  "id_customer": int,
  "id_currency": int,
  "id_shop": int,
  "products": [
    {"id_product": int, "id_product_attribute": int, "quantity": int}
  ],
  "payment_method": string,
  "transaction_id": ?string
}

Success (201):
{"order_id": int, "reference": string}

Errors:
401 → {"error": "Unauthorized"}
405 → {"error": "Method not allowed"}
422 → {"error": "Validation failed", "details": {...}}
```

### Configuration Keys

| Key | Purpose | Set By |
|-----|---------|--------|
| `TPVCONNECT_URL` | TPV base URL (e.g. `https://tpv.example.com`) | Admin config |
| `TPVCONNECT_SSO_SECRET` | HMAC shared secret (min 32 chars) | Admin config / auto-generated on install |
| `TPV_OS_PAID` | Order state ID: "Pagado en TPV" | Install |
| `TPV_OS_REFUND` | Order state ID: "Devolución TPV" | Install |
| `TPV_OS_PENDING_SYNC` | Order state ID: "Pendiente sincronización" | Install |
| `TPVCONNECT_WS_KEY_ID` | WebserviceKey ID for TPV read-only access | Admin "Generate Key" button |

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `SsoToken::generate()`, `SsoToken::verify()`, HMAC computation, nonce validation | PHPUnit with known inputs/outputs. No PS dependency |
| Unit | Admin config field validation (URL, secret length) | PHPUnit mock of `Configuration` class |
| Integration | Order state creation on install, uninstall cleanup | PS 8.x Docker instance. Install module, verify `ps_order_state` rows |
| Integration | Order creation endpoint end-to-end | PS 8.x Docker. POST to `/module/tpvconnect/orders`, verify `ps_orders` row via `validateOrder()` |
| E2E | Full SSO flow | PS 8.x Docker + TPV app integration test. Click "Abrir TPV", verify redirect URL and token validity |

## Migration / Rollout

1. Upload `tpvconnect/` to `modules/` directory (or zip install via Back Office)
2. Install module from Back Office → creates order states, Configuration defaults
3. Configure TPV URL and generate/regenerate SSO secret
4. Generate Webservice API key for catalog reads
5. Rollback: uninstall from Back Office (deletes Configuration, preserves order states), remove directory

## Open Questions

- [ ] Should nonce cleanup be a cron task or lazy (clean on each token generation)? → Recommend lazy with 120s expiry window
- [ ] Rate limiting strategy for order endpoint — PS hook or module-level check? → NFR-4 specifies module-level (60 req/min/IP), implement with in-memory APCu counter in module class
- [ ] PS 9.x forward compat: migrate admin controller to Symfony when PS 9 ships? → Yes, in Phase 2+; HelperForm works on 1.7.7→8.x