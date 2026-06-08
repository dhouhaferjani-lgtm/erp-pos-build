# Session Prompt — Fix Quantity-Precision Drift in Inventory (properly, once)

You are starting a fresh session to fix a known precision-drift bug in the Inventory module of the AutoERP repo. The bug is well-characterized; your job is to land a clean, end-to-end fix and the regression coverage to keep it fixed.

This prompt is your full brief. Read it top to bottom before touching code.

---

## The bug

Inventory quantities are stored at two different precisions, and the lower one silently truncates the higher one:

- `stock_transfer_lines.quantity` is `decimal(15,4)` — 4 decimal places.
- `StoreStockTransferRequest::rules()` accepts `numeric, min:0.0001`.
- The model casts `quantity` as `decimal:4`.
- BUT `stock_levels.quantity` is `decimal(15,2)` — 2 decimal places.
- AND `stock_movements.quantity` is `decimal(15,2)` — 2 decimal places.
- AND `StockAdjustmentService` declares `private const SCALE = 2;` — every `bcadd` / `bcsub` / `bccomp` inside the service uses 2-decimal precision.

**Concrete consequence:** a user submits a transfer line for `quantity = 7.1234`. The transfer line row stores `7.1234`. When `StockAdjustmentService::issue('7.1234')` runs, it does `bcsub($before, '7.1234', 2)` and writes the result into `stock_levels.quantity` rounded to `7.12`. The audit `stock_movements.quantity` row is also `7.12`. The transfer line still says 7.1234. Net: 0.0034 units of ghost stock that no audit query can reconcile.

This is **pre-existing tech debt** — the 2-decimal scale on `stock_levels` and `stock_movements` predates the transfer feature, predates the WAC service, predates the modern PHPUnit suite. The transfer feature shipped 4-decimal lines because that's what the spec called for. Now the boundary is honest at the API and dishonest at the storage layer.

---

## The canonical fix

Bump the storage precision and the service-layer scale to **4 decimal places** for quantity, repo-wide. Match the highest-precision producer. Do this as one coherent landing — not piecemeal.

### Scope of changes

**Schema (under `apps/api/database/migrations/tenant/`):**

- New migration that alters every relevant column to `decimal(15,4)`. Confirmed in-scope columns:
  - `stock_levels.quantity`
  - `stock_levels.reserved`
  - `stock_levels.min_quantity`
  - `stock_levels.max_quantity`
  - `stock_movements.quantity`
  - `stock_movements.quantity_before`
  - `stock_movements.quantity_after`
  - Anything else you find via grep that's `decimal(N,2)` on a quantity column inside Inventory.
- **Not in scope** (precision boundaries that are correct as-is):
  - Currency columns — those are scaled by `CurrencyScale` per the per-currency rule. Don't touch.
  - Tax-rate columns — already use their own precision.
- PostgreSQL DECIMAL precision widening is non-destructive: existing 2-decimal rows survive as e.g. `7.1200` after the migration. Verify on a copy of a prod-like dataset before merging.

**Service (in `apps/api/app/Modules/Inventory/`):**

- `StockAdjustmentService::SCALE` → `4`.
- Every `bcadd / bcsub / bcmul / bcdiv / bccomp` call in the service that uses the local constant follows automatically; verify by grep.
- `WeightedAverageCostService` — check the WAC math. `cost_price` is currency-scaled (per the existing `CurrencyScaleResolverInterface`); the quantity sides need 4-decimal alignment.
- `LandedCostService`, `GoodsReceiptService`, `StockReservationService`, `InventoryCountingService`, `StockTransferService`, `FraudTriggeredCountingService`, `CountingReconciliationService` — grep each for `decimal:2` casts and hardcoded `'2'` arguments to bcmath; fix.

**Models:**

- `StockLevel`, `StockMovement`, `StockReservation`, `InventoryCounting*` — cast `quantity` and friends as `decimal:4`.

**Tests:**

