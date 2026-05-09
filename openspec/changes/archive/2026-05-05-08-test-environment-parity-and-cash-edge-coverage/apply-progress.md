# Apply Progress — 08-test-environment-parity-and-cash-edge-coverage

## Mode
Standard (Strict TDD disabled)

## Completed Tasks

- [x] 1.1 Refactor ProductSearchService unit tests to remove SQLite dependency.
- [x] 1.2 Re-scope ProductSearchService unit assertions to service behavior contract.
- [x] 1.3 Add DB-driver-free unit fixtures/helpers based on Connection/QueryBuilder doubles.
- [x] 2.1 Create MySQL-gated ProductSearch integration test with explicit skip reason.
- [x] 2.2 Add MySQL integration coverage for search by name/reference/EAN.
- [x] 2.3 Add MySQL integration coverage for category filter, stock joins, and combination stock.
- [x] 2.4 Add MySQL integration coverage for findById and getActiveCategories SQL behavior.
- [x] 3.1 Add duplicate-open-session rejection unit test in CashSessionService.
- [x] 3.2 Add mixed payment unit test asserting drawer movement uses cash_amount and not total.
- [x] 3.3 Add missing cash_amount mixed payment test asserting explicit zero cash impact behavior.
- [x] 4.1 Evaluated need for production guardrails; not required because current behavior matches selected contract.
- [x] 4.2 Added assertions to lock mixed-payment edge behavior.
- [x] 5.1 Ran targeted unit tests for ProductSearch/CashSession/Checkout.
- [x] 5.2 Ran MySQL-gated integration test for ProductSearch SQL semantics.
- [x] 5.3 Ran full PHPUnit suite + PHPStan + PHP CS Fixer dry-run.

## Verification Run

- Targeted:
  - `/opt/lampp/bin/php vendor/bin/phpunit tests/Unit/Service/Catalog/ProductSearchServiceTest.php tests/Unit/Service/Cash/CashSessionServiceTest.php tests/Unit/Service/Checkout/CheckoutServiceTest.php tests/Integration/Service/Catalog/ProductSearchServiceMySqlIntegrationTest.php`
  - Result: pass (with existing project-level warnings/skips)
- Full suite:
  - `/opt/lampp/bin/php vendor/bin/phpunit`
  - Result: pass (143 tests, 301 assertions), with existing warnings/skips
- Static analysis:
  - `/opt/lampp/bin/php vendor/bin/phpstan analyse`
  - Result: pass
- Style:
  - `/opt/lampp/bin/php vendor/bin/php-cs-fixer fix --dry-run --diff`
  - Result: pass (no files to fix)

## Production Code Changes

No production code changes were required; behavior gap was not found for mixed-payment missing `cash_amount` (explicit zero movement path already implemented).

## Files Changed

- `tests/Unit/Service/Catalog/ProductSearchServiceTest.php`
- `tests/Integration/Service/Catalog/ProductSearchServiceMySqlIntegrationTest.php` (new)
- `tests/Unit/Service/Cash/CashSessionServiceTest.php`
- `tests/Unit/Service/Checkout/CheckoutServiceTest.php`
- `openspec/changes/08-test-environment-parity-and-cash-edge-coverage/tasks.md`
