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

Front controller `TpvConnectOrdersModuleFrontController` at `POST /module/tpvconnect/orders` MUST accept HMAC-authenticated requests, create a PrestaShop `Cart`, populate it with received products, call `validateOrder()` with `TPV_OS_PAID` state, and return JSON `{order_id, reference}`. Auth MUST use `Authorization: HMAC-SHA256 {signature}` header over the request body, validated against `TPVCONNECT_SSO_SECRET`. Request MUST include: `id_customer`, `id_currency`, `id_shop`, `products[]` (each with `id_product`, `id_product_attribute`, `quantity`), `payment_method`. Response: HTTP 201 on success, 401 on bad auth, 422 on invalid data, 405 on non-POST.

#### Scenario: Successful order creation

- GIVEN a valid HMAC-signed POST with complete cart data
- WHEN endpoint processes the request
- THEN PrestaShop order MUST be created via `validateOrder()` with "Pagado en TPV" state, response MUST be HTTP 201 with `{order_id, reference}`

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