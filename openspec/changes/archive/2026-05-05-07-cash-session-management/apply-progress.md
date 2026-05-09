# Apply Progress: 07-cash-session-management

## Mode
Standard (no strict TDD config detected)

## Completed Tasks
- [x] 1.1 to 1.5
- [x] 2.1 to 2.7
- [x] 3.1 to 3.7
- [x] 4.1 to 4.4

## Incomplete Tasks
- None

## Verification
- PHPStan: OK (0 errors)
- PHP CS Fixer: applied
- PHPUnit (targeted): `CatalogSyncServiceTest` and `SalesApiIntegrationTest` pass (integration skips when MySQL is unavailable)
- PHPUnit (full suite in this environment): no catalog rollback failures, but blocked by missing `pdo_sqlite` driver in `ProductSearchServiceTest`
- Smoke (`/opt/lampp/bin/php`): `bin/console debug:router` works

## Notes
- Checkout now enforces active cash session and persists real terminal/session ids.
- Cash lifecycle is DB-backed with `terminal`, `session`, `movement`, and simplified `z_report` JSON totals on close.
- Added end-to-end integration coverage for open → checkout (sale movement) → cash_out movement → report/current totals → close difference.
