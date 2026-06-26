# Design — Inline Product Opening Balance (Stage 3, IZI POS editor) — v2

> Status: **DESIGN v2 — post Codex adversarial review; awaiting final user sign-off**
> Date: 2026-06-26
> Branch/worktree: `feat/izipos-theme-product-editor` (`/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor`)
> Adversarial review: `docs/superpowers/specs/2026-06-26-product-opening-balance-adversarial-review.md` (20 findings; this v2 resolves all accepted ones — see §13 disposition).
> Handover origin: `docs/handoff/HANDOVER-opening-balance.md` (uncommitted, main-repo only — **not** present in this worktree; its open questions are resolved inline here, so this spec is self-contained).

## 1. Goal

On the product **create** form, let an authorized user enter an inline opening stock balance:
`opening_qty` (quantity) + `opening_unit_cost` (inventory valuation cost). On create this posts
**exactly one** `MovementType::Opening` / `MovementReason::OpeningBalance` stock movement, upserts
the stock level, seeds the product cost basis, **and posts a balanced general-ledger entry**
(`Dr Inventory / Cr Opening Balance Equity`) — making an inline opening accounting-equivalent to
one created via the existing import flow.

Opening balance is **enter-once**. After it is posted the fields lock. A wrong opening can be
**reset** (reverse + re-enter) *only while the opening movement is the product's sole movement*;
once any downstream movement exists, the opening is permanently locked and changes go through the
existing stock-adjustment flow (`StockAdjustmentService`, `POST /stock-movements/adjust`,
`can:inventory.adjust`).

## 2. Decisions (locked with owner, 2026-06-26)

1. **Full GL via one extracted use case (1A).** Canonical posting logic is extracted out of
   `InventoryOpeningService::postBatch` into a reusable Application service that both the import
   flow and inline product-create call. One source of truth. No duplicated service. No TODO seams.
2. **`is_historical = true`, dated today.** Opening balances are pre-system seed data, **excluded
   from the GL fiscal hash chain** by the established convention (`AccountingOpeningService` posts
   openings `is_historical=true // Skip fiscal hash chain`; `GeneralLedgerService::postEntry` only
   seals `Draft` entries). Inline opening is dated **today** but flagged `is_historical=true` so it
   is a sealed-exempt historical entry, not an unsealed live one. (Corrects v1, which used
   `is_historical=false` — that would have produced a Posted, unsealed, chain-bypassing entry.)
   No date field; future dating is moot (not user-supplied). The service keeps an `entryDate`
   param (import uses `cutover_date`), so an as-of-date picker is an additive future adapter.
3. **Authorization:** inline opening posting requires **`can:inventory.adjust`** in addition to
   `can:products.create`, enforced **server-side** (not just UI). Opening is an inventory-valuation
   action in the same family as stock adjustment. (Resolves review C1 — escalation under bare
   `products.create`.)
4. **Location: strict company default**, resolved via `LocationContext::getDefaultLocation(companyId)`
   (NOT `resolveLocationId`, which returns the current-active location first). 422 if none exists.
   Then `LocationContext::validateLocationAccess(locationId, companyId, user)` before posting.
   (Resolves H1 + C5.) No picker this delivery; the use case already takes `location_id`.
5. **Enter-once enforced at three layers:** UI lock, a service-level check **inside** the advisory
   lock, and a DB partial-unique backstop. (Resolves C3 — v1's controller-only guard was racy and
   bypassable by other callers.)
6. **Correction = reset-opening while sole movement.** A bounded `POST /products/{id}/opening/reset`
   (gated `can:inventory.adjust`) that, *iff the opening movement is the product's only movement*,
   hard-removes the opening (movement + GL entry/lines + stock level + cost basis) and unlocks the
   fields. Defensible because it erases pre-trading seed data that never entered any transaction or
   the hash chain. Once any other movement exists → not allowed (use adjustment/revaluation).
   (Resolves C4.)
7. **Shared-service hardening (also fixes the import flow):** dispatch `StockMovementRecorded` +
   `StockMovementRecordedV2` after commit for every opening movement; move product/opening
   side-effect events to `DB::afterCommit()`; make `INV-OB` entry-number generation
   concurrency-safe. (Resolves H4/H5/H6.)
8. **No vertical gate.** Opening balance is a generic Inventory feature gated by `module:Inventory`
   + the `inventory.adjust` permission; it is available to any Inventory-enabled vertical (incl.
   Otospex). (Review H2 declined with reasoning — it is not vertical-exclusive.)

## 3. WAC coherence (the sensitive part)

| Path | Cost effect | Service |
|------|-------------|---------|
| **Opening** | Sets initial cost basis by **direct replacement** (no blend) | `OpeningBalancePostingService` |
| **Receipt** | **Blends** WAC across all locations | `WeightedAverageCostService::recordPurchase()` |
| **Adjustment** | Quantity only — **WAC unchanged** | `StockAdjustmentService::adjust()` |

