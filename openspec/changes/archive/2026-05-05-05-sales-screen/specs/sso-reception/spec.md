# Delta for SSO Reception

## MODIFIED Requirements

### Requirement: SSO Controller

`SsoController` at `GET /sso` SHALL accept a `token` query parameter, delegate validation to `SsoTokenVerifier`, create a PHP native session on success (with `session_regenerate_id()`), and redirect to `/app/pos`. On failure, it SHALL return the appropriate HTTP error from S5.
(Previously: redirect target was `/app/dashboard`)

#### Scenario: Successful SSO authentication

- GIVEN a valid, unexpired token for a known employee
- WHEN GET `/sso?token={valid_token}` is requested
- THEN a PHP session is created with `tpv_user_id`, `tpv_user_name`, and `ps_employee_id`
- AND the session ID is regenerated
- AND the user's `last_login_at` is updated
- AND the response redirects to `/app/pos`

#### Scenario: Missing token parameter

- GIVEN a request to GET `/sso` without a `token` parameter
- WHEN the controller processes the request
- THEN it returns HTTP 400 with message "Missing token"

#### Scenario: Invalid signature returns 401

- GIVEN a token whose HMAC does not verify
- WHEN GET `/sso?token={bad_sig_token}` is requested
- THEN the response is HTTP 401 with message "Invalid token signature"

#### Scenario: Unknown user returns 403

- GIVEN a valid token with an unmapped `employee_id`
- WHEN GET `/sso?token={token}` is requested
- THEN the response is HTTP 403 with message "User not configured"

#### Scenario: Misconfigured secret returns 500

- GIVEN `SSO_SHARED_SECRET` is empty or unset
- WHEN any SSO request is processed
- THEN the response is HTTP 500 with message "SSO configuration error"