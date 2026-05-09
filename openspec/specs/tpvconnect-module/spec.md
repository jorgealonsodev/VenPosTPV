# tpvconnect Module Specification

## Purpose

PrestaShop module providing SSO gateway, order creation API, custom order states, and configuration for the independent TPV application. The TPV communicates with PrestaShop exclusively via HTTP — no shared filesystem.

## Requirements

### R1: Module Installation and Lifecycle (M1)

The module `tpvconnect` MUST install via PrestaShop Back Office, registering hooks `displayBackOfficeHeader` and `actionAdminControllerSetMedia`. Uninstall MUST delete `Configuration` values but MUST NOT delete order states (to preserve order history). `config.xml` MUST declare compatibility `min 1.7.7.0, max 8.99.99`. Module class MUST extend `Module` with hybrid legacy+Symfony structure.

#### Scenario: Clean install on PS 8.x

- GIVEN a PrestaShop 8.x installation with Back Office module upload
- WHEN admin uploads and installs `tpvconnect`
- THEN module MUST register hooks, create order states, add admin tab, and persist configuration defaults without errors

#### Scenario: Uninstall preserves order states

- GIVEN `tpvconnect` is installed with orders referencing its custom order states
- WHEN admin uninstalls the module
- THEN configuration values MUST be deleted and order states MUST remain intact

#### Scenario: Reinstall does not duplicate order states

- GIVEN `tpvconnect` was previously installed and then uninstalled
- WHEN admin reinstalls the module
- THEN existing order states MUST be reused (by stored Configuration IDs) without duplication

### R2: Admin Configuration (M2)

Module MUST provide a Back Office configuration page with fields: TPV base URL (`TPVCONNECT_URL`, MUST start with `https://`), SSO shared secret (`TPVCONNECT_SSO_SECRET`, masked input, minimum 32 characters), and a "Abrir TPV" button. Values MUST persist via `Configuration` with shop context. An admin tab under "TPV" MUST be registered.

#### Scenario: Save valid configuration

- GIVEN admin is on tpvconnect config page
- WHEN valid TPV URL (`https://`) and SSO secret (≥32 chars) are submitted
- THEN values MUST persist to `Configuration` and page MUST display confirmation

#### Scenario: Reject invalid URL

- GIVEN admin submits TPV URL without `https://` scheme or empty
- WHEN form is submitted
- THEN validation error MUST be shown and configuration MUST NOT be saved

#### Scenario: Reject short SSO secret

- GIVEN admin submits an SSO secret shorter than 32 characters
- WHEN form is submitted
- THEN validation error MUST be shown indicating minimum length requirement

### R3: SSO Authentication (M3)

"Abrir TPV" button MUST generate a signed token: `base64url(json({employee_id, shop_id, timestamp, nonce}))` + HMAC-SHA256 signature using `TPVCONNECT_SSO_SECRET`. Token TTL MUST be 60 seconds. Nonce MUST be single-use, stored in `Configuration` with auto-expiry. Browser MUST redirect to `{TPVCONNECT_URL}/sso?token={base64_payload}~{hmac_signature}`.

#### Scenario: Valid SSO redirect

- GIVEN an authenticated Back Office employee clicks "Abrir TPV"
- WHEN module generates the token
- THEN browser MUST redirect to TPV URL with token containing valid HMAC, current employee ID, current shop ID, fresh timestamp, and unique nonce

#### Scenario: Token expiry rejection

- GIVEN a token with timestamp older than 60 seconds
- WHEN TPV validates the token
- THEN validation MUST fail regardless of HMAC correctness

#### Scenario: Nonce replay prevention

- GIVEN a nonce that was previously consumed by a successful SSO request
- WHEN a new token containing the same nonce is submitted
- THEN validation MUST reject the token

### R4: Custom Order States (M4)

Module install MUST create three `OrderState` entries with `module_name = 'tpvconnect'`: "Pagado en TPV" (green `#32CD32`, paid=true, logable=true), "Devolución TPV" (gray `#999999`), "Pendiente sincronización" (yellow `#FFD700`). Each MUST have multi-lang names (ES, EN, FR, PT). IDs MUST be stored in `Configuration`: `TPV_OS_PAID`, `TPV_OS_REFUND`, `TPV_OS_PENDING_SYNC`.

#### Scenario: Order states created on install

