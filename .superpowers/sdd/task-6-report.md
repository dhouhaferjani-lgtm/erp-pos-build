# Wave 1 Task 6 report

- Base: `c311a2090bad32f1ce8702b9490ca7f16c1ddc37`
- Head: `107387ba5f1af0da7a8941cf8ca1252d3bd9a316`
- Commit: `feat(multiloc): scoped + management locations endpoints, drop duplicate controller (§1 step 4c)`

## Files

- `apps/api/app/Modules/Company/routes.php`
- `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/LocationController.php` (deleted; no references found)
- `apps/api/tests/Feature/Company/LocationListEndpointsTest.php`

## TDD evidence

- RED: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/LocationListEndpointsTest.php` — 8 tests failed (10 assertions): the new company routes returned 404 and the existing `/locations` index was unscoped.
- GREEN: same command — `OK (8 tests, 32 assertions)`.

## Verification

- Existing location suites: `./vendor/bin/phpunit tests/Feature/Company/LocationStockPolicyResolverTest.php tests/Feature/Company/LocationScopeResolverTest.php tests/Feature/Inventory/StockManagementTest.php` — `OK (29 tests, 52 assertions)`.
- Combined focused suite: `./vendor/bin/phpunit tests/Feature/Company/LocationListEndpointsTest.php tests/Feature/Company/LocationStockPolicyResolverTest.php tests/Feature/Company/LocationScopeResolverTest.php tests/Feature/Inventory/StockManagementTest.php` — `OK (37 tests, 84 assertions)`.
- PHPStan: `./vendor/bin/phpstan analyse app/Modules/Company/Presentation/Controllers/LocationController.php app/Modules/Company/routes.php` — no errors.
- Pint: focused controller/routes/test run completed; no remaining fixes.
- `git diff --check` — clean.
- `rg` reference proof for `App\\Modules\\Inventory\\Presentation\\Controllers\\LocationController` under `apps/api/app` and `apps/api/routes` — no matches.

## Deviations and concerns

- The new module-agnostic endpoints were added to the registered Company route file (`app/Modules/Company/routes.php`). The existing legacy `/api/v1/locations` remains registered by Inventory presentation routes and continues using the Company controller.
- Absent/suspended membership endpoint tests disable the global company-context middleware after binding the company in the test, so they exercise the resolver's required empty picker payload (`{"data": []}`); with normal middleware, the same users are rejected at company-context authorization before controller execution.
