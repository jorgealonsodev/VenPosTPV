# Delta for tpvconnect-module

## MODIFIED Requirements

### R5: Order Creation Endpoint (M5)

Front controller `TpvConnectOrdersModuleFrontController` at `POST /module/tpvconnect/orders` MUST accept HMAC-authenticated requests, create a PrestaShop `Cart`, populate it with received products, call `validateOrder()` with `TPV_OS_PAID` state, and return JSON `{order_id, reference}`. Auth MUST use `Authorization: HMAC-SHA256 {signature}` header over the request body, validated against `TPVCONNECT_SSO_SECRET`. Request MUST include: `id_customer`, `id_currency`, `id_shop`, `products[]` (each with `id_product`, `id_product_attribute`, `quantity`), `payment_method`. When `id_customer=0`, the endpoint MUST substitute `Configuration::get('TPVCONNECT_ANONYMOUS_CUSTOMER_ID')`. Response: HTTP 201 on success, 401 on bad auth, 422 on invalid data, 405 on non-POST.

(Previously: R5 rejected id_customer=0 — endpoint required a valid Customer object via Validate::isLoadedObject())

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

## ADDED Requirements

### R7: Anonymous Customer (M1 extension)

Module install MUST create a PrestaShop `Customer` record named "Consumidor final" with `id_default_group` set to the default customer group, `active=1`, `deleted=0`. The created `id_customer` MUST be stored in `Configuration::updateValue('TPVCONNECT_ANONYMOUS_CUSTOMER_ID', $id)`. On reinstall, if the Configuration value exists and Customer is valid, reuse it; otherwise recreate.

#### Scenario: Anonymous customer created on fresh install

- GIVEN a fresh PrestaShop installation without `TPVCONNECT_ANONYMOUS_CUSTOMER_ID`
- WHEN module installs
- THEN a Customer "Consumidor final" MUST be created and its ID stored in Configuration

#### Scenario: Anonymous customer preserved on reinstall

- GIVEN module was installed (anonymous customer exists in Configuration) then uninstalled
- WHEN module reinstalls
- THEN existing `TPVCONNECT_ANONYMOUS_CUSTOMER_ID` MUST be reused if the Customer still exists; otherwise recreated