# Design — Inline Product Opening Balance (Stage 3, IZI POS editor)

> Status: **DESIGN — awaiting Codex adversarial review + user approval**
> Date: 2026-06-26
> Branch/worktree: `feat/izipos-theme-product-editor` (`/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor`)
> Supersedes the open questions in `docs/handoff/HANDOVER-opening-balance.md`.

## 1. Goal

On the product **create** form, let the user enter an inline opening stock balance:
`opening_qty` (quantity) + `opening_unit_cost` (money). On create, this posts **exactly one**
`MovementType::Opening` / `MovementReason::OpeningBalance` stock movement, upserts the stock
level, seeds the product cost basis, **and posts the corresponding general-ledger entry**
(`Dr Inventory / Cr Opening Balance Equity`) — making an inline opening balance
accounting-equivalent to one created via the existing import flow.

Opening balance is **enter-once**: once a product has any movement, the inline fields are
locked (UI) and the server refuses a second opening (invariant). All subsequent inventory
changes go through the **existing stock-adjustment flow** (`StockAdjustmentService`,
`POST /stock-movements/adjust`, `can:inventory.adjust`) — not re-editing the opening balance.

## 2. Decisions (locked with owner, 2026-06-26)

1. **Full GL via a single extracted use case (option 1A).** Opening inventory must hit the
   ledger (ERP/accounting standard; trial balance must tie). The canonical posting logic is
   extracted out of `InventoryOpeningService::postBatch` into a reusable Application use case
   that both the import flow and the inline product-create flow call. One source of truth for
   the fiscal/WAC-seeding logic. **No duplicated "thin mirror" service. No TODO seams.**
2. **Location: auto-resolve the company default** via `Company\Services\LocationContext`
   (`resolveLocationId(null, companyId)`). No picker this delivery; the use case already takes
   `location_id`, so a picker is a purely additive future adapter.
3. **Post-opening changes: lock + route to the existing adjustment flow.** No new product-page
   adjustment UI this delivery (additive follow-up; same `StockAdjustmentService` use case).
4. **Date: always today; no date field.** There is no UI to choose a date, so the inline
   opening is always dated **today** → `is_historical = false`. Future dating is moot (not
   user-supplied). The extracted use case still carries an `entryDate` (the import flow needs
   it for cutover dating), so a future "as-of date" picker is additive with zero rework.
   **No `opening_as_of_date` field in the form or FormRequest this delivery.**

## 3. WAC coherence (the sensitive part)

The three inventory cost paths stay correctly separated — confirmed against existing code:

| Path | Cost effect | Service |
|------|-------------|---------|
| **Opening** | Sets initial cost basis by **direct replacement** (no blend) | extracted use case |
| **Receipt** | **Blends** WAC across all locations | `WeightedAverageCostService::recordPurchase()` |
| **Adjustment** | Quantity only — **WAC unchanged** (recount, not revalue) | `StockAdjustmentService::adjust()` |

Opening seeds the basis; receipts blend from it; adjustments never touch cost. No double-count.
Cost replacement mirrors the existing import behavior (`InventoryOpeningService` lines ~310-317,
guarded by `bccomp($unitCost, '0', scale) > 0`).

## 4. Architecture — the extraction

New, in the Inventory module (Application layer), taking **clean DTOs** with no batch coupling:

```
app/Modules/Inventory/Application/
  Services/OpeningBalancePostingService.php   # the single source of truth
  DTOs/OpeningBalancePosting.php              # company, userId, entryDate, isHistorical,
                                              #   sourceType, sourceId, reference, notes, lines[]
  DTOs/OpeningBalanceLine.php                 # productId, locationId, quantity, unitCost (strings)
  DTOs/OpeningBalancePostingResult.php        # JournalEntry + per-line movement ids (input order)
```

### 4.1 `OpeningBalancePostingService::post(OpeningBalancePosting): OpeningBalancePostingResult`

Does exactly what `postBatch`'s inner closure does today, parameterized over **1..N lines**:

