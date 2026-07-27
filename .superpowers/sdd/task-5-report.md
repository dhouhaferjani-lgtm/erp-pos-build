# Wave 1 Task 5 report

- Base: `aaf4789bedf608d430f64f6dfe832f9935fbde53`
- Head: `f37ca641b1148938b997db53541270e9e9ae6fbf`
- Commit: `refactor(multiloc): ValidLocationAccess off app(), activate on stock-transfer source (§1 step 4b)`
- Files: `apps/api/app/Rules/ValidLocationAccess.php`, `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php`, `apps/api/tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php`

## TDD evidence

- RED: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php` failed before production edits: restricted source returned 403 instead of the new validation behavior, and the source assertion found `app(` in `ValidLocationAccess.php`.
- GREEN: the same focused path passed with `OK (4 tests, 7 assertions)` after implementation. The restricted case evaluates the rule through Laravel's validator (422 status); the HTTP request preserves the established 403 response contract.

## Verification

- `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php` — pass (4 tests, 7 assertions).
- `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockTransferLocationScopeTest.php` — pass (12 tests, 24 assertions).
- `cd apps/api && ./vendor/bin/phpstan analyse app/Rules/ValidLocationAccess.php app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php` — pass, no errors.
- `cd apps/api && ./vendor/bin/pint app/Rules/ValidLocationAccess.php app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php` — pass.
- `git diff --check` — pass.

## Deviations and concerns

- Activating a FormRequest access rule naturally changes the validation response to 422, while the existing stock-transfer endpoint contract and regression suite require 403 `LOCATION_ACCESS_DENIED`. `StoreStockTransferRequest::failedValidation` therefore maps only the exact `ValidLocationAccess` message for a valid, company-scoped source to the existing 403 response; `bail` ensures malformed or non-company sources are not mapped. Direct validator coverage retains the rule's validation-failure semantics.
- No plan checkboxes were changed.

## Review fix — narrow validation adapter

- Base: `f37ca641b1148938b997db53541270e9e9ae6fbf`
- Head: `dbcd54e228daef91b71b3997af7ecac59774e32b`
- RED: focused rule suite failed 1 test: a restricted inaccessible source plus a missing destination incorrectly returned 403 instead of 422.
- GREEN: focused rule suite passed (5 tests, 9 assertions); scope regression passed (12 tests, 24 assertions).
- Change: map to `LOCATION_ACCESS_DENIED` only when the validator has exactly one key (`source_location_id`) and exactly one matching access message; unrelated destination/line errors retain normal 422 handling.
- Verification: scoped PHPStan passed; focused Pint passed; `git diff --check` passed.
