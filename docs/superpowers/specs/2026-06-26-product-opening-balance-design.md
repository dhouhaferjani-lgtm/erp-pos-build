# Design — Inline Product Opening Balance (Stage 3, IZI POS editor) — v2.1

> Status: **DESIGN v2.1 — two Codex adversarial passes folded in; awaiting final user sign-off**
> Date: 2026-06-26
> Branch/worktree: `feat/izipos-theme-product-editor` (`/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor`)
> Adversarial reviews: `…-adversarial-review.md` (20 findings) + `…-adversarial-review-v2.md` (new-surface pass). Dispositions in §13.
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
5. **Enter-once enforced in the service, under the advisory lock** (resolves C3 — v1's
   controller-only guard was racy and bypassable by other callers): the authoritative guard is an
   in-lock check for "no **active** (non-reversed) opening" for the product+location, run inside
   `OpeningBalancePostingService` so **every** caller is covered, not just the controller. Plus the
   UI lock. No DB partial-unique index — reset-by-reversal (§6) makes "active opening" a *stateful*
   predicate (an Opening movement not referenced by any reversing movement) that a partial index
   cannot express; the advisory-locked service check is the correct single guard.
6. **Correction = reset-opening while sole movement, via contra-reversal (audit-preserving).** A
   bounded `POST /products/{id}/opening/reset` (gated `can:inventory.adjust`) that, *iff the opening
   movement is the product's only active movement*, **reverses** the opening — reusing the existing
   `reverses_movement_id` reversal infrastructure (from the merged stock-adjustment-writeoff work) to
   post a reversing stock movement + a reversing/contra GL entry, zeroing stock + clearing the cost
   basis, and unlocks the fields for re-entry. **Both the original and the reversal rows remain** —
   full audit trail (StockMovement has no soft-delete/audit columns, so deletion would leave no
   record; reversal is the compliant correction). Once any other (non-reversal) movement exists →
   not allowed (use adjustment/revaluation). (Resolves C4 + v2-review reset-audit finding; matches
   the reversal semantics chosen for C4.)
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
   assert no **active** (non-reversed) `Opening` movement exists for
   `(company_id, product_id, location_id, variant_id)` — i.e. no Opening movement whose id is not
   referenced by a reversing movement's `reverses_movement_id`; throw `OpeningAlreadyExistsException`
   if present. Re-read under the lock to close the TOCTOU window. This is the authoritative guard
   for all callers (no DB partial-unique index — see §2.5).
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

### 4.3 No DB partial-unique index (resolves C3 via the in-lock check instead)