Opening seeds the basis; receipts blend from it; adjustments never touch cost. The enter-once
invariant guarantees no opening is ever posted onto a product that already has receipts/sales, so
opening cost can never be re-blended into a running WAC (protects review's WAC concern).

## 4. Architecture — the extraction

```
app/Modules/Inventory/Application/
  Services/OpeningBalancePostingService.php   # single source of truth (1..N lines)
  DTOs/OpeningBalancePosting.php              # company, userId, entryDate, isHistorical,
                                              #   sourceType, sourceId, reference, notes, lines[]
  DTOs/OpeningBalanceLine.php                 # productId, variantId?, locationId, quantity, unitCost
  DTOs/OpeningBalancePostingResult.php        # JournalEntry + per-line movement ids (input order)
```

### 4.1 `OpeningBalancePostingService::post(OpeningBalancePosting): OpeningBalancePostingResult`

1. **Canonicalize at the boundary** (resolves Med1): `OpeningBalanceLine` constructor validates +
   canonicalizes `quantity` via `QuantityScale` (scale 4) and `unitCost` via `CurrencyScale`
   (currency scale, strict) — rejects non-numeric-string / over-scale input, so every caller
   (import, inline, future) reaches storage with identical precision.
2. Collect distinct `productId`s; `DB::transaction(..., attempts: 3)` →
   `costLock->acquire(tenantId, companyId, productIds, fn …)` (sorted advisory locks — deadlock
   defense preserved).
3. **Enter-once check INSIDE the lock** (resolves C3): for each line, after acquiring the lock,
   assert no existing `Opening` movement for `(company_id, product_id, location_id, variant_id)`;
   throw `OpeningAlreadyExistsException` if present. The check is re-read under the lock to close
   the TOCTOU window.
4. `entryNumber = generateOpeningEntryNumber(companyId)` — **concurrency-safe** (resolves H6):
   replace the read-max-and-increment with an advisory-locked sequence on
   `("inv-ob-seq:{tenantId}:{companyId}:{year}")` (or a dedicated sequence table) so concurrent
   openings on *different* products cannot collide on `(tenant_id, entry_number)`.
5. Per line: read/lock `StockLevel` (`lockForUpdate`), compute `quantity_after`, create the
   `StockMovement` (`movement_type=Opening`, `reason=OpeningBalance`, `is_historical=posting.isHistorical`,
   `reference`/`notes` from posting), upsert `StockLevel`, replace `cost_price` when `unitCost > 0`,
   accumulate `totalInventoryValue`, record `lineId → movementId`.
6. One `JournalEntry` (`status=Posted`, `entry_date=posting.entryDate`,
   `source_type=posting.sourceType`, `source_id=posting.sourceId`,
   `is_historical=posting.isHistorical`) + two balanced `JournalLine`s
   (Dr Inventory = Cr OBE = `totalInventoryValue`), accounts via
   `Account::findByPurposeOrFail(companyId, Inventory|OpeningBalanceEquity)`.
7. **After commit** (resolves H4): dispatch `StockMovementRecorded` + `StockMovementRecordedV2` for
   each created movement via `DB::afterCommit()`.
8. Return `OpeningBalancePostingResult{ entry->load('lines'), movementIdsInInputOrder }`.

GL is aggregated **once per posting** (matches current batch behavior; for inline = 1 line).

### 4.2 `InventoryOpeningService::postBatch` becomes a thin import adapter

Keeps import-only concerns (validate batch state, map `mapped_data` → `OpeningBalanceLine[]`,
`markBatchValidated`/`markRowsPosted`), builds an `OpeningBalancePosting`
(`entryDate=cutover_date`, `isHistorical=true`, source=batch), calls `post(...)`, zips
`movementIdsInInputOrder` back to rows. **Behavior parity except the deliberate hardening in §2.7**
(import now also dispatches movement events + uses safe numbering). The existing `InventoryOpening*`
tests must stay green; **add** an assertion that import now dispatches `StockMovementRecorded`.

### 4.3 DB backstop migration (resolves C3)

Partial unique index on `stock_movements (company_id, product_id, location_id)` (+ `variant_id`
where applicable) `WHERE movement_type = 'opening'`. Allows the import flow's legitimate one-opening
**per location** while preventing a duplicate opening for the same product+location. The migration
**pre-checks for existing duplicates** and aborts with a clear message rather than failing opaquely
(new-tenant launch has none; defensive for existing tenants). Reset (§6) hard-removes the row, so a
post-reset re-entry does not collide.

## 5. Product create flow (`ProductController::store`)

Constructor gains: `OpeningBalancePostingService`, `LocationContext`, `InventoryServiceInterface`.
`store()` is not transactional today — wrap it; move `ProductCreated` + metadata side-effects to
`DB::afterCommit()` (resolves H5).

```
authorize: can:products.create AND (opening fields present ⇒ can:inventory.adjust)   # §2.3
DB::transaction:
  $product = Product::create(...)
  build metadata/media
  if opening_qty present && bccomp(opening_qty,'0',4) > 0:
      require $product->is_physical === true            # §H3 — no opening on Service/non-physical
      $locationId = LocationContext->getDefaultLocation($companyId)?->id
      if $locationId === null: 422 "no default location"
      LocationContext->validateLocationAccess($locationId, $companyId, $user)   # §C5
      # opening_unit_cost is REQUIRED here (§H7) — enforced in CreateProductRequest
      $postingService->post(OpeningBalancePosting{
          company, userId, entryDate: today, isHistorical: true,              # §2.2
          sourceType:'opening_balance', sourceId:$product->id,
          reference:"Opening balance: {$product->sku}",
          lines:[ OpeningBalanceLine{ product->id, null, locationId, opening_qty, opening_unit_cost } ],
      })
DB::afterCommit: fire ProductCreated + opening movement events
return 201 with ProductData (has_movements true)
```

Empty/0 `opening_qty` → nothing posted (and `opening_unit_cost` ignored — see §6).

## 6. Validation (`CreateProductRequest`) — precision rule 19 + conditional

```php
'opening_qty'       => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
// REQUIRED when a positive opening qty is present (§H7 — no silent zero-cost inventory):
'opening_unit_cost' => ['required_with_positive:opening_qty', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
```

- Conditional: when `opening_qty > 0`, `opening_unit_cost` is required (custom rule / `withValidator`
  closure — `required_with` alone can't express "> 0"). When `opening_qty` is empty/0, providing
  `opening_unit_cost` is rejected with a 422 explaining it will be ignored (resolves Med3).
- **Negatives prohibited** (`min:0` + unsigned regex). Documented (resolves Med4): a negative
  initial count is a data-migration edge handled via the import flow / stock adjustment, not the
  inline form.
- Strings end-to-end; no `parseFloat`/float casts.

## 7. `has_movements` exposure — module boundary (rule 6)

Add to `Shared/Contracts/InventoryServiceInterface` (impl `InventoryService`, bound in
`AppServiceProvider`), **company-scoped** (resolves Med2; tenant isolation is the per-tenant DB):

```php
public function hasMovements(string $companyId, string $productId): bool;
public function hasOnlyOpeningMovement(string $companyId, string $productId): bool;  // for reset guard §6
```

`ProductController` injects the contract, computes `has_movements`, threads it into
`ProductData::fromModel(Product $product, ?ProductMediaData $media = null, bool $hasMovements = false)`
→ new `has_movements: bool`. Run `CACHE_STORE=array php artisan typescript:transform` after.
(`Product` already imports `Inventory\Domain\StockLevel` for `stockLevels()`, but the movements
check deliberately routes through the contract rather than widening that coupling.)

## 8. Correction — reset-opening (resolves C4)

New `POST /products/{id}/opening/reset` on the Product routes
(`['api','auth:sanctum',SetPermissionsTeam::class,…,'module:Inventory']` + `can:inventory.adjust`),
handled by a `ResetOpeningBalanceService` (Inventory Application), under the product advisory lock:

1. Guard: `hasOnlyOpeningMovement(companyId, productId)` must be true (exactly one movement, of type
   Opening). Otherwise 409 "Opening locked — downstream movements exist; use stock adjustment."
2. In one transaction: delete the opening `StockMovement`, delete its `JournalEntry` + `JournalLine`s,
   reset the `StockLevel` (to 0 / delete the row), clear `cost_price` + `cost_updated_at`.
3. `afterCommit`: dispatch `StockMovementRecorded(V2)` reflecting the removal so channels resync.

Rationale for hard-delete (not contra): the opening is `is_historical` seed data, excluded from the
hash chain, with zero downstream references — there is no fiscal history to preserve, and deletion
keeps the §4.3 unique index free for a corrected re-entry. Pre-launch setup operation only.

## 9. Frontend (`apps/web/src/features/inventory/ProductForm.tsx`)

- New **create-only** "Opening stock" section, **rendered only when `hasModule('Inventory')` &&
  `hasPermission('inventory.adjust')` && the product is physical** (mirrors the server gate §2.3/§H3):
  `<QuantityInput>` (`opening_qty`) + `<MoneyInput>` (`opening_unit_cost`). Strings in the payload.
- **Label/help clarifies valuation** (resolves Low2): e.g. "Coût unitaire de valorisation du stock
  (sert de base au coût moyen pondéré)" — not sale/purchase price. All copy via `t()`; design tokens.
- `opening_unit_cost` is shown required once `opening_qty > 0`; disabled/cleared when qty is empty
  (resolves Med3).
- When `has_movements === true`: section is locked with helper text. If the product has **only** the
  opening movement, show a **Reset opening** action (calls §8) → re-enter. Otherwise the helper links
  to the exact adjustment surface — **Inventory › Stock** (`/inventory/stock`, `StockLevelsPage`,
  `POST /stock-movements/adjust`) (resolves Low1).

## 10. Edge cases

Future date N/A (no input). `opening_qty` 0/empty → nothing posted, `opening_unit_cost` rejected if
supplied. `opening_unit_cost` required when qty>0 (no zero-cost inventory). Service/non-physical →
opening rejected (server + UI). No default location → 422. User lacks default-location access → 403.
Concurrent openings (same product) → second blocked by the in-lock enter-once check + DB index.
Concurrent openings (different products) → distinct safe entry numbers (no `(tenant,entry_number)`
collision). Opening GL entries are `is_historical` and excluded from the POS + GL hash chains.

## 11. Testing (TDD, run **by path**, never the full suite; `{error:{errors}}` envelope via `AssertsApiValidation`)

Backend:
- **Regression:** existing `InventoryOpening*` tests green after refactor; **add** "import dispatches
  `StockMovementRecorded`" + "import entry numbering is safe."
- **`OpeningBalancePostingService`:** single-line → one Opening/OpeningBalance movement, stock level,
  cost set, **balanced** GL entry (`Dr Inv == Cr OBE == qty×cost`), `is_historical=true`, movement
  events dispatched. `unitCost=0` path covered. Over-scale input rejected at the DTO boundary.
- **Enter-once / idempotency:** second opening for same product+location rejected (service throws);
  **two concurrent** same-product posts → exactly one succeeds (in-lock check + DB index);
  **two concurrent different-product** posts → both succeed with distinct entry numbers.
- **Authz (C1):** `products.create` **without** `inventory.adjust` + opening fields → 403;
  with both → 201.
- **Location (C5/H1):** opening lands in `getDefaultLocation`; user without access to it → 403;
  no default → 422.
- **Physical guard (H3):** Service product + opening fields → 422.
- **Required cost (H7):** `opening_qty>0` without `opening_unit_cost` → 422.
- **afterCommit (H5):** opening posting failure rolls back the product AND fires no `ProductCreated`.
- **Reset (C4):** reset while sole movement removes movement+GL+level+cost and unlocks; reset after a
  second movement → 409.

Frontend (Vitest): section hidden without `inventory.adjust` / for non-physical; inputs disabled
when `has_movements`; Reset action shown only when opening is the sole movement.

Pre-flight (`./scripts/preflight.sh`) before commit.

## 12. Blast radius

- **New:** `OpeningBalancePostingService`, `ResetOpeningBalanceService`, 3 posting DTOs,
  `OpeningAlreadyExistsException`; `hasMovements`/`hasOnlyOpeningMovement` on
  `InventoryServiceInterface` + impl; DB migration (partial unique index + concurrency-safe
  numbering support); `POST /products/{id}/opening/reset` route.
- **Modified:** `InventoryOpeningService::postBatch` (delegates + hardening),
  `ProductController::store` (authz + transaction + afterCommit + opening post),
  `CreateProductRequest` (rules), `ProductData` (`has_movements`), `ProductForm.tsx`, generated FE
  types. Import flow gains movement-event dispatch + safe numbering (intended; regression-checked).
- **Untouched:** `StockAdjustmentService`, `WeightedAverageCostService`, the merged
  stock-adjustment-writeoff code. No TODO seams.

## 13. Review disposition (`…-adversarial-review.md`)

- **Accepted & resolved:** C1 (§2.3 authz), C2 (§2.2 `is_historical`), C3 (§4.1/§4.3 in-lock check +
  DB index), C4 (§8 reset), C5 (§5 location access), H1 (§2.4 strict default), H3 (§5 physical),
  H4/H5/H6 (§2.7 shared hardening), H7 (§6 required cost), Med1 (§4.1 canonicalization),
  Med2 (§7 scope), Med3 (§6 qty/cost UX), Med4 (§6 negatives documented), Med5 (§11 concurrency
  tests), Med6 (handover note in header), Low1 (§9 route), Low2 (§9 label).
- **Declined with reasoning:** H2 (vertical gate) — opening balance is generic to any
  Inventory-enabled vertical; gated by `module:Inventory` + `inventory.adjust`, not a vertical gate.

## 14. Out of scope (clean future adapters, no rework)

Location picker (use case takes `location_id`); as-of-date picker / back-dated inline opening (use
case takes `entryDate`); full anytime cost-revaluation flow (beyond the sole-movement reset);
product-page stock-adjustment action (same `StockAdjustmentService`).