- Every assertion that reads back a quantity field will currently read `'7.1200'` (the cast widens to 4 decimals after the migration). Sweep the inventory feature suite:
  - `tests/Feature/Inventory/StockManagementTest.php`
  - `tests/Feature/Inventory/InventoryTransferServiceTest.php`
  - `tests/Feature/Inventory/StockMovementTest.php`
  - `tests/Feature/Inventory/InventoryEventsTest.php`
  - `tests/Feature/Inventory/InventoryTenantIsolationTest.php`
  - `tests/Feature/Inventory/StockAdjustmentEventsTest.php`
  - `tests/Feature/Inventory/GoodsReceiptTest.php`
  - `tests/Feature/Inventory/BlindCountingTest.php`
  - `tests/Feature/Inventory/ActivateDraftCountingTest.php`
  - `tests/Feature/Inventory/BatchChainE2ETest.php`
  - `tests/Feature/Inventory/ReconciliationTest.php`
  - `tests/Unit/Inventory/*Test.php`
  - The transfer-feature golden test: `test_complete_with_transfer_cost_recomputes_company_wide_wac` — expects `'5.5000'` (4 decimals because that's `cost_price`); leave it.
- New regression test: `test_quantity_precision_survives_round_trip_through_stock_levels` — submit a transfer with `quantity = '7.1234'`, complete it, assert the line, the source `stock_levels.quantity`, the destination `stock_levels.quantity`, and the two `stock_movements.quantity` rows all read back as `'7.1234'`. This is the test that would have caught the bug.

**Frontend:**

- `apps/web/src/features/inventory/*` and `apps/web/src/features/stock-transfers/*` — anywhere a quantity is formatted for display, confirm it uses the 4-decimal scale. Most formatters route through `formatCurrency` for money (wrong scale) or `formatQuantity` (if it exists). Add `formatQuantity(value, scale = 4)` to `apps/web/src/lib/format.ts` if not present.
- Forms — confirm `step="0.0001"` and `min="0.0001"` on quantity inputs.

**Lint / regression-prevention:**

- Add a PHPStan custom rule (or a Pest/PHPUnit architecture test) that asserts:
  - No file under `app/Modules/Inventory/` declares `const SCALE = 2` or any constant with value `2` whose name contains `SCALE`.
  - No bcmath call inside that module passes the literal `2` as the scale argument.
- Add a per-module test (or extend the chokepoint-completeness gate at §14.3) that asserts the `decimal:N` cast on every Inventory model's quantity property matches the migration column scale. If you find a smaller-scope way to express this — e.g. a Pest architecture test — prefer that.

### Order of operations

This is a destructive-feeling change that's actually non-destructive in PostgreSQL. Still, sequence carefully:

1. Branch from `dev`. Worktree at `apps/erp.inventory-precision-fix/` recommended.
2. Add the migration. Run it locally against a seeded DB. Verify rows survive (`SELECT quantity FROM stock_levels` reads `7.1200` rather than `7.12`).
3. Update the service constant + model casts. Run the inventory suite; expect a flood of red.
4. Sweep test assertions in one commit per test file. Don't bulk-edit blindly; each test that changes from `'7.12'` to `'7.1200'` needs a visual confirmation that the new value is correct (not just a `sed` replacement that silently changed math).
5. Add the regression test from the list above.
6. Add the lint / architecture-test guard.
7. Run `./scripts/preflight.sh` clean.
8. Open PR to `dev`. PR body: link to this prompt; explain the migration is a precision widening (non-destructive); explain the regression test.

### What to NOT do

- Don't touch currency columns. They're separately handled by `CurrencyScale`.
- Don't introduce a per-product variable scale. That's a real feature (kg vs items vs liquids) but it's a separate spec.
- Don't refactor `StockAdjustmentService` while you're in there. Land the precision fix, ship it, then refactor in a follow-up.
- Don't change the public API surface (request shapes, route signatures, response shapes). The `min:0.01` → `min:0.0001` validation on `StoreStockTransferRequest` was already there; this PR makes it honest, not stricter.

### Verification before PR

- `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/ tests/Unit/Inventory/` — green.
- `cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=2G` — clean.
- `cd apps/api && ./vendor/bin/pint --test` — clean.
- `cd apps/web && pnpm typecheck && pnpm lint && pnpm test` — clean.
- Manual smoke pass: create a transfer with quantity `7.1234`, complete it, verify both `stock_levels` rows and both `stock_movements` rows store `7.1234` and the UI displays the same.
- `docker compose up` + Playwright if you wrote one — golden path stays green.

### Coordination

- A separate audit session is cataloging precision-drift issues across the rest of the app (`docs/superpowers/coordination/2026-05-28-app-wide-precision-audit-prompt.md`). Other modules may need similar fixes; coordinate so the migration files in each module don't collide.
- An inventory-transfer remediation session (PR #147) is parallel; that session is dropping its sub-cent validator workaround from "not covered" with a forward-pointer to this session. If you ship first, the inventory-transfer remediation session inherits the proper fix and can drop the validator entirely.

---

## Required reading before you start

- `apps/erp/CLAUDE.md` — agent operational rules (TDD, strict typing, constructor injection, no `mixed`, hexagonal architecture, route middleware contract).
- Memory file `project_monetary_precision.md` — the existing currency-precision rule, for context on what `CurrencyScale` does and why this work doesn't touch it.
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` — particularly the `SCALE` constant and all the bcmath sites.
- `apps/api/database/migrations/2025_11_30_110000_create_inventory_tables.php` — the original schema you're widening.
- The Opus and Codex reviews of PR #147 for context on why this fix matters: `docs/superpowers/reviews/2026-05-28-inventory-transfer-opus-review.md` (P2-5), `docs/superpowers/reviews/2026-05-28-inventory-transfer-codex-review.md` (cross-references).

---

## Output

A merged PR to `dev` whose body links to this prompt, explains the widening, and pastes the manual-smoke verification log. Ping the inventory-transfer session when it lands so it can drop the validator workaround.
