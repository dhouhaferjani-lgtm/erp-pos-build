# Design — Inline Product Opening Balance (Stage 3, IZI POS editor) — v2.3

> Status: **DESIGN v2.3 — three Codex adversarial passes + industry best-practice research folded in; ready for user sign-off → writing-plans**
> Date: 2026-06-26 (rev 2026-06-27)
> Branch/worktree: `feat/izipos-theme-product-editor` (`/Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/izipos-product-editor`)
> Adversarial reviews: `…-adversarial-review.md` (20 findings) + `…-adversarial-review-v2.md` (new-surface) + `…-adversarial-review-v3.md` (final gate; 1 blocking re-entry-path fix). Dispositions in §13.
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
4. **Location: company default (or first active), via `getDefaultLocation`.** Resolve via
   `LocationContext::getDefaultLocation(companyId)` — the company's explicit default, falling back to
   its first active location (NOT `resolveLocationId`, which returns the *current-session* active
   location first — H1's concern). 422 only when the company has **no active location at all**. Then
   `LocationContext::validateLocationAccess(locationId, companyId, user)` before posting. (Resolves
   H1 — avoids the session-current-location surprise — + C5.) For a single-branch tenant (launch),
   default-or-first-active is the sole correct location. No picker this delivery; the use case
   already takes `location_id`. (v3: corrected from "strict default + 422-if-none" — that mismatched
   `getDefaultLocation`'s real first-active fallback semantics; no LocationContext change needed.)
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
      if $locationId === null: 422 "no active location configured"          # §2.4
      LocationContext->validateLocationAccess($locationId, $companyId, $user)   # §C5
      # opening_unit_cost is REQUIRED here (§H7) — enforced in CreateProductRequest
      $postingService->post(OpeningBalancePosting{ … as §5.1 … sourceId:$product->id })
DB::afterCommit: fire ProductCreated + opening movement events
return 201 with ProductData (opening-state fields — §7)
```

Empty/0 `opening_qty` → nothing posted (and `opening_unit_cost` ignored — see §6). The opening
posting call is **identical** to the standalone re-entry endpoint (§5.1) — both are thin adapters
over the one `OpeningBalancePostingService` use case; create just does it atomically in the same
transaction as the product insert.

### 5.1 Standalone opening endpoint (resolves v3 BLOCKING — the re-entry path)

`POST /products/{id}/opening` on the Product routes
(`['api','auth:sanctum',SetPermissionsTeam::class,…,'module:Inventory']` + `can:inventory.adjust`),
a thin adapter over `OpeningBalancePostingService`. It posts an opening for an **existing** product
that is eligible (`can_enter_opening` — §7), with the same physical guard, location resolution
(§2.4), access check (§C5), required-cost rule (§H7), and in-lock enter-once guard (§4.1 step 3) as
create. Two callers it serves:
- **Post-reset re-entry** — after §8 reverses a wrong opening, the product has `can_enter_opening`
  true again, so this endpoint enters the corrected opening. **This is what closes the "reverse +
  re-enter" loop** that reversal-based reset (v2.1) otherwise left open.
- **First opening on a product created without one** — a product created with no opening (then no
  activity yet) can still receive its opening here. This also matches the standard "add opening
  later" path (Zoho/QuickBooks) and keeps create-time opening optional.

The create flow (§5) and this endpoint are the two adapters; the service + its guard are the single
source of truth.

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

## 7. Opening-state exposure — module boundary (rule 6) — resolves v3 BLOCKING

A single coarse `has_movements` bool **cannot** drive the lock once reset-by-reversal keeps rows
around (a reset product still "has movements" yet must be re-openable). The DTO instead exposes an
**opening state**. Add to `Shared/Contracts/InventoryServiceInterface` (impl `InventoryService`,
bound in `AppServiceProvider`), **company-scoped** (resolves Med2; tenant isolation is the
per-tenant DB):

```php
public function hasActiveOpening(string $companyId, string $productId): bool;       // a non-reversed Opening movement exists
public function hasDownstreamMovements(string $companyId, string $productId): bool; // any movement that is neither the opening nor its reversal
```

`ProductData` gains three fields (the third derived):

```php
has_active_opening:       bool   // there is a live opening
has_downstream_movements: bool   // real activity (receipt/sale/adjustment) exists
can_enter_opening:        bool   // = !has_active_opening && !has_downstream_movements
```

- `can_enter_opening` drives the UI: the opening section is editable iff true (a fresh product, OR a
  reset product with no later activity). It is **not** equivalent to "create mode" — an existing
  product can be eligible (post-reset / never-opened).
- The §8 reset guard = `hasActiveOpening && !hasDownstreamMovements` (exactly one active opening, no
  activity). The §4.1-step-3 enter-once guard = `!hasActiveOpening` under the lock.

`ProductController` injects the contract and threads these into
`ProductData::fromModel(Product $product, ?ProductMediaData $media = null, ?OpeningState $opening = null)`.
Run `CACHE_STORE=array php artisan typescript:transform` after. (`Product` already imports
`Inventory\Domain\StockLevel` for `stockLevels()`, but the movement checks deliberately route
through the contract rather than widening that coupling.)

## 8. Correction — reset-opening via contra-reversal (resolves C4 + v2 reset-audit finding)

New `POST /products/{id}/opening/reset` on the Product routes
(`['api','auth:sanctum',SetPermissionsTeam::class,…,'module:Inventory']` + `can:inventory.adjust`),
handled by a `ResetOpeningBalanceService` (Inventory Application), under the product advisory lock:

1. Guard: `hasActiveOpening(companyId, productId) && !hasDownstreamMovements(companyId, productId)`
   (§7) — exactly one **active** Opening, no downstream activity, no prior reversal. Otherwise 409
   "Opening locked — downstream movements exist; use stock adjustment."
2. In one transaction, **reverse** (do not delete) — reusing the existing `reverses_movement_id`
   reversal infrastructure (stock-adjustment-writeoff merge): post a reversing `StockMovement`
   (`reverses_movement_id` = the opening movement, quantity negated) bringing the stock level to 0,
   post a reversing/contra `JournalEntry` (`is_historical=true`; Dr OBE / Cr Inventory for the
   opening value) linked to the original, and clear `cost_price` + `cost_updated_at`.
3. `afterCommit`: dispatch `StockMovementRecorded` + `StockMovementRecordedV2` for the reversal so
   channels resync.

After reset, `hasActiveOpening` is false and (no activity) `can_enter_opening` is true, so the
corrected opening is re-entered via the standalone endpoint **`POST /products/{id}/opening` (§5.1)**
— this is what completes the "reverse + re-enter" loop. **Both rows persist → full audit trail.**
Rationale for
reversal over hard-delete: `StockMovement` has no soft-delete/audit columns, so deletion would
leave no record; reversal is the compliant correction and reuses tested infrastructure. Pre-launch
setup operation, but auditable regardless.

**On the "sole active movement" gate (acknowledged conservative choice):** industry norm gates
opening corrections on *period-close*, allowing a dated correcting reversal even after later
activity (research §15). Our gate is deliberately **tighter** — once any non-reversal movement
exists, inline reset is refused. This is safe and simple (the system has no formal period-close
concept yet); the post-activity correction path for a wrong **cost** is the future
cost-revaluation flow (§14), and a wrong **quantity** is the existing stock-adjustment flow. If a
period-close concept is later introduced, loosening this gate to "before period close" is the
standard-aligned enhancement.

## 9. Frontend (`apps/web/src/features/inventory/ProductForm.tsx`)

- "Opening stock" section, **rendered only when `hasModule('Inventory')` &&
  `hasPermission('inventory.adjust')` && the product is physical** (mirrors the server gate §2.3/§H3):
  `<QuantityInput>` (`opening_qty`) + `<MoneyInput>` (`opening_unit_cost`). Strings in the payload.
  It shows on **create**, and on an **existing product when `can_enter_opening === true`** (post-reset
  / never-opened) — submitting then calls `POST /products/{id}/opening` (§5.1) instead of create.
- **Label/help clarifies valuation** (resolves Low2): e.g. "Coût unitaire de valorisation du stock
  (sert de base au coût moyen pondéré)" — not sale/purchase price. All copy via `t()`; design tokens.
- `opening_unit_cost` is shown required once `opening_qty > 0`; disabled/cleared when qty is empty
  (resolves Med3).
- When **`can_enter_opening === false`**: section is locked with helper text. If `has_active_opening &&
  !has_downstream_movements`, show a **Reset opening** action (§8) → then the section becomes
  re-enterable. Otherwise (downstream activity exists) the helper links to the exact adjustment
  surface — **Inventory › Stock** (`/inventory/stock`, `StockLevelsPage`, `POST /stock-movements/adjust`)
  (resolves Low1).

## 10. Edge cases

Future date N/A (no input). `opening_qty` 0/empty → nothing posted, `opening_unit_cost` rejected if
supplied. `opening_unit_cost` required when qty>0 (no zero-cost inventory). Service/non-physical →
opening rejected (server + UI). No active location → 422. User lacks default-location access → 403.
Concurrent openings (same product) → second blocked by the product advisory lock + in-lock
active-opening check (§4.1 step 3; no DB index — §4.3). Concurrent openings (different products) →
distinct safe entry numbers (no `(tenant,entry_number)` collision). Opening GL entries are
`is_historical` and excluded from the POS + GL hash chains.

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
  set) + contra GL, zeroes the stock level, clears cost — **original + reversal rows both persist**
  (audit). Reset after a second movement → 409. Reset when an opening was already reversed → 409.
- **Re-entry endpoint (§5.1 / v3 BLOCKING):** `POST /products/{id}/opening` posts an opening on an
  eligible existing product; **post-reset re-entry** succeeds and yields one fresh active opening +
  correct stock/cost; the endpoint is gated `inventory.adjust` (403 without); rejected when
  `can_enter_opening` is false (active opening still present → enter-once; or downstream activity →
  409). State assertions: `has_active_opening`/`has_downstream_movements`/`can_enter_opening`
  transition correctly across create → reset → re-enter.

Frontend (Vitest): section hidden without `inventory.adjust` / for non-physical; section locked when
`can_enter_opening === false`; **Reset opening** action shown only when `has_active_opening &&
!has_downstream_movements`; section re-enterable (calls §5.1) after reset.

Pre-flight (`./scripts/preflight.sh`) before commit.

## 12. Blast radius

- **New:** `OpeningBalancePostingService`, `ResetOpeningBalanceService` (reuses the existing
  `reverses_movement_id` reversal infra), 3 posting DTOs + an `OpeningState` DTO,
  `OpeningAlreadyExistsException`; `hasActiveOpening`/`hasDownstreamMovements` on
  `InventoryServiceInterface` + impl; concurrency-safe `INV-OB` numbering support (sequence/advisory
  lock — no new uniqueness index); `POST /products/{id}/opening` (re-entry, §5.1) +
  `POST /products/{id}/opening/reset` routes + their controller actions.
- **Modified:** `InventoryOpeningService::postBatch` (delegates + hardening),
  `ProductController::store` (authz + transaction + afterCommit + opening post),
  `CreateProductRequest` (rules), `ProductData` (opening-state fields), `ProductForm.tsx`, generated
  FE types. Import flow gains movement-event dispatch + safe numbering (intended; regression-checked).
- **Untouched:** `StockAdjustmentService`, `WeightedAverageCostService`, the merged
  stock-adjustment-writeoff code. No TODO seams.

## 13. Review disposition (`…-adversarial-review.md`)

- **Accepted & resolved:** C1 (§2.3 authz), C2 (§2.2 `is_historical`), C3 (§4.1 in-lock
  active-opening check), C4 (§8 reset), C5 (§5 location access), H1 (§2.4 default-or-first-active — refined in v3), H3 (§5 physical),
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

### v3 final-review disposition (`…-adversarial-review-v3.md`)

- **Accepted (BLOCKING) — reset preserved rows but no re-entry path:** real contradiction introduced
  by the v2.1 hard-delete→reversal switch. Resolved: §5.1 adds the standalone
  `POST /products/{id}/opening` endpoint (completes "reverse + re-enter"); §7 replaces the coarse
  `has_movements` with the `has_active_opening` / `has_downstream_movements` / `can_enter_opening`
  state; §8/§9/§11 updated to match. (Also enables "add opening to a product created without one" —
  the standard later-opening path.)
- **Accepted (MAJOR) — "strict default" mislabeled:** `getDefaultLocation` falls back to first-active,
  not strict. §2.4 reworded to "company default or first active"; 422 only when **no active
  location** exists. No `LocationContext` change needed; H1's session-current-location concern stays
  resolved (we use `getDefaultLocation`, not `resolveLocationId`).
- **Accepted (MINOR) — stale "+ DB index" in §10:** removed; §10 now cites the advisory lock +
  in-lock active-opening check only.
- **Confirmed coherent (no change):** reversal reset primitive, in-lock enter-once, `is_historical`
  exclusion, `inventory.adjust` gate, concurrency-safe numbering — all verified against worktree code.

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
  NF525 conservation still applies to these rows (research §15) — this delivery satisfies it by
  correcting only via **reversal** (§8), never edit/delete; this follow-up closes the remaining
  raw-mutation gap.
- **Opening Balance Equity (OBE) close-out (system-wide follow-up):** OBE is a *temporary* clearing
  account; a lingering balance is a recognized setup smell (research §15), and best practice is to
  zero it into retained/owner equity once setup completes. This applies identically to the existing
  import opening flow (both credit OBE), so it is a pre-existing, system-wide concern — not
  introduced here. A documented or assisted OBE close-out is a separate enhancement.

## 15. Industry best-practice validation (researched 2026-06-27)

Each design pillar was validated against ERP vendor + compliance sources; all match established
convention (the design is **not** bespoke):

| Pillar | Verdict | Representative sources |
|--------|---------|------------------------|
| Inline opening qty+cost on the product create form | **Standard for SMB retail/POS** (our market). Larger ERPs (Odoo, D365 BC) use a separate inventory-adjustment doc; we still materialize one Opening movement internally, getting both. | Loyverse, Shopify, Zoho Inventory/Books, QuickBooks |
| GL double-entry `Dr Inventory / Cr Opening Balance Equity` | **Standard.** OBE is the conventional opening-balance offset. | QuickBooks, Xero, FitSmallBusiness |
| Correction via **reversal** (keep original), locked after activity | **Standard.** Reversing/contra entry preserves the audit trail; deletion is discouraged. Our "sole-movement" gate is *tighter* than the typical period-close gate (see §8). | Dynamics 365 BC (Reverse Transaction), Sage |
| WAC seeded directly from opening cost, blended on later receipts | **Standard.** Opening inventory is the seed term in moving-average; nothing prior to blend. | Corporate Finance Institute, Unleashed, Zoho |
| Opening balances posted to GL but **excluded from the certified fiscal hash chain** | **Consistent with NF525/FEC.** The certified, hash-chained sequence covers fiscal *sales* events; setup/migration balances are separate. `is_historical` rows must remain immutable (conservation) — satisfied by reversal-only correction. | Microsoft Learn (NF525 cash register, France FEC) |

Refinements adopted from the research: the OBE close-out and `is_historical` immutability
follow-ups (§14), and the explicit rationale for the conservative correction gate (§8).