- GIVEN a fresh PrestaShop installation without tpvconnect order states
- WHEN module installs
- THEN three order states MUST exist with correct colors, flags, multi-lang names, and `module_name = 'tpvconnect'`

#### Scenario: Order creation uses correct state

- GIVEN `TPV_OS_PAID` configuration value exists
- WHEN an order is created via the order creation endpoint
- THEN order state MUST be "Pagado en TPV" with paid and logable flags set

### R5: Order Creation Endpoint (M5)

Front controller `TpvConnectOrdersModuleFrontController` at `POST /module/tpvconnect/orders` MUST accept HMAC-authenticated requests, create a PrestaShop `Cart`, populate it with received products, call `validateOrder()` with `TPV_OS_PAID` state, and return JSON `{order_id, reference}`. Auth MUST use `Authorization: HMAC-SHA256 {signature}` header over the request body, validated against `TPVCONNECT_SSO_SECRET`. Request MUST include: `id_customer`, `id_currency`, `id_shop`, `products[]` (each with `id_product`, `id_product_attribute`, `quantity`), `payment_method`. When `id_customer=0`, the endpoint MUST substitute `Configuration::get('TPVCONNECT_ANONYMOUS_CUSTOMER_ID')`. Response: HTTP 201 on success, 401 on bad auth, 422 on invalid data, 405 on non-POST. Missing anonymous customer configuration on `id_customer=0` MUST return HTTP 422.

#### Scenario: Successful order creation

- GIVEN a valid HMAC-signed POST with complete cart data
- WHEN endpoint processes the request
- THEN PrestaShop order MUST be created via `validateOrder()` with "Pagado en TPV" state, response MUST be HTTP 201 with `{order_id, reference}`

#### Scenario: Anonymous customer order

- GIVEN a valid HMAC-signed POST with `id_customer=0`
- WHEN endpoint processes the request
- THEN `id_customer=0` MUST be replaced with `TPVCONNECT_ANONYMOUS_CUSTOMER_ID`, order MUST be created under the anonymous customer, response HTTP 201

#### Scenario: Missing anonymous customer config

- GIVEN `TPVCONNECT_ANONYMOUS_CUSTOMER_ID` is not configured
- WHEN request arrives with `id_customer=0`
- THEN endpoint MUST return HTTP 422 with error indicating anonymous customer not configured

#### Scenario: Invalid HMAC signature

- GIVEN a POST request with incorrect or missing `Authorization` header
- WHEN endpoint receives it
- THEN response MUST be HTTP 401 with `{error: "Unauthorized"}`

#### Scenario: Invalid cart data

- GIVEN a valid HMAC-signed POST with missing required fields or non-existent product IDs
- WHEN endpoint processes the request
- THEN response MUST be HTTP 422 with validation error details

#### Scenario: Non-POST method rejected

- GIVEN a GET, PUT, or DELETE request to `/module/tpvconnect/orders`
- WHEN endpoint receives it
- THEN response MUST be HTTP 405 with `{error: "Method not allowed"}`

### R7: Anonymous Customer (M1 extension)

Module install MUST create a PrestaShop `Customer` record named "Consumidor final" with `id_default_group` set to the default customer group, `active=1`, `deleted=0`. The created `id_customer` MUST be stored in `Configuration::updateValue('TPVCONNECT_ANONYMOUS_CUSTOMER_ID', $id)`. On reinstall, if the Configuration value exists and Customer is valid, reuse it; otherwise recreate. Install MUST also create a default `Address` for the anonymous customer so `validateOrder()` can assign delivery/invoice addresses.

#### Scenario: Anonymous customer created on fresh install

- GIVEN a fresh PrestaShop installation without `TPVCONNECT_ANONYMOUS_CUSTOMER_ID`
- WHEN module installs
- THEN a Customer "Consumidor final" MUST be created and its ID stored in Configuration

#### Scenario: Anonymous customer preserved on reinstall

- GIVEN module was installed (anonymous customer exists in Configuration) then uninstalled
- WHEN module reinstalls
- THEN existing `TPVCONNECT_ANONYMOUS_CUSTOMER_ID` MUST be reused if the Customer still exists; otherwise recreated

### R8: Refund Endpoint (M7)

