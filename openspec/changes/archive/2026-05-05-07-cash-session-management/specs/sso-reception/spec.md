# Delta for SSO Reception

## MODIFIED Requirements

### Requirement: SSO Controller

`SsoController` at `GET /sso` SHALL accept a `token` query parameter, delegate validation to `SsoTokenVerifier`, create a PHP native session on success (with `session_regenerate_id()`), persist authenticated user context for downstream APIs, and redirect to `/app/pos`. The PHP session MUST carry `shop_id` and MUST initialize cash-context keys for `terminal_id` and `cash_session_id` so later cash APIs can resolve or update them.

(Previously: the PHP session only needed authentication data for redirect into `/app/pos`.)

#### Scenario: Successful SSO authentication

- GIVEN a valid, unexpired token for a known employee
- WHEN `GET /sso?token={valid_token}` is requested
- THEN a PHP session is created with authenticated user data, `shop_id`, and initialized cash-context keys
- AND the response redirects to `/app/pos`