1. Collect distinct `productId`s from the lines.
2. `DB::transaction(..., attempts: 3)` → `costLock->acquire(tenantId, companyId, productIds, fn …)`
   (advisory locks, sorted internally — preserves the multi-product deadlock defense).
3. `entryNumber = generateEntryNumber(companyId)` (moved verbatim from `InventoryOpeningService`;
   `INV-OB-{year}-{6-digit seq}`).
4. For each line: read/lock `StockLevel` (`lockForUpdate`), compute `quantity_after`, create the
   `StockMovement` (`movement_type=Opening`, `reason=OpeningBalance`, `is_historical` from the
   posting, `reference`/`notes` from the posting), upsert the `StockLevel`, replace `cost_price`
   when `unit_cost > 0`, accumulate `totalInventoryValue`, record `lineId → movementId`.
5. One `JournalEntry` (`status=Posted`, `entry_date=posting.entryDate`,
   `source_type=posting.sourceType`, `source_id=posting.sourceId`,
   `is_historical=posting.isHistorical`) + two balanced `JournalLine`s
   (Dr Inventory = Cr Opening Balance Equity = `totalInventoryValue`), accounts resolved via
   `Account::findByPurposeOrFail(companyId, Inventory|OpeningBalanceEquity)`.
6. Return `OpeningBalancePostingResult{ entry->load('lines'), movementIdsInInputOrder }`.

Dependencies (constructor-injected): `CurrencyScaleResolverInterface`, `ProductCostLock`.
**Note:** the GL entry is aggregated **once per posting**, not per line — this is exactly the
current batch behavior, and for the inline path (1 line) it is one entry with one line's value.

### 4.2 `InventoryOpeningService::postBatch` becomes a thin import adapter

Unchanged responsibilities it keeps (import-specific): validate batch state (draft, valid rows),
map `OpeningBalanceImportRow.mapped_data` → `OpeningBalanceLine[]`, build an `OpeningBalancePosting`
(`entryDate = batch.cutover_date`, `isHistorical = true`, `sourceType='opening_balance'`,
`sourceId=batch.id`, `reference="Opening Balance Batch: {name}"`), call
`postingService->post(...)`, then **zip** `result.movementIdsInInputOrder` with the rows (same
order) to build `rowEntityMap` for `markBatchValidated` + `markRowsPosted`.

**Behavior is byte-identical.** The existing `InventoryOpening*` tests are the regression proof
and must stay green with no edits. `postBatch`'s constructor gains the posting service; the GL/
movement/level/cost code and the now-shared `generateEntryNumber` move into the new service.

## 5. Product create flow

`ProductController::store` — constructor gains: `OpeningBalancePostingService`, `LocationContext`,
`InventoryServiceInterface` (for the movements check). Today `store()` is **not** transactional;
wrap it.

```
DB::transaction:
  $product = Product::create(...)                # existing
  fire ProductCreated, build metadata/media      # existing
  if opening_qty !== null && bccomp(opening_qty,'0',4) > 0:
      $locationId = LocationContext->resolveLocationId(null, $companyId)   # auto default
      if $locationId === null: 422 (no default location configured)
      assert InventoryService->hasMovements($product->id) === false        # enter-once invariant
      $postingService->post(OpeningBalancePosting{
          company, userId, entryDate: today, isHistorical: false,
          sourceType: 'opening_balance', sourceId: $product->id,
          reference: "Opening balance: {$product->sku}",
          lines: [ OpeningBalanceLine{ product->id, locationId, opening_qty, opening_unit_cost } ],
      })
  return 201 with ProductData (has_movements now true)
```

- `opening_qty` empty/0 → **nothing** posted (no movement, no GL).
- `opening_unit_cost` 0 → movement posts, `cost_price` left untouched (same guard as import).
- The new product can't already have movements; the `hasMovements` assertion is defense-in-depth
  against retries / a future edit-path wiring.

## 6. Validation (`CreateProductRequest`) — precision rule 19

```php
'opening_qty'       => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
'opening_unit_cost' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
// no opening_as_of_date this delivery
```

Strings end-to-end; no `parseFloat`/float casts. `min:0` + regex ceiling per column scale.

