# Apply Progress: 02-prestashop-module

**Status**: Complete — all 42 tasks across 7 phases implemented.
**Date**: 2026-05-03
**Test Results**: 32 tests, 62 assertions — all passing

---

## Phase 1: Module Scaffold ✅

| Task | Status | Evidence |
|------|--------|----------|
| 1.1 config.xml | ✅ | `modules/tpvconnect/config.xml` — name, version 0.1.0, author VenPosTPV, PS compat 1.7.7.0–8.99.99 |
| 1.2 logo.png | ✅ | `modules/tpvconnect/logo.png` — 48×48 PNG generated with ImageMagick |
| 1.3 tpvconnect.php | ✅ | Main Module class with install/uninstall, hooks, getContent(), SSO, WS key mgmt |
| 1.4 install/sql/install.php | ✅ | SQL CREATE TABLE IF NOT EXISTS for `ps_order_state_tpvconnect` fallback tracking table |
| 1.5 install/install.php | ✅ | TpvConnectInstaller with run(), createOrderStates(), setConfigurationDefaults(), installSql() |

## Phase 2: Custom Order States ✅

| Task | Status | Evidence |
|------|--------|----------|
| 2.1 Pagado en TPV | ✅ | Green #32CD32, paid=1, logable=1, created via ObjectModel |
| 2.2 Devolución TPV | ✅ | Gray #999999, paid=0, logable=0 |
| 2.3 Pendiente sincronización | ✅ | Yellow #FFD700, paid=0, logable=0 |
| 2.4 Multi-language names | ✅ | ES, EN, FR, PT via OrderState->name per lang ID |
| 2.5 Configuration IDs | ✅ | TPV_OS_PAID, TPV_OS_REFUND, TPV_OS_PENDING_SYNC stored in Configuration |
| 2.6 Uninstall preservation | ✅ | uninstallConfiguration() deletes config keys but NOT order states; reinstall reuses stored IDs |

## Phase 3: Admin Configuration ✅

| Task | Status | Evidence |
|------|--------|----------|
| 3.1 configure.tpl | ✅ | Smarty template with HelperForm structure, TPV URL, SSO secret, buttons |
| 3.2 TPV URL validation | ✅ | `filter_var($url, FILTER_VALIDATE_URL)` + `https://` prefix check in getContent() |
| 3.3 SSO secret validation | ✅ | Masked input, min 32 chars validation |
| 3.4 Abrir TPV button | ✅ | Links to `getSsoUrl()` with target="_blank"; also injected via hookDisplayBackOfficeHeader |
| 3.5 translations/es.php | ✅ | Spanish translation strings for all admin labels |
| 3.6 Admin tab | ✅ | Parent "TPV" tab under AdminParentOrders + child "Configuración" tab |

## Phase 4: SSO Authentication ✅

| Task | Status | Evidence |
|------|--------|----------|
| 4.1 SsoToken.php | ✅ | `generate(int $employeeId, int $shopId, string $secret): string` |
| 4.2 Payload format | ✅ | `base64url(json({employee_id, shop_id, timestamp, nonce}))~hmac_sha256` |
| 4.3 Nonce storage | ✅ | `Configuration::updateValue('TPVCONNECT_NONCE_' . $nonce, time())` |
| 4.4 Nonce cleanup | ✅ | `cleanupNonces()` removes entries older than 120s on each generate() call |
| 4.5 verify() | ✅ | `verify(string $token, string $secret): ?array` — HMAC check, timestamp < 60s, nonce replay protection |
| 4.6 BO redirect | ✅ | `hookDisplayBackOfficeHeader()` fetches SSO URL and injects JS button + template |

## Phase 5: Order Creation Endpoint ✅

| Task | Status | Evidence |
|------|--------|----------|
| 5.1 orders.php controller | ✅ | `TpvConnectOrdersModuleFrontController extends ModuleFrontController` |
| 5.2 HMAC auth | ✅ | `verifyHmac()` checks `Authorization: HMAC-SHA256 {signature}` via `hash_hmac('sha256', raw_body, secret)` |
| 5.3 JSON parsing | ✅ | Validates id_customer, id_currency, id_shop, products[], payment_method with existence checks |
| 5.4 Cart creation | ✅ | `new Cart()`, sets customer/currency/shop/addresses, `updateQty()` for each product |
| 5.5 validateOrder | ✅ | Calls `$this->module->validateOrder()` with TPV_OS_PAID state and transaction_id extra var |
| 5.6 JSON responses | ✅ | 201 `{order_id, reference}`, 401 `{error: "Unauthorized"}`, 405 `{error: "Method not allowed"}`, 422 `{error, details}` |
| 5.7 Rate limiting | ✅ | `Tpvconnect::checkRateLimit($ip)` — file-based counter, 60 req/min per IP |

## Phase 6: Webservice Key Management ✅

