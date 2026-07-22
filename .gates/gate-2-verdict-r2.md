# Gate 2 Re-Review (Round 2) — Wave 2 inventory visibility

> Controller-run 2026-07-20 (post Codex fix commits, tip `399cf4df6`). Reviewer: inventory-costing-reviewer (Opus). Round 1: `.gates/gate-2-verdict.md` (REJECT).

Branch: `feat/multi-location` @ `399cf4df6` (worktree `apps/erp.multiloc`)
Scope: `git diff origin/dev...HEAD -- apps/api/app/Modules/Inventory apps/api/database/migrations/tenant apps/web/src/features/{inventory,stock-transfers,purchases} apps/web/src/components/organisms/ProductLocationMatrix`

## Round-1 findings — status

- **[Important] SQLite-instead-of-PostgreSQL for aggregate tests — NOT FIXED (regressed).** The fix (commit `86ceadaeb`) added to each test class:
  `apps/api/tests/Feature/Inventory/StockRebalanceEndpointTest.php:33-36` (and identically in `StockMovementLocationFilterTest.php`, `StockMatrixEndpointTest.php`, `StockThresholdTest.php`, `GoodsReceiptDestinationTest.php:46-49`)
  ```php
  protected array $connectionsToTransact = ['pgsql'];
  protected function beforeRefreshingDatabase(): void { config(['database.default' => 'pgsql']); }
  ```
  This only flips `database.default`. Tenancy's central connection is `central_connection = env('DB_CONNECTION', 'central')` (`apps/api/config/tenancy.php:50`), which under the committed `phpunit.xml:41` stays `sqlite`. Result under the default runner is a split-brain, and the pgsql connection params resolve from `env('DB_DATABASE')`/`env('DB_USERNAME')` = `:memory:`/`root` (`apps/api/config/database.php:92-93`). **Empirically reproduced:** `./vendor/bin/phpunit -c phpunit.xml tests/Feature/Inventory/StockMovementLocationFilterTest.php` → `SQLSTATE[08006] FATAL: role "root" does not exist ... Database: :memory:` (2 errors). So under `php artisan test` / `composer test` / `scripts/preflight.sh` these classes now **ERROR** — strictly worse than round-1's green-on-sqlite.
  They pass **only** when the whole ambient stack is already pgsql — verified: `./vendor/bin/phpunit -c phpunit-pgsql.xml …` → `OK`, and a full-pgsql env run of all four classes → `OK (17 tests, 72 assertions)`, rebalance → `OK (2 tests)`. In that case the `beforeRefreshingDatabase` flip is a pure no-op.
  **No automated gate runs these classes on PostgreSQL:** `backend-test` runs `--testsuite=Unit` only (`.github/workflows/ci.yml:274`); `backend-test-pgsql`'s `--filter` allowlist (`ci.yml:553`) does **not** list any new inventory test; and both jobs skip PR→dev (`ci.yml:185,291`). Grep of `.github/` for the new test names returns nothing. The "verified on PostgreSQL" precondition is therefore still unmet by CI, and the mechanism chosen breaks the default runner. Fix: delete the `beforeRefreshingDatabase`/`connectionsToTransact` hack and instead run these classes under the existing `phpunit-pgsql.xml` (`DB_CONNECTION=pgsql force="true"`, line 57) by adding them to the `backend-test-pgsql` `--filter`, and make that lane (or an equivalent) gate PR→dev.

- **[Minor] Owed `GoodsReceiptDestinationTest` (A-then-B) — FIXED.** `apps/api/tests/Feature/Inventory/GoodsReceiptDestinationTest.php:115-165` genuinely pins the sequence: receive 4→A then 3→B, asserting immutable per-receipt movements (`firstMovement.location_id=A` qty 4, `secondMovement.location_id=B` qty 3, `:150-155`), the PO line follows the latest destination (`latestLine.location_id=B`, `quantity_received=7`, remainder `3`, `:156-158`), and `incoming` re-homes to B while A drops to 0 (`:159-160`), with 2 receipts / 2 lines (`:162-164`). Real models, `RolesAndPermissionsSeeder`, real HTTP for the matrix + out-of-scope 403 case. Passes on pgsql. (Carries the same broken pg-pinning as above.)

- **[Minor] Rebalance severity sort — FIXED.** `StockRebalanceQueryService.php:88-98` now ranks by deficit depth (`min_quantity − available`) instead of `−available`. Display-only, correct.
- **[Minor] Threshold read-then-insert race — FIXED.** `StockThresholdService.php:24-58` wraps in `DB::transaction` + `lockForUpdate` and retries the unique-violation loser (`23000/23505`) as an update.
- **[Minor] Missing tenant scoping — FIXED.** Destination lookup adds a typed `companies.tenant_id` guard (`GoodsReceiptService.php:928-935`, commit `4afba44f8`); threshold write adds product/variant company-scope guards (`StockThresholdService.php:23-33`).
- **[Minor] `incoming` rollup invariant — acknowledged in-code** (`StockMatrixQueryService.php:262-271,423-431`), advisory only.

## Fresh adversarial pass (fix commits `c36464b6…4afba44f8`, `f70c86352`)
- No float on money/quantity introduced anywhere in the scoped diff — added-line grep for `parseFloat|Number(|toFixed|(float)|floatval` on FE and BE returns nothing.
- WAC engine untouched; goods-receipt still routes increments through `recordPurchase`.
- Frontend "align conventions" commit (`f70c86352`) is presentational; no regression to string-payload / `formatQuantity` discipline.
- Backend guards are defense-in-depth and correct; `lockForUpdate()->first()` on an absent row locking nothing is the documented two-writer path the catch block handles.

## New findings
### Critical
None.
### Important
- **[Important] The four inventory Feature test classes ERROR under the committed default `phpunit.xml` and are not gated on PostgreSQL by any CI job** — see round-1 status above. `apps/api/tests/Feature/Inventory/{StockRebalanceEndpointTest,StockMovementLocationFilterTest,StockMatrixEndpointTest,StockThresholdTest,GoodsReceiptDestinationTest}.php` + `.github/workflows/ci.yml:274,553`. Fix: run them via `phpunit-pgsql.xml` and add to the `backend-test-pgsql` filter with a PR→dev trigger.
### Minor
- **[Minor] `StockThresholdService.php:26-33`** — the new product/variant company-scope guard queries `products`/`product_variants` on `company_id` but not the location; low-risk under db-per-tenant. Acceptable.

## What to fix before merge
Stop pinning pg with the in-test `config(['database.default'=>'pgsql'])` flip (it errors under the default sqlite runner and central-connection split-brain); run these five classes under the existing `phpunit-pgsql.xml` and add them to the `backend-test-pgsql` `--filter` on a PR→dev-triggering lane so "verified on PostgreSQL" is actually gated.

VERDICT: REJECT
