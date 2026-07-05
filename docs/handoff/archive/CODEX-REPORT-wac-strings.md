# CODEX Report: WAC Strings and Shared Money Allocation

Date: 2026-07-02
Branch: `fix/wac-cost-adjustment-strings`

## Scope Completed

- Changed `WeightedAverageCostService::recordCostAdjustment()` from `float $additionalCost` to `string $additionalCost` with a `numeric-string` PHPDoc contract.
- Replaced the internal `CurrencyScale::bcformat($additionalCost, ...)` float-compatible boundary with `CurrencyScale::bcformatStrict($additionalCost, ...)`.
- Updated the sole production caller in `StockTransferService` to pass the existing 4dp allocation numeric string directly into WAC, removing the float cast.
- Extracted LandedCostService's proportional allocation/absorber logic into `App\Shared\Domain\ProportionalMoneyAllocator`.
- Refactored `LandedCostService` to consume the allocator while preserving the existing positive-total allocation gate and last-positive-base absorber behavior.
- Added unit coverage for allocator remainder reconciliation, zero-value lines, and single-line allocation.
- Added WAC coverage proving `recordCostAdjustment()` accepts a numeric string and preserves bcmath truncation.

## Verification

- `php artisan test tests/Unit/Shared/ProportionalMoneyAllocatorTest.php`
  - Passed: 3 tests, 4 assertions.
- `php artisan test tests/Unit/Inventory/WeightedAverageCostServiceTest.php --filter cost_adjustment_accepts_numeric_string_without_float_rebase`
  - Passed: 1 test, 3 assertions.
- `php artisan test tests/Unit/Inventory/LandedCostServiceTest.php`
  - Passed: 8 tests, 20 assertions.
- `php artisan test tests/Feature/Inventory/LandedCostBcmathTest.php`
  - Passed: 4 tests, 9 assertions.
- `php artisan test tests/Unit/Inventory/WeightedAverageCostServiceTest.php`
  - Passed: 22 tests, 53 assertions.
- `php artisan test tests/Feature/Inventory/InventoryTransferServiceTest.php --filter transfer_cost`
  - Passed: 3 tests, 4 assertions.
- `./vendor/bin/phpunit tests/Unit/Shared/ProportionalMoneyAllocatorTest.php tests/Unit/Inventory/LandedCostServiceTest.php tests/Feature/Inventory/LandedCostBcmathTest.php tests/Unit/Inventory/WeightedAverageCostServiceTest.php`
  - Passed: 37 tests, 86 assertions.
- `./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php --filter transfer_cost`
  - Passed: 3 tests, 4 assertions.
- `./vendor/bin/phpstan analyse --configuration=phpstan.neon app/Shared/Domain/ProportionalMoneyAllocator.php app/Modules/Inventory/Application/Services/LandedCostService.php app/Modules/Inventory/Application/Services/WeightedAverageCostService.php app/Modules/Inventory/Application/Services/StockTransferService.php tests/Unit/Shared/ProportionalMoneyAllocatorTest.php tests/Unit/Inventory/WeightedAverageCostServiceTest.php tests/Unit/Inventory/LandedCostServiceTest.php`
  - Passed: no errors.
- `./vendor/bin/pint ...changed PHP files...`
  - Passed.

## Notes

- PostgreSQL-backed path tests ran successfully in this sandbox; no DB sandbox block was encountered.
- No full suite was run, per instruction.
- No git commit was created, per instruction.
