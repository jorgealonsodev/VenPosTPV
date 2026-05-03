# Tasks: tpvconnect PrestaShop Module

## Phase 1: Module Scaffold

- [x] 1.1 Create `modules/tpvconnect/config.xml` with module metadata (name, version, author, PS min/max)
- [x] 1.2 Create `modules/tpvconnect/logo.png` placeholder icon (48x48px)
- [x] 1.3 Create `modules/tpvconnect/tpvconnect.php` extending `Module` with install/uninstall methods, hook registration for `displayBackOfficeHeader` and `actionAdminControllerSetMedia`
- [x] 1.4 Create `modules/tpvconnect/install/sql/install.php` with SQL CREATE TABLE IF NOT EXISTS for order state fallback (ps_order_state_tpvconnect)
- [x] 1.5 Create `modules/tpvconnect/install/install.php` with run() method calling createOrderStates() and setConfigurationDefaults()

## Phase 2: Custom Order States

- [x] 2.1 Implement OrderState creation in `install/install.php::createOrderStates()` — "Pagado en TPV" (green `#32CD32`, paid=1, logable=1)
- [x] 2.2 Add "Devolución TPV" order state (gray `#999999`, paid=0, logable=0)
- [x] 2.3 Add "Pendiente sincronización" order state (yellow `#FFD700`, paid=0, logable=0)
- [x] 2.4 Add multi-language names (ES, EN, FR, PT) for all three order states via OrderStateLang
- [x] 2.5 Store order state IDs in Configuration: `TPV_OS_PAID`, `TPV_OS_REFUND`, `TPV_OS_PENDING_SYNC`
- [x] 2.6 Implement uninstall() to delete Configuration values but preserve order states

## Phase 3: Admin Configuration

- [x] 3.1 Create `modules/tpvconnect/views/templates/admin/configure.tpl` with HelperForm structure
- [x] 3.2 Add TPV URL field (`TPVCONNECT_URL`) with `https://` validation in getContent()
- [x] 3.3 Add SSO secret field (`TPVCONNECT_SSO_SECRET`) as masked input, min 32 chars validation
- [x] 3.4 Add "Abrir TPV" button linking to SSO redirect action
- [x] 3.5 Create `modules/tpvconnect/translations/es.php` with Spanish translation strings for all admin labels
- [x] 3.6 Register admin tab under "TPV" menu in install()

## Phase 4: SSO Authentication

- [x] 4.1 Create `modules/tpvconnect/classes/SsoToken.php` with `generate(int $employeeId, int $shopId, string $secret): string`
- [x] 4.2 Implement payload: base64url(json({employee_id, shop_id, timestamp, nonce}))~hmac_sha256
- [x] 4.3 Implement nonce storage: `Configuration::updateValue('TPVCONNECT_NONCE_{nonce}', time())` on token generation
- [x] 4.4 Implement nonce expiry check in generate(): clean nonces older than 120s on each call
- [x] 4.5 Implement `verify(string $token, string $secret): ?array` for future TPV validation (stateless HMAC check, timestamp < 60s)
- [x] 4.6 Add "Abrir TPV" redirect in tpvconnect.php hookDisplayBackOfficeHeader() using SsoToken::generate()

## Phase 5: Order Creation Endpoint

- [x] 5.1 Create `modules/tpvconnect/controllers/front/orders.php` extending ModuleFrontController
- [x] 5.2 Implement postProcess() with HMAC auth: verify `Authorization: HMAC-SHA256 {signature}` header
- [x] 5.3 Implement JSON body parsing: validate id_customer, id_currency, id_shop, products[], payment_method
- [x] 5.4 Implement Cart creation: new Cart(), add products with Cart::add()
- [x] 5.5 Call `$paymentModule->validateOrder()` with TPV_OS_PAID state and transaction_id
- [x] 5.6 Return JSON `{order_id, reference}` on HTTP 201, proper error JSON on 401/405/422
- [x] 5.7 Add rate limiting: max 60 req/min per IP using module-level counter

## Phase 6: Webservice Key Management

- [x] 6.1 Add Webservice key status display to admin configure.tpl
- [x] 6.2 Implement "Generate API Key" button handler in tpvconnect.php
- [x] 6.3 Create WebserviceKey with read-only permissions: products, combinations, stock_availables, customers, categories, images, orders
- [x] 6.4 Store key ID in `TPVCONNECT_WS_KEY_ID` Configuration (never store key value)
- [x] 6.5 Display key value once (on generation) then show masked reference
- [x] 6.6 Add warning block when PS Webservice is disabled with enable link

## Phase 7: Verification

- [x] 7.1 Write unit tests for SsoToken::generate() and verify() with known HMAC inputs
- [x] 7.2 Test install: verify hooks registered, order states created, Configuration defaults set
- [x] 7.3 Test uninstall: verify Configuration deleted, order states preserved
- [x] 7.4 Test admin config: save valid URL/secret, verify rejection of invalid inputs
- [x] 7.5 Test SSO token format: verify base64url encoding, tilde separator, HMAC validity
- [x] 7.6 Test order endpoint: POST with valid HMAC creates order (HTTP 201), invalid HMAC returns 401, invalid data returns 422, non-POST returns 405