Front controller `TpvConnectRefundsModuleFrontController` at `POST /module/tpvconnect/refunds` MUST accept HMAC-authenticated requests, create a PrestaShop `OrderSlip` via `OrderSlip::create()`, change order state to "Devolución TPV" (`TPV_OS_REFUND`), and return JSON `{order_slip_id, refunded_amount, refunded_lines}`.

Request MUST include: `ps_order_id` (int), `refund_type` ("full"|"partial"), `lines` array (each with `id_product`, `id_product_attribute`, `quantity`; required for partial, all order lines for full), `refund_shipping` (bool, default false), `reason` (string, optional).

Auth MUST use `Authorization: HMAC-SHA256 {signature}` header over request body, validated against `TPVCONNECT_SSO_SECRET`, identical to the orders endpoint pattern (R5).

The endpoint MUST use PrestaShop core `OrderSlip` class — direct SQL insertion into `ps_order_slip` is prohibited (NFR-6). Stock restoration via `StockAvailable::updateQuantity()` is handled by `OrderSlip::create()` internally and MUST NOT be duplicated.

Responses: HTTP 201 on success, 401 on bad auth, 422 on invalid data (non-existent order, zero lines, already refunded), 405 on non-POST, 429 on rate limit exceeded.

#### Scenario: Successful full refund slip

- GIVEN valid HMAC-signed POST with `{ps_order_id: 42, refund_type: "full"}`
- WHEN endpoint processes the request
- THEN `OrderSlip::create()` called for all order lines, order state set to `TPV_OS_REFUND`, response HTTP 201 with order_slip_id and refunded_amount

#### Scenario: Successful partial refund slip

- GIVEN valid HMAC-signed POST with `{ps_order_id: 42, refund_type: "partial", lines: [{id_product: 10, quantity: 1}]}`
- WHEN endpoint processes the request
- THEN `OrderSlip::create()` called for specified lines only, response HTTP 201

#### Scenario: Invalid HMAC signature

- GIVEN POST with incorrect or missing `Authorization` header
- WHEN endpoint receives it
- THEN response MUST be HTTP 401 with `{error: "Unauthorized"}`

#### Scenario: Non-existent order

- GIVEN valid HMAC-signed POST with `ps_order_id` that does not exist in PrestaShop
- WHEN endpoint processes the request
- THEN response MUST be HTTP 422 with error details indicating order not found

#### Scenario: Already refunded order

- GIVEN order #42 already has an `OrderSlip` for all its lines
- WHEN a new full refund request arrives
- THEN response MUST be HTTP 422 with `{error: "Order already fully refunded"}`

#### Scenario: Non-POST method

- GIVEN a GET request to `/module/tpvconnect/refunds`
- WHEN endpoint receives it
- THEN response MUST be HTTP 405 with `{error: "Method not allowed"}`

### R6: Webservice Key Management (M6)

Config page MUST display current Webservice key status and provide a button generating a `WebserviceKey` with read-only permissions on: `products`, `combinations`, `stock_availables`, `customers`, `categories`, `images`, `orders`. Key MUST be stored in `Configuration` (`TPVCONNECT_WS_KEY_ID`). If PrestaShop Webservice is disabled, the page MUST show a warning with an enable link.

#### Scenario: Generate API key

- GIVEN PrestaShop Webservice feature is enabled
- WHEN admin clicks "Generate API Key"
- THEN a new `WebserviceKey` MUST be created with read-only permissions on specified resources, and the key value MUST be displayed once (not stored in Configuration, only the ID)

#### Scenario: Webservice disabled warning

- GIVEN PrestaShop Webservice feature is disabled
- WHEN admin visits config page
- THEN a warning MUST be displayed with a link to enable Webservice, and key generation button MUST be disabled

## Non-Functional Requirements

| ID | Requirement |
|----|-------------|
| NFR-1 | All module PHP files MUST use `declare(strict_types=1)` |
| NFR-2 | Module MUST work on PrestaShop 1.7.7+ through 8.x with forward-compatibility for 9.x |
| NFR-3 | SSO secret MUST be stored encrypted in `Configuration`; never logged or exposed in frontend |
| NFR-4 | Order creation endpoint MUST be rate-limited (module-level check, max 60 req/min per IP) |
| NFR-5 | All admin pages MUST follow PrestaShop's HelperForm/HelperList conventions for PS 8.x |
| NFR-6 | Module MUST NOT modify any PrestaShop core tables directly — only via ObjectModel classes |