## 7. `has_movements` exposure — respecting module boundaries (rule 6)

Product must not query Inventory's `StockMovement` model directly. Add to the existing
`Shared/Contracts/InventoryServiceInterface` (implemented by
`Inventory\Application\Services\InventoryService`, bound in `AppServiceProvider`):

```php
public function hasMovements(string $productId): bool;
```

`ProductController` injects `InventoryServiceInterface`, computes the flag, and threads it into
the DTO. `ProductData::fromModel(Product $product, ?ProductMediaData $media = null, bool $hasMovements = false)`
gains `has_movements: bool`. Run `CACHE_STORE=array php artisan typescript:transform` after
(per worktree env note) to regenerate the FE type.

> Precedent note: `Product` already imports `Inventory\Domain\StockLevel` for its `stockLevels()`
> relationship, but we deliberately route the new movements check through the contract rather than
> widening that coupling.

## 8. Frontend (`apps/web/src/features/inventory/ProductForm.tsx`)

- New **create-only** "Opening stock" section: `<QuantityInput>` (`opening_qty`) +
  `<MoneyInput>` (`opening_unit_cost`) — both already imported. Payload sends strings.
- All labels/help via `t()`; colors via design tokens.
- In **edit** mode (or whenever `has_movements === true`): the two inputs are disabled with a
  helper line — e.g. "Opening balance is locked after the first stock movement. Use **Inventory ›
  Stock** to adjust." — pointing to the existing adjustment flow. No new adjustment UI here.

## 9. Edge cases

- Future date: not applicable (no date input; always today).
- `opening_qty` 0/empty → no posting.
- `opening_unit_cost` 0 → movement posts, cost untouched.
- Double submit / retry → guarded by the wrapping transaction + the `hasMovements` invariant.
- No default location configured → 422 (explicit), opening not silently dropped.
- Opening movements / their GL entry are **not** part of the POS fiscal hash chain (that chain is
  for `SALE_RECEIPT`). `is_historical=false` for inline (today-dated); the import flow's
  historical handling is unchanged.

## 10. Testing (TDD, run **by path**, never the full suite)

Backend (`tests/Feature/...` / `tests/Unit/...`):
- **Regression (port proof):** existing `InventoryOpening*` tests pass unchanged after the refactor.
- **`OpeningBalancePostingService`:** a single-line post creates exactly one `Opening`/`OpeningBalance`
  movement, upserts the stock level, sets `cost_price`, and posts a **balanced** GL entry
  (`Dr Inventory == Cr OBE == qty × cost`). `unit_cost=0` → movement but no cost write.
- **Product create:** `opening_qty="10"`, `opening_unit_cost="5.000"` → one Opening movement,
  stock level 10, cost set, `has_movements` true. `opening_qty` empty → no movement.
  Invalid precision (e.g. `"5.00000"`) → 422 (`{error:{errors}}` envelope; `AssertsApiValidation`).
- **Enter-once:** posting an opening when movements already exist is rejected.

Frontend (Vitest): opening inputs disabled when `has_movements === true`; enabled on create.

Pre-flight (`./scripts/preflight.sh` — PHPStan L8, Pint, scoped PHPUnit, tsc, ESLint) before commit.

## 11. Blast radius

- **New:** `OpeningBalancePostingService` + 3 DTOs; `hasMovements` on `InventoryServiceInterface` +
  `InventoryService`.
- **Modified:** `InventoryOpeningService::postBatch` (delegates; behavior identical),
  `ProductController::store` (transaction + opening post), `CreateProductRequest` (2 rules),
  `ProductData` (`has_movements`), `ProductForm.tsx` (fields + lock), generated FE types.
- **No** changes to `StockAdjustmentService`, `WeightedAverageCostService`, or the
  stock-adjustment-writeoff code already merged — zero overlap, conflict-free.
- **No TODO seams.**

## 12. Out of scope (clean future adapters, no rework needed)

- Location picker on the form (use case already takes `location_id`).
- `as-of date` picker / back-dated inline opening (use case already takes `entryDate`).
- Product-page stock-adjustment action (same `StockAdjustmentService` use case).
