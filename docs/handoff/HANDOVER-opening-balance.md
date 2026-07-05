# HANDOVER — Product opening balance (Stage 3 of the IZI POS editor)

> Paste into a fresh session. Read-then-brainstorm-then-TDD. This is the **opening-balance** field on the product create form: an inline `opening_qty` / `opening_unit_cost` / `opening_as_of_date` that writes ONE initial `Opening` stock movement and locks once the item has any movement. Most of the inventory plumbing already exists — this is mostly a wire-up with correct logic + lock rule.

## Where to work
- Builds on the product editor branch: **worktree `/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor`, branch `feat/izipos-theme-product-editor`** (Stage 3 of that plan). Reconcile with `origin/dev` before finishing (rule 21).
- **⚠️ Worktree env (cost a prior session a stalled subagent — memory `project_izipos_worktree_backend_env_gotchas`):** vendor was a symlink to main (FIXED — verify `php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("App\\Modules\\Product\\Presentation\\Controllers\\ProductController"))->getFileName();'` prints the WORKTREE path; if a fresh worktree, redo `rm vendor && cp -R <main>/vendor ./vendor && composer dump-autoload -o`). Run backend tests **BY PATH** (full suite/bare `--filter` fatals on a broken `OwnerSalesSummaryServiceTest`). **Never run the full suite** (laptop crash). Validation envelope is `{error:{errors}}` → `Tests\Traits\AssertsApiValidation`. `typescript:transform` needs `CACHE_STORE=array …`.

## The spec (from the plans — already written)
- `docs/superpowers/plans/2026-06-24-izipos-product-editor.md` **Stage 3 (lines 201-211)**: add `opening_qty` (QuantityInput), `opening_unit_cost` (MoneyInput), `opening_as_of_date` (date); on create, post **ONE `MovementType::Opening` StockMovement**; **lock the inputs once the item has ≥1 movement**; expose `has_movements` on `ProductData`; editable **only at creation**.
- Rule 19 precision: `opening_qty` = quantity decimal(15,4) string via `<QuantityInput>` (FormRequest regex `…{1,4}`); `opening_unit_cost` = money decimal(N,3) string via `<MoneyInput>` (regex `/^-?\d+(\.\d{1,3})?$/`).

## What ALREADY exists (reuse — verified)
- **Enum/type are in place AND tested:** `MovementType::Opening` (`apps/api/app/Modules/Inventory/Domain/Enums/MovementType.php:14`, `isOpening()`), `MovementReason::OpeningBalance` (`.../Enums/MovementReason.php:15`, label "Opening Balance", in `manualAdjustmentCases()`). Verified by `tests/Feature/Inventory/InventoryOpeningMovementReasonTest.php`.
- **A working opening-movement service to mirror/reuse:** `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php` — `postBatch()` (line 201) creates the `StockMovement` (lines 280-294: `movement_type=Opening`, `reason=OpeningBalance`, quantity_before/after, `is_historical` when as-of date is in the past), creates/updates the stock level (297-308) and updates product cost (311-317), all in one transaction. **Decide: call this service from the product create flow, or extract a `ProductOpeningBalanceService` that wraps it for the single-product case.** Reusing avoids re-deriving on-hand logic.
- **StockMovement model:** `apps/api/app/Modules/Inventory/Domain/StockMovement.php` (`reason` fillable+cast at 67/91).
- **Stock-adjustment reason-passing pattern** (in-flight branch `apps/erp.stock-adj`, `feat/stock-adjustment-writeoff`): `StockAdjustmentService::adjust()`/`recordMovement()` thread `?MovementReason` through to the movement; `AdjustStockRequest` validates `Rule::in(MovementReason::manualAdjustmentValues())`. Good reference if you build a fresh service rather than reusing `InventoryOpeningService`.

## Hook point (product create)
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` `store()` (lines 315-428): product created at ~348-352, `ProductCreated` event at 354-363, metadata 366-415. **There is NO `DB::transaction()` wrapping store today — add one** so the product + opening movement + stock level commit atomically. Post the opening movement after the product exists (so it has an id) and only when `opening_qty > 0`.
- Validation: add nullable `opening_qty` / `opening_unit_cost` / `opening_as_of_date` rules to `CreateProductRequest` (regex per rule 19; as-of date `nullable|date`, not future beyond today unless historical is intended — decide).
- **Lock rule:** add `has_movements` to `ProductData` (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php` — constructor ~line 22, derive in `fromModel()` ~line 58 via the product's movements count > 0). Run `php artisan typescript:transform` after. FE: disable the three inputs + show a "Locked after first movement" helper when `has_movements === true`. These fields only appear/are-editable in **create mode** anyway.

## Method & open questions to brainstorm FIRST
1. **Reuse vs. wrap:** call `InventoryOpeningService` directly from product store, or a thin `ProductOpeningBalanceService`? Prefer reuse unless its `postBatch` signature (batch-shaped) makes the single-product call awkward.
2. **Location:** opening balance needs a stock location/branch. Which location does a just-created product's opening stock land in — the company's default/primary branch? Confirm how `InventoryOpeningService` resolves location and whether the product create form should let the user pick (parapharmacy may be single-branch at launch → default is fine, but make it explicit, not implicit).
3. **`opening_unit_cost` vs WAC:** opening cost seeds the product's cost basis (WAC). Confirm it flows into `cost_price`/WAC correctly (InventoryOpeningService lines 311-317 already update cost) and doesn't double-count.
4. **Historical date:** `is_historical=true` when `opening_as_of_date` is in the past — confirm fiscal/hash-chain implications are acceptable for an opening movement (it predates trading). Check with the fiscal rules before shipping a back-dated movement.
5. **Idempotency / re-submit:** guard against creating two opening movements if create is retried.

TDD: BE test — creating a product with `opening_qty=10, opening_unit_cost="5.000"` posts exactly one `Opening`/`OpeningBalance` movement, sets stock level to 10, sets cost; `has_movements` then true and a second opening attempt is rejected/blocked. FE test — inputs disabled when `has_movements`. Tests by path; preflight before commit.

## Acceptance bar
- Creating a product with an opening qty posts exactly one `MovementType::Opening` / `MovementReason::OpeningBalance` movement in the same transaction, sets on-hand and cost, and the opening inputs lock afterward.
- No opening movement when qty is empty/0. Precision is string-based (rule 19). Back-dated opening is fiscally sound (confirmed) or explicitly deferred.
- Reuses existing inventory opening infra rather than duplicating on-hand/cost logic.
