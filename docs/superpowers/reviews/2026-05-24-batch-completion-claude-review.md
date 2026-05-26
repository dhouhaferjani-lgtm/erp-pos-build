# Claude Adversarial Review: Batch Completion

Date: 2026-05-24
Branch: feat/batch-management-completion
Base: dev @ aa2fc948fbe56e9eac264300a37696a2e4500223

## Strengths

- Vertical defaults are explicit in `config/verticals.php`, with pharmacy/parapharmacy/restaurant/coffee shop set true and other listed verticals false.
- Product creation now preserves explicit `requires_batch_tracking` overrides while defaulting from tenant vertical.
- Goods receipt and POS paths now pass `movement_id` into batch movements, and the new E2E test covers receipt, transfer via issue/receive, FEFO POS allocation, expiry notification, and expired marking.
- No new migrations were added.
- Targeted verification passed: `php artisan test tests/Feature/Inventory/GoodsReceiptTest.php tests/Feature/Inventory/BatchChainE2ETest.php tests/Unit/Services/VerticalConfigServiceTest.php` with 35 passing tests.

## Critical

None found in the inspected diff.

## Important

1. Batch-tracked products can still be received without batch data. `GoodsReceiptService` records aggregate stock first, then only creates batch stock when `isset($batchData[$line->id])` is true, so a default-batch-tracked pharmacy/F&B product can gain sellable aggregate stock with no batch rows. POS then only logs FEFO shortfall and does not block the sale.
   - Files: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`, `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`

2. `DailyExpiryCheck` mutates the global Spatie permission team context and never restores it. In a long-lived queue worker, the final tenant processed can leak into later permission checks/jobs.
   - File: `apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php`

3. The defaulting/backfill applies to all products in batch-heavy verticals, including non-physical/service products. That may be intentional, but it is not a sensible default for service SKUs.
   - Files: `apps/api/app/Modules/Product/Domain/Product.php`, `apps/api/database/seeders/BatchTrackingDefaultsSeeder.php`

## Minor

- The `DailyExpiryCheck` docblock is now stale: it still says notifications use a `users.company_id` filter, but the implementation uses memberships and permission team context.
- `BatchTrackingDefaultsSeeder.php` and `BatchChainE2ETest.php` are untracked in `git status`; they will be omitted unless explicitly added.
- Delivery note `movement_id` wiring was changed but not directly covered by the new E2E test.

## Recommendations

- For batch-tracked products, reject goods receipt without batch data or create an explicit unknown batch policy; do not allow aggregate-only stock.
- Wrap `DailyExpiryCheck` permission-team mutation in save/restore logic, preferably `try/finally`.
- Decide whether defaults/backfill should be limited to physical inventory products.
- Add a rollout note for running `BatchTrackingDefaultsSeeder` in real environments; wiring it into `DatabaseSeeder` only helps demo/seeded databases.

## Assessment

Ready to merge: Not yet.

Reasoning: The targeted tests pass and the main implementation direction is solid, but the aggregate-only receipt gap and permission-team leakage are production risks. The untracked test/seeder files also need to be included before this can be merged.

## Post-Review Actions

- Added receipt validation that rejects missing batch data for batch-tracked physical products before aggregate stock is recorded.
- Restored Spatie's permission team id in `DailyExpiryCheck` with `try/finally`.
- Limited vertical batch-tracking defaults and the backfill seeder to physical products unless explicitly overridden.
- Updated the stale `DailyExpiryCheck` cross-tenant docblock.
- Kept the transfer test on existing batch-aware `issue` and `receive` operations, avoiding the known `StockAdjustmentService::transfer()` batch-preservation bug.