| Task | Status | Evidence |
|------|--------|----------|
| 6.1 WS key status in tpl | ✅ | configure.tpl shows active/inactive label + key ID |
| 6.2 Generate button handler | ✅ | `submit_tpvconnect_generate_ws_key` handled in getContent() |
| 6.3 Read-only permissions | ✅ | Creates WebserviceKey, inserts GET+HEAD permissions for products, combinations, stock_availables, customers, categories, images, orders |
| 6.4 Store key ID | ✅ | `TPVCONNECT_WS_KEY_ID` Configuration stores only the ID, never the key value |
| 6.5 Display once | ✅ | Key value shown once in confirmation message; stored temporarily in cookie for single display |
| 6.6 Disabled warning | ✅ | Alert block with link to AdminWebservice when `PS_WEBSERVICE` is disabled; button disabled |

## Phase 7: Verification ✅

| Task | Status | Evidence |
|------|--------|----------|
| 7.1 SsoToken unit tests | ✅ | `SsoTokenTest.php` — 10 tests covering generate, verify, HMAC, base64url, nonce replay, expiry |
| 7.2 Install test | ✅ | `InstallLifecycleTest` — verifies Configuration defaults set, secret length ≥32 |
| 7.3 Uninstall test | ✅ | `InstallLifecycleTest` — verifies Configuration keys removed after uninstall |
| 7.4 Admin config test | ✅ | `AdminConfigValidationTest` — URL/https validation, secret length validation |
| 7.5 SSO token format test | ✅ | `SsoTokenTest` — base64url encoding, tilde separator, HMAC validity with known inputs |
| 7.6 Order endpoint test | ✅ | `OrderEndpointTest` — payload structure, error response structures, rate limit key format |

---

## Files Created

| File | Lines | Purpose |
|------|-------|---------|
| `modules/tpvconnect/config.xml` | 23 | Module metadata |
| `modules/tpvconnect/logo.png` | binary | 48×48 module icon |
| `modules/tpvconnect/tpvconnect.php` | 389 | Main module class |
| `modules/tpvconnect/install/install.php` | 139 | Installer helper |
| `modules/tpvconnect/install/sql/install.php` | 28 | SQL fallback table |
| `modules/tpvconnect/classes/SsoToken.php` | 186 | SSO token generate/verify |
| `modules/tpvconnect/controllers/front/orders.php` | 260 | Order creation endpoint |
| `modules/tpvconnect/views/templates/admin/configure.tpl` | 86 | Admin config Smarty template |
| `modules/tpvconnect/views/templates/admin/bo_header.tpl` | 23 | BO header injection template |
| `modules/tpvconnect/views/css/admin.css` | 14 | Admin styles |
| `modules/tpvconnect/translations/es.php` | 52 | Spanish translations |
| `modules/tpvconnect/upgrade/upgrade-0.1.0.php` | 19 | Upgrade placeholder |
| `modules/tpvconnect/index.php` + 10 subdirs | 11×10 | PrestaShop directory security |
| `modules/tpvconnect/tests/bootstrap.php` | 111 | PHPUnit bootstrap with PS stubs |
| `modules/tpvconnect/tests/phpunit.xml` | 14 | PHPUnit config |
| `modules/tpvconnect/tests/Unit/SsoTokenTest.php` | 162 | SSO token unit tests |
| `modules/tpvconnect/tests/Unit/HmacValidationTest.php` | 85 | HMAC utility tests |
| `modules/tpvconnect/tests/Unit/InstallLifecycleTest.php` | 77 | Install/uninstall tests |
| `modules/tpvconnect/tests/Unit/AdminConfigValidationTest.php` | 67 | Config validation tests |
| `modules/tpvconnect/tests/Unit/OrderEndpointTest.php` | 110 | Order endpoint structure tests |

---

## Deviations from Design

1. **Webservice permissions**: Used `Db::getInstance()->insert('webservice_permission', ...)` instead of `WebserviceKey::setPermissionForAccount()` because the latter method does not exist in all PS 1.7/8 versions. This is a safe, version-agnostic approach.

2. **SQL install file**: Task 1.4 asked for `ps_order_state_tpvconnect` table creation. Created as a custom module tracking table (not a core table modification), satisfying NFR-6.

3. **APCu rate limiting**: Design mentioned APCu; implemented file-based rate limiting (`_PS_CACHE_DIR_`) for broader compatibility (works without APCu extension).

4. **Nonce cleanup**: Implemented as lazy cleanup on each `generate()` call (per design decision), not as a cron job.

---

## Issues Found

- None blocking. One minor compatibility note: `str_starts_with()` was replaced with `strpos(...) === 0` in `orders.php` for PHP 7.x compatibility (PS 1.7.7+).

---

## Test Results

```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.
................................
Time: 00:00.006, Memory: 10.00 MB
OK (32 tests, 62 assertions)
```

---

## Next Steps

1. **Integration testing** on a real PrestaShop 8.x Docker instance to verify:
   - Actual module install/uninstall cycle
   - Real order creation via `validateOrder()`
   - Full SSO redirect flow end-to-end
2. **TPV-side implementation** (future change): `SsoController` to validate tokens and create sessions.