v1/early-v2 proposed a partial-unique index on `stock_movements … WHERE movement_type='opening'`.
**Dropped.** Reset-by-reversal (§8) keeps the original opening row and adds a reversal row, so the
real invariant is "at most one **active** (non-reversed) opening per product+location" — a stateful
predicate (an Opening row not referenced by any reversal's `reverses_movement_id`) that a partial
index cannot express; a naive index would also block legitimate post-reset re-entry. The
**advisory-locked in-service check** (§4.1 step 3) is the authoritative guard and covers every
caller — which is exactly what C3 asked for (guard in the reusable service, not the controller).
No new index migration; no destructive change to any existing unique.

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

## 8. Correction — reset-opening via contra-reversal (resolves C4 + v2 reset-audit finding)

New `POST /products/{id}/opening/reset` on the Product routes
(`['api','auth:sanctum',SetPermissionsTeam::class,…,'module:Inventory']` + `can:inventory.adjust`),
handled by a `ResetOpeningBalanceService` (Inventory Application), under the product advisory lock:

1. Guard: `hasOnlyOpeningMovement(companyId, productId)` must be true — exactly one **active**
   movement, of type Opening (no downstream, no prior reversal). Otherwise 409 "Opening locked —
   downstream movements exist; use stock adjustment."
2. In one transaction, **reverse** (do not delete) — reusing the existing `reverses_movement_id`
   reversal infrastructure (stock-adjustment-writeoff merge): post a reversing `StockMovement`
   (`reverses_movement_id` = the opening movement, quantity negated) bringing the stock level to 0,
   post a reversing/contra `JournalEntry` (`is_historical=true`; Dr OBE / Cr Inventory for the
   opening value) linked to the original, and clear `cost_price` + `cost_updated_at`.
3. `afterCommit`: dispatch `StockMovementRecorded` + `StockMovementRecordedV2` for the reversal so
   channels resync.

After reset, the §4.1-step-3 check sees the original opening as **reversed** (inactive), so a
corrected opening can be re-entered. **Both rows persist → full audit trail.** Rationale for
reversal over hard-delete: `StockMovement` has no soft-delete/audit columns, so deletion would
leave no record; reversal is the compliant correction and reuses tested infrastructure. Pre-launch
setup operation, but auditable regardless.

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
  **two concurrent** same-product posts → exactly one succeeds (in-lock active-opening check);
  **two concurrent different-product** posts → both succeed with distinct entry numbers.
- **Authz (C1):** `products.create` **without** `inventory.adjust` + opening fields → 403;
  with both → 201.
- **Location (C5/H1):** opening lands in `getDefaultLocation`; user without access to it → 403;
  no default → 422.
- **Physical guard (H3):** Service product + opening fields → 422.
- **Required cost (H7):** `opening_qty>0` without `opening_unit_cost` → 422.
- **afterCommit (H5):** opening posting failure rolls back the product AND fires no `ProductCreated`.
- **Reset (C4):** reset while sole active movement posts a reversal movement (`reverses_movement_id`
  set) + contra GL, zeroes the stock level, clears cost, and unlocks — **original + reversal rows
  both persist** (audit); a corrected opening can then be re-entered. Reset after a second movement →
  409. Reset when an opening was already reversed → 409.

Frontend (Vitest): section hidden without `inventory.adjust` / for non-physical; inputs disabled
when `has_movements`; Reset action shown only when opening is the sole movement.

Pre-flight (`./scripts/preflight.sh`) before commit.

## 12. Blast radius

- **New:** `OpeningBalancePostingService`, `ResetOpeningBalanceService` (reuses the existing
  `reverses_movement_id` reversal infra), 3 posting DTOs, `OpeningAlreadyExistsException`;
  `hasMovements`/`hasOnlyOpeningMovement` on `InventoryServiceInterface` + impl; concurrency-safe
  `INV-OB` numbering support (sequence/advisory lock — no new uniqueness index);
  `POST /products/{id}/opening/reset` route.
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

### v2 re-review disposition (`…-adversarial-review-v2.md`)

- **Declined — BLOCKER "classes don't exist / REJECT":** category error. This is a *design spec*;
  implementation is the next phase (writing-plans → TDD). Non-existence of the described classes is
  expected, not a spec defect.
- **Accepted — reset has no audit trail:** §6/§8 switched from hard-delete to **contra-reversal**
  (reuses `reverses_movement_id`); both rows persist. Also aligns with the reversal semantics chosen
  for C4.
- **Reaffirmed — entry-number race:** already designed as a concurrency-safe sequence (§4.1 step 4).
- **Clarified — "destructive partial-unique migration":** misread (it targeted `stock_movements`,
  not `opening_balance_entries`); moot — the index is **dropped** (§4.3) in favor of the in-lock guard.
- **Out of scope — `is_historical` immutability:** pre-existing, GL-wide (the `JournalEntryObserver`
  `updating` guard only protects entries that already hold a `fiscal_hash`; all historical opening
  entries lack one today). Not introduced by this feature → §14 follow-up, not scope creep here.

## 14. Out of scope (clean future adapters / separate follow-ups, no rework)

- Location picker (use case takes `location_id`); as-of-date picker / back-dated inline opening (use
  case takes `entryDate`); full anytime cost-revaluation flow (beyond the sole-movement reset);
  product-page stock-adjustment action (same `StockAdjustmentService`).
- **`is_historical` column immutability (GL-wide follow-up):** the `JournalEntryObserver` `updating`
  guard only protects entries that already hold a `fiscal_hash`, so a historical (chain-excluded)
  entry's `is_historical` could in principle be toggled post-creation without detection. This
  affects **all** historical opening entries (import included), predates this feature, and should be
  hardened separately (e.g. a model/DB guard making `is_historical` immutable after insert). Flagged
  here so it is not lost; not addressed in this delivery to avoid scope creep into the GL core